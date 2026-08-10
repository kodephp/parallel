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
    /** 等待空闲槽位的轮询间隔（微秒） */
    private const int POLL_INTERVAL_US = 500;

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
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
     * @return array<array-key, mixed>
     * @throws ParallelException 任一任务失败
     */
    public function map(iterable $items, callable $worker): array
    {
        $futures = $this->dispatch($items, $worker);
        $results = Futures::all($futures);
        $this->collect();

        return $results;
    }

    /**
     * 并行映射（不抛异常版）：返回每个元素的 fulfilled / rejected 状态
     *
     * @param iterable<array-key, mixed> $items
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function mapSettled(iterable $items, callable $worker): array
    {
        $futures = $this->dispatch($items, $worker);
        $results = Futures::settle($futures);
        $this->collect();

        return $results;
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
     */
    private function waitForSlot(): void
    {
        while (true) {
            $this->collect();

            if (count($this->pending) < $this->concurrency) {
                return;
            }

            usleep(self::POLL_INTERVAL_US);
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

    public function __destruct()
    {
        $this->close();
    }
}
