<?php

declare(strict_types=1);

namespace Kode\Parallel\Pool;

use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Util\Sys;

/**
 * 多线程池（非阻塞派发 + 常驻 worker 线程）
 *
 * 与 {@see WorkerPool} 的区别在于**派发语义**：
 *
 * - {@see WorkerPool}：槽位满时 {@see WorkerPool::submit()} 会**阻塞调用方**直到有空位，
 *   适合「生产者-消费者速度相当」的常规并行映射。
 * - {@see ThreadPool}：{@see self::submit()} **永不阻塞**——任务先进入进程内队列立即返回
 *   Future，由 N 个常驻 `\parallel\Runtime` 工作线程在空闲时自行拉取执行。适合
 *   **生产者远快于消费者 / 需要预排成千上万个任务 / 在协程或事件循环里非阻塞提交** 的场景。
 *
 * 线程池的 N 个工作线程在构造时一次性创建并长期复用（线程创建成本被摊薄），每个线程
 * 同一时刻只执行一个任务（FIFO）。由于派发与回收都在调用方线程内惰性发生（与
 * ext-parallel 的 Channel 跨线程队列相比，无 rendezvous 死锁风险），本实现稳健且
 * 不依赖无缓冲通道。
 *
 * **环境要求**：必须运行在 ZTS + ext-parallel 下，否则构造即抛出清晰异常（同步引擎无真线程）。
 * 若任务闭包需要使用业务类，请在构造时传入 composer 自动加载器作为 `$bootstrap`，
 * 与 {@see WorkerPool} / {@see \Kode\Parallel\Runtime\Runtime} 的约定一致。
 *
 * @since 1.18.0
 */
final class ThreadPool
{
    /** 等待空闲 worker 的最小轮询间隔（微秒） */
    private const int MIN_POLL_US = 10;

    /** 等待空闲 worker 的最大轮询间隔（微秒） */
    private const int MAX_POLL_US = 500;

    private readonly int $size;

    private readonly ?string $bootstrap;

    /** @var array<int, \parallel\Runtime> 常驻工作线程，键为 worker 下标 */
    private array $workers = [];

    /** @var array<int, \parallel\Future|null> 每个 worker 的在途 future，null 表示空闲 */
    private array $busy = [];

    /** @var array<int, int> worker 下标 => 当前执行的任务 id */
    private array $runningTask = [];

    /** @var array<int, bool> 已失效（线程被杀死且无法重建）的 worker 下标 */
    private array $dead = [];

    /** @var list<array{id: int, closure: \Closure, args: array}> 待派发任务队列 */
    private array $queue = [];

    /** @var array<int, array{ok: bool, value?: mixed, error?: \Throwable}> 已解析结果，键为任务 id */
    private array $results = [];

    /** @var array<int, bool> 已取消标记，键为任务 id */
    private array $cancelled = [];

    private int $nextId = 1;

    private int $submitted = 0;

    private int $completed = 0;

    private int $failed = 0;

    private bool $closed = false;

    /**
     * @param int $size 工作线程数（并发上限），<=0 按 CPU 核心数推荐
     * @param string|null $bootstrap 引导文件（每个工作线程启动时加载，通常为 vendor/autoload.php）
     * @throws ParallelException 环境不支持（无 ext-parallel / 非 ZTS）或线程初始化失败
     */
    public function __construct(int $size = 0, ?string $bootstrap = null)
    {
        if (!ParallelEngine::supported()) {
            throw new ParallelException(
                'ThreadPool 需要 ext-parallel 真线程支持（ZTS 构建的 PHP），当前环境不可用'
            );
        }

        $this->size = $size > 0 ? $size : Sys::recommendedConcurrency();
        $this->bootstrap = $bootstrap;

        for ($i = 0; $i < $this->size; $i++) {
            $this->workers[$i] = $this->createRuntime();
            $this->busy[$i] = null;
        }
    }

    /**
     * 非阻塞提交任务，立即返回 Future
     *
     * 任务进入进程内队列后立刻返回；空闲工作线程会自行拉取执行。即使队列中积压了
     * 成千上万个任务，本方法也不会阻塞或预占线程。
     *
     * @param Task|callable $task 任务，签名为 fn(array $args): mixed
     * @param array<array-key, mixed> $args
     */
    public function submit(Task|callable $task, array $args = []): ThreadPoolFuture
    {
        if ($this->closed) {
            throw new ParallelException('线程池已关闭，无法提交任务');
        }

        $closure = $task instanceof Task
            ? $task->getClosure()
            : \Closure::fromCallable($task);

        $id = $this->nextId++;
        $this->queue[] = ['id' => $id, 'closure' => $closure, 'args' => $args];
        $this->submitted++;

        // 立即把任务派发给当前空闲的 worker（非阻塞：仅填充空闲槽位）
        $this->dispatch();

        return new ThreadPoolFuture($this, $id);
    }

    /**
     * 并行映射：对每个元素执行 worker，按输入键序返回结果
     *
     * 与 {@see WorkerPool::map()} 语义一致，但底层是非阻塞队列派发——
     * 不会因槽位占满而阻塞调用方。
     *
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
     * @return array<array-key, mixed>
     * @throws ParallelException 任一任务失败
     */
    public function map(iterable $items, callable $worker): array
    {
        $normalized = is_array($items) ? $items : iterator_to_array($items);
        $futures = [];

        foreach ($normalized as $key => $item) {
            $futures[$key] = $this->submit(
                static fn(array $a): mixed => $worker($a['item'], $a['key']),
                ['item' => $item, 'key' => $key]
            )->withKey((string) $key);
        }

        return Futures::all($futures);
    }

    /**
     * 并行映射（容错版）：返回每个元素的 fulfilled / rejected 状态
     *
     * @param iterable<array-key, mixed> $items
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function mapSettled(iterable $items, callable $worker): array
    {
        $normalized = is_array($items) ? $items : iterator_to_array($items);
        $futures = [];

        foreach ($normalized as $key => $item) {
            $futures[$key] = $this->submit(
                static fn(array $a): mixed => $worker($a['item'], $a['key']),
                ['item' => $item, 'key' => $key]
            )->withKey((string) $key);
        }

        return Futures::settle($futures);
    }

    /**
     * 等待全部已提交任务结束（含队列中尚未派发的）
     *
     * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
     * @return array<int, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function wait(int $timeoutMs = 0): array
    {
        $deadline = $timeoutMs > 0 ? hrtime(true) + ($timeoutMs * 1_000_000) : null;
        $sleep = self::MIN_POLL_US;

        while ($this->queue !== [] || $this->hasBusy()) {
            $this->drain();
            $this->dispatch();

            if ($this->queue === [] && !$this->hasBusy()) {
                break;
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                break;
            }

            usleep($sleep);
            $sleep = min($sleep * 2, self::MAX_POLL_US);
        }

        $out = [];

        foreach ($this->results as $id => $r) {
            $out[$id] = $r['ok']
                ? ['status' => Futures::STATUS_FULFILLED, 'value' => $r['value']]
                : ['status' => Futures::STATUS_REJECTED, 'reason' => $r['error']];
        }

        return $out;
    }

    /**
     * 运行统计
     *
     * @return array{engine: string, concurrent: bool, size: int, queue: int, busy: int, submitted: int, completed: int, failed: int}
     */
    public function stats(): array
    {
        return [
            'engine' => ParallelEngine::NAME,
            'concurrent' => true,
            'size' => $this->size,
            'queue' => count($this->queue),
            'busy' => $this->countBusy(),
            'submitted' => $this->submitted,
            'completed' => $this->completed,
            'failed' => $this->failed,
        ];
    }

    /**
     * 工作线程数
     */
    public function getSize(): int
    {
        return $this->size;
    }

    /**
     * 队列中尚未派发的任务数
     */
    public function getQueueLength(): int
    {
        return count($this->queue);
    }

    /**
     * 关闭线程池，回收所有工作线程
     *
     * 调用前若需要任务结果，请先调用 {@see self::wait()}。关闭会放弃队列中尚未派发的任务
     * 并终止在途任务（线程被回收）。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->workers as $runtime) {
            try {
                $runtime->close();
            } catch (\Throwable) {
                // 忽略关闭过程中的异常
            }
        }

        $this->workers = [];
        $this->busy = [];
        $this->runningTask = [];
        $this->dead = [];
        $this->queue = [];
        $this->results = [];
        $this->cancelled = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * 任务是否已解析（成功 / 失败 / 取消）
     *
     * @internal
     */
    public function isResolved(int $id): bool
    {
        return isset($this->results[$id]);
    }

    /**
     * 任务是否已取消
     *
     * @internal
     */
    public function isCancelled(int $id): bool
    {
        if ($this->cancelled[$id] ?? false) {
            return true;
        }

        return isset($this->results[$id]) && !$this->results[$id]['ok']
            && str_contains($this->results[$id]['error']?->getMessage() ?? '', '取消');

    }

    /**
     * 取得已解析结果：成功返回值，失败/取消抛出异常
     *
     * @internal
     */
    public function resultOf(int $id): mixed
    {
        $r = $this->results[$id];

        if ($r['ok']) {
            return $r['value'];
        }

        throw $r['error'];
    }

    /**
     * 阻塞等待单个任务解析（成功返回值，失败/取消抛异常）
     *
     * @internal
     * @throws ParallelException 任务失败、已取消或等待超时
     */
    public function await(int $id, int $timeoutMs = 0): mixed
    {
        $deadline = $timeoutMs > 0 ? hrtime(true) + ($timeoutMs * 1_000_000) : null;
        $sleep = self::MIN_POLL_US;

        while (!$this->isResolved($id)) {
            $this->drain();

            if ($this->isResolved($id)) {
                break;
            }

            $this->dispatch();

            if ($this->isResolved($id)) {
                break;
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                throw new ParallelException(
                    'ThreadPool 任务等待超时',
                    0,
                    null,
                    ['id' => $id, 'timeout_ms' => $timeoutMs]
                );
            }

            usleep($sleep);
            $sleep = min($sleep * 2, self::MAX_POLL_US);
        }

        return $this->resultOf($id);
    }

    /**
     * 取消任务：队列中未派发的直接移除；已派发的标记取消，完成后记为取消错误
     *
     * @internal
     */
    public function cancel(int $id): bool
    {
        if ($this->isResolved($id)) {
            return false;
        }

        foreach ($this->queue as $k => $task) {
            if ($task['id'] === $id) {
                unset($this->queue[$k]);
                $this->queue = array_values($this->queue);
                $this->cancelled[$id] = true;
                $this->results[$id] = [
                    'ok' => false,
                    'error' => new ParallelException('任务已被取消', 0, null, ['id' => $id]),
                ];

                return true;
            }
        }

        if (in_array($id, $this->runningTask, true)) {
            $this->cancelled[$id] = true;

            return true;
        }

        return false;
    }

    /**
     * 把队列中待派发任务分配给空闲 worker（非阻塞）
     */
    private function dispatch(): void
    {
        foreach ($this->busy as $idx => $future) {
            if ($future !== null || ($this->dead[$idx] ?? false)) {
                continue;
            }

            if ($this->queue === []) {
                break;
            }

            $task = array_shift($this->queue);

            try {
                $this->busy[$idx] = $this->workers[$idx]->run($task['closure'], [$task['args']]);
                $this->runningTask[$idx] = $task['id'];
            } catch (\Throwable $e) {
                // 派发失败（如工作线程被杀死）：记录错误并自愈式重建线程
                $this->results[$task['id']] = [
                    'ok' => false,
                    'error' => new ParallelException(
                        '任务派发失败: ' . $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    ),
                ];
                $this->failed++;

                $this->heal($idx);
            }
        }
    }

    /**
     * 推进线程池：回收已完成任务并派发队列中待处理任务
     *
     * 由于本线程池的派发/回收完全由调用方线程惰性驱动（不使用跨线程通道），
     * 任何对任务状态的查询（{@see ThreadPoolFuture::done()}）都必须触发本方法，
     * 否则 {@see Futures::all()}/\span{settle()} 这类只轮询 done() 的组合器会永远等待。
     *
     * @internal
     */
    public function pump(): void
    {
        if ($this->closed) {
            return;
        }

        $this->drain();
        $this->dispatch();
    }

    /**
     * 回收已完成的任务并把结果路由回对应 Future
     */
    private function drain(): void
    {
        foreach ($this->busy as $idx => $future) {
            if ($future === null) {
                continue;
            }

            if (!$future->done()) {
                continue;
            }

            $taskId = $this->runningTask[$idx];

            if ($this->cancelled[$taskId] ?? false) {
                $this->results[$taskId] = [
                    'ok' => false,
                    'error' => new ParallelException('任务已被取消', 0, null, ['id' => $taskId]),
                ];
            } else {
                try {
                    $value = $future->value();
                    $this->results[$taskId] = ['ok' => true, 'value' => $value];
                    $this->completed++;
                } catch (\Throwable $e) {
                    $this->results[$taskId] = [
                        'ok' => false,
                        'error' => $e instanceof ParallelException
                            ? $e
                            : new ParallelException(
                                '任务执行失败: ' . $e->getMessage(),
                                (int) $e->getCode(),
                                $e,
                                ['exception' => $e::class]
                            ),
                    ];
                    $this->failed++;
                }
            }

            $this->busy[$idx] = null;
            unset($this->runningTask[$idx]);
        }
    }

    /**
     * 自愈：重建失效的工作线程；重建失败则标记该下标为 dead
     */
    private function heal(int $idx): void
    {
        try {
            $this->workers[$idx] = $this->createRuntime();
            $this->busy[$idx] = null;
            unset($this->dead[$idx]);
        } catch (\Throwable) {
            $this->dead[$idx] = true;
            $this->busy[$idx] = null;
        }
    }

    private function hasBusy(): bool
    {
        foreach ($this->busy as $future) {
            if ($future !== null) {
                return true;
            }
        }

        return false;
    }

    private function countBusy(): int
    {
        $n = 0;

        foreach ($this->busy as $future) {
            if ($future !== null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @throws ParallelException 线程初始化失败
     */
    private function createRuntime(): \parallel\Runtime
    {
        try {
            return $this->bootstrap !== null
                ? new \parallel\Runtime($this->bootstrap)
                : new \parallel\Runtime();
        } catch (\Throwable $e) {
            throw new ParallelException(
                'ThreadPool 工作线程初始化失败: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
                ['bootstrap' => $this->bootstrap]
            );
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
