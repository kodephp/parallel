<?php

declare(strict_types=1);

namespace Kode\Parallel\Pool;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\EngineInterface;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Util\Sys;

/**
 * 通用工作池
 *
 * 构建在引擎抽象之上，在有无 ext-parallel 的环境中都能运行，
 * 并提供并发上限、批量 map、失败聚合与运行统计。
 *
 * 并发上限会作为线程数传给底层引擎：在 parallel 引擎下即开启对应数量的
 * 解释器线程真正并行（单个 `\parallel\Runtime` 是 FIFO 串行的）。
 *
 * @since 1.6.0
 */
final class WorkerPool
{
    /** 等待空闲槽位的初始轮询间隔（微秒）：小并发下避免每次派发都睡满 */
    private const int MIN_POLL_US = 10;

    /** 等待空闲槽位的最大轮询间隔（微秒）：长任务下避免空转烧 CPU */
    private const int MAX_POLL_US = 500;

    /** 批量映射：batchSize<=0 表示按元素总数与并发度自动推导 */
    private const int AUTO_BATCH = 0;

    /** 自动批大小的上限，避免单批载荷过大撑爆内存 */
    private const int MAX_AUTO_BATCH = 1024;

    /** 自动批大小时，每个线程期望分到的批次数（多于 1 才能做负载均衡） */
    private const int BATCHES_PER_THREAD = 4;

    private readonly EngineInterface $engine;
    private readonly int $concurrency;

    /** @var array<int, FutureInterface> 运行中的任务 */
    private array $pending = [];

    private int $submitted = 0;
    private int $completed = 0;
    private int $failed = 0;
    private bool $closed = false;

    /**
     * @param int $concurrency 并发上限，<=0 时按 CPU 核心数推荐值
     * @param string|null $engine 引擎名，null 自动探测
     * @param string|null $bootstrap 引导文件
     */
    public function __construct(int $concurrency = 0, ?string $engine = null, ?string $bootstrap = null)
    {
        $this->concurrency = $concurrency > 0 ? $concurrency : Sys::recommendedConcurrency();
        // 并发上限同时作为线程数传入，确保 parallel 引擎下并发真实生效（单 Runtime 为 FIFO 串行）
        $this->engine = EngineFactory::create($engine, $bootstrap, $this->concurrency);
    }

    /**
     * 提交任务；达到并发上限时阻塞等待空闲槽位
     *
     * @param Task|callable $task 任务，签名为 fn(array $args): mixed
     * @param array<array-key, mixed> $args
     */
    public function submit(Task|callable $task, array $args = []): FutureInterface
    {
        if ($this->closed) {
            throw new ParallelException('工作池已关闭，无法提交任务');
        }

        $this->waitForSlot();

        $closure = $task instanceof Task
            ? $task->getClosure()
            : \Closure::fromCallable($task);

        $future = $this->engine->submit($closure, $args);
        $this->pending[] = $future;
        $this->submitted++;

        return $future;
    }

    /**
     * 并行映射：对每个元素执行 worker，按输入键序返回结果
     *
     * 元素数量较大时（> 并发度 × {@see BATCHES_PER_THREAD}）自动走「批量合并」快速路径，
     * 把多个元素打包为一次引擎提交，大幅降低 ext-parallel 的每任务序列化开销；
     * 少量元素则保持逐条提交，避免分块本身的开销。两者失败语义一致：任一任务失败即抛出。
     *
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
     * @return array<array-key, mixed>
     * @throws ParallelException 任一任务失败
     */
    public function map(iterable $items, callable $worker): array
    {
        $normalized = $this->normalize($items);

        // 元素足够多时自动走批量合并快速路径；少量元素直接逐条，避免分块开销。
        // 失败语义与逐条一致（任一失败即抛出）。
        if (count($normalized) > $this->concurrency * self::BATCHES_PER_THREAD) {
            return $this->mapBatch($normalized, $worker, self::AUTO_BATCH);
        }

        $futures = $this->dispatch($items, $worker);
        $results = Futures::all($futures);
        $this->collect();

        return $results;
    }

    /**
     * 并行映射（不抛异常版）：返回每个元素的 fulfilled / rejected 状态
     *
     * 元素数量较大时同样自动走「批量合并」快速路径（逐元素容错版），少量元素保持逐条。
     *
     * @param iterable<array-key, mixed> $items
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function mapSettled(iterable $items, callable $worker): array
    {
        $normalized = $this->normalize($items);

        if (count($normalized) > $this->concurrency * self::BATCHES_PER_THREAD) {
            return $this->mapBatchSettled($normalized, $worker, self::AUTO_BATCH);
        }

        $futures = $this->dispatch($items, $worker);
        $results = Futures::settle($futures);
        $this->collect();

        return $results;
    }

    /**
     * 批量并行映射：把每 $batchSize 个元素打包成一次引擎提交，显著降低
     * ext-parallel 的每任务序列化开销（worker 闭包只序列化一次，而非每元素一次）。
     *
     * 适合**高频短任务**（如群发通知、批量轻量计算）：单元素派发受序列化开销
     * 限制时，批量映射通常带来数倍到数十倍的吞吐提升。
     *
     * batchSize 默认自动推导（元素数 / 并发度 / 4，上限 1024），无需调参即可接近最优；
     * 单条数据很大（如 64KB 富文本）时应显式调小，避免单批载荷撑爆 memory_limit：
     * 经验公式 `batchSize × 单条字节数 × 并发度 < memory_limit / 2`。
     *
     * 注意：一个批次内任一元素抛异常会导致整批失败（与 {@see map()} 一致）。
     * 若需逐元素容错，使用 {@see mapBatchSettled()}。
     *
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
     * @param int $batchSize 每批元素数，<=0 自动推导，1 时退化为 {@see map()}
     * @return array<array-key, mixed> 与输入键一一对应
     * @throws ParallelException 任一批次失败
     */
    public function mapBatch(iterable $items, callable $worker, int $batchSize = self::AUTO_BATCH): array
    {
        $normalized = $this->normalize($items);
        $batchSize = $this->resolveBatchSize($batchSize, count($normalized));

        if ($batchSize <= 1) {
            return $this->map($normalized, $worker);
        }

        $batches = $this->chunkItems($normalized, $batchSize);
        $futures = [];

        foreach ($batches as $bKey => $batch) {
            $futures[$bKey] = $this->submit(
                static function (array $args): array {
                    $worker = $args['worker'];
                    $results = [];

                    foreach ($args['items'] as $key => $item) {
                        $results[$key] = $worker($item, $key);
                    }

                    return $results;
                },
                ['worker' => $worker, 'items' => $batch]
            );
        }

        $settled = Futures::all($futures);
        $out = [];

        foreach ($settled as $batchResult) {
            foreach ($batchResult as $key => $value) {
                $out[$key] = $value;
            }
        }

        // 回收已完成的批 future，避免其残留在 pending 中污染 stats/计数并累积内存
        $this->collect();

        return $out;
    }

    /**
     * 批量并行映射（逐元素容错版）：每个元素单独捕获异常，返回其 fulfilled / rejected 状态。
     *
     * 适合**群发通知**这类“部分失败可重试、其余照常”的场景：单个收件人失败不会
     * 连累同批其他收件人，最终按元素返回每个收件人的投递结果。
     *
     * 失败元素的 `reason` 恒为 {@see ParallelException}：线程内的原始异常对象无法
     * 跨线程序列化（ext-parallel 会退化为 `parallel\Runtime\Object\Unavailable`），
     * 因此这里在工作线程内把异常降级为纯标量描述，回到主线程后重建为异常对象，
     * 原始类名 / 文件 / 行号 / 堆栈可通过 `$reason->getContext()` 获取。
     *
     * @param iterable<array-key, mixed> $items
     * @param int $batchSize 每批元素数，<=0 自动推导，1 时退化为 {@see mapSettled()}
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function mapBatchSettled(iterable $items, callable $worker, int $batchSize = self::AUTO_BATCH): array
    {
        $normalized = $this->normalize($items);
        $batchSize = $this->resolveBatchSize($batchSize, count($normalized));

        if ($batchSize <= 1) {
            return $this->mapSettled($normalized, $worker);
        }

        $batches = $this->chunkItems($normalized, $batchSize);
        $futures = [];
        $batchKeys = [];

        foreach ($batches as $bKey => $batch) {
            $batchKeys[$bKey] = array_keys($batch);
            $futures[$bKey] = $this->submit(
                // 注意：闭包在工作线程内执行，无自动加载器，只能使用字面量与内置类
                static function (array $args): array {
                    $worker = $args['worker'];
                    $results = [];

                    foreach ($args['items'] as $key => $item) {
                        try {
                            $results[$key] = [
                                'status' => 'fulfilled',
                                'value' => $worker($item, $key),
                            ];
                        } catch (\Throwable $e) {
                            // 异常对象含不可序列化引用，降级为纯标量在主线程重建
                            $results[$key] = [
                                'status' => 'rejected',
                                'error' => [
                                    'class' => $e::class,
                                    'message' => $e->getMessage(),
                                    'code' => (int) $e->getCode(),
                                    'file' => $e->getFile(),
                                    'line' => $e->getLine(),
                                    'trace' => $e->getTraceAsString(),
                                ],
                            ];
                        }
                    }

                    return $results;
                },
                ['worker' => $worker, 'items' => $batch]
            );
        }

        $settled = Futures::settle($futures);
        $out = [];

        foreach ($settled as $bKey => $batchResult) {
            if ($batchResult['status'] === Futures::STATUS_REJECTED) {
                // 整批意外失败（如 worker 不可序列化），逐个标记该批元素失败
                foreach ($batchKeys[$bKey] as $key) {
                    $out[$key] = ['status' => Futures::STATUS_REJECTED, 'reason' => $batchResult['reason']];
                }

                continue;
            }

            foreach ($batchResult['value'] as $key => $itemRes) {
                $out[$key] = isset($itemRes['error'])
                    ? [
                        'status' => Futures::STATUS_REJECTED,
                        'reason' => self::restoreRemoteError($itemRes['error']),
                    ]
                    : [
                        'status' => Futures::STATUS_FULFILLED,
                        'value' => $itemRes['value'],
                    ];
            }
        }

        return $out;
    }

    /**
     * 把工作线程回传的异常描述重建为主线程可用的异常对象
     *
     * @param array{class: string, message: string, code: int, file: string, line: int, trace: string} $wire
     */
    private static function restoreRemoteError(array $wire): ParallelException
    {
        return new ParallelException(
            '任务执行失败: ' . $wire['message'],
            $wire['code'],
            null,
            [
                'class' => $wire['class'],
                'file' => $wire['file'],
                'line' => $wire['line'],
                'trace' => $wire['trace'],
            ]
        );
    }

    /**
     * 等待全部在途任务结束
     *
     * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
     * @return array<int, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function wait(int $timeoutMs = 0): array
    {
        $results = Futures::settle($this->pending, $timeoutMs);
        $this->collect();

        return $results;
    }

    /**
     * 运行统计
     *
     * @return array{engine: string, concurrent: bool, concurrency: int, submitted: int, completed: int, failed: int, pending: int}
     */
    public function stats(): array
    {
        return [
            'engine' => $this->engine->name(),
            'concurrent' => $this->engine->isConcurrent(),
            'concurrency' => $this->concurrency,
            'submitted' => $this->submitted,
            'completed' => $this->completed,
            'failed' => $this->failed,
            'pending' => count($this->pending),
        ];
    }

    /**
     * 引擎名
     */
    public function getEngineName(): string
    {
        return $this->engine->name();
    }

    /**
     * 并发上限
     */
    public function getConcurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * 在途任务数
     */
    public function getPendingCount(): int
    {
        $this->collect();

        return count($this->pending);
    }

    /**
     * 关闭工作池，取消所有未完成任务
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        Futures::cancelAll($this->pending);
        $this->pending = [];
        $this->engine->close();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @param iterable<array-key, mixed> $items
     * @return array<array-key, FutureInterface>
     */
    private function dispatch(iterable $items, callable $worker): array
    {
        $futures = [];

        foreach ($items as $key => $item) {
            $futures[$key] = $this->submit(
                static fn(array $args): mixed => ($args['worker'])($args['item'], $args['key']),
                ['worker' => $worker, 'item' => $item, 'key' => $key]
            );
        }

        return $futures;
    }

    /**
     * 阻塞直到有空闲槽位
     *
     * 采用指数退避轮询：短任务（微秒级）几乎立刻拿到槽位，不必睡满一个长间隔；
     * 长任务则很快退避到最大间隔，避免空转烧 CPU。
     */
    private function waitForSlot(): void
    {
        $sleep = self::MIN_POLL_US;

        while (true) {
            $this->collect();

            if (count($this->pending) < $this->concurrency) {
                return;
            }

            usleep($sleep);
            $sleep = min($sleep * 2, self::MAX_POLL_US);
        }
    }

    /**
     * 回收已结束任务并累加统计
     */
    private function collect(): void
    {
        foreach ($this->pending as $index => $future) {
            if (!$future->done()) {
                continue;
            }

            try {
                $future->get();
                $this->completed++;
            } catch (\Throwable) {
                $this->failed++;
            }

            unset($this->pending[$index]);
        }
    }

    /**
     * @param iterable<array-key, mixed> $items
     * @return array<array-key, mixed>
     */
    private function normalize(iterable $items): array
    {
        return is_array($items) ? $items : iterator_to_array($items);
    }

    /**
     * 推导批大小：<=0 时按元素总数与并发度自动计算，使每个线程分到若干批以便负载均衡
     */
    private function resolveBatchSize(int $batchSize, int $count): int
    {
        if ($batchSize > 0) {
            return $batchSize;
        }

        if ($count <= 0) {
            return 1;
        }

        $slices = max(1, $this->concurrency * self::BATCHES_PER_THREAD);

        return max(1, min(self::MAX_AUTO_BATCH, (int) ceil($count / $slices)));
    }

    /**
     * 把元素集合切分为保留原始键的批次
     *
     * @param array<array-key, mixed> $items
     * @return array<int, array<array-key, mixed>>
     */
    private function chunkItems(array $items, int $batchSize): array
    {
        if ($items === []) {
            return [];
        }

        return array_chunk($items, max(1, $batchSize), true);
    }

    public function __destruct()
    {
        $this->close();
    }
}
