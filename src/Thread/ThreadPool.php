<?php

declare(strict_types=1);

namespace Kode\Parallel\Thread;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Future;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Util\Installation;
use Kode\Parallel\Util\Sys;

/**
 * ext-parallel 线程池
 *
 * 维护一组常驻 \parallel\Runtime 线程，按"最少在途任务"策略分发。
 * 需要 ext-parallel 扩展；若环境不确定，请改用
 * {@see \Kode\Parallel\Pool\WorkerPool}（自动降级为多进程/同步）。
 */
final class ThreadPool
{
    private readonly int $minSize;
    private readonly int $maxSize;
    private readonly int $queueMaxSize;

    /** @var array<int, array{runtime: \parallel\Runtime, tasks: int}> */
    private array $runtimes = [];

    /** @var array<int, array{future: FutureInterface, worker: int}> */
    private array $futures = [];

    private int $workerSequence = 0;
    private int $completed = 0;
    private int $failed = 0;
    private bool $running = false;

    public function __construct(int $minSize = 4, int $maxSize = 16, int $queueMaxSize = 100)
    {
        if ($minSize < 1) {
            throw new ParallelException('最小工作线程数必须 >= 1');
        }

        if ($maxSize < $minSize) {
            throw new ParallelException('最大工作线程数必须 >= 最小工作线程数');
        }

        if ($queueMaxSize < 1) {
            throw new ParallelException('队列容量必须 >= 1');
        }

        $this->minSize = $minSize;
        $this->maxSize = $maxSize;
        $this->queueMaxSize = $queueMaxSize;
    }

    /**
     * 按 CPU 核心数创建线程池
     */
    public static function auto(): self
    {
        $cpu = Sys::recommendedConcurrency();

        return new self($cpu, $cpu * 2);
    }

    /**
     * 启动线程池
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        Installation::assertExtension();
        $this->running = true;

        for ($i = 0; $i < $this->minSize; $i++) {
            $this->createWorker();
        }
    }

    /**
     * 提交任务
     *
     * @param array<array-key, mixed> $args
     * @throws ParallelException 队列已满或线程创建失败
     */
    public function submit(callable $task, array $args = []): FutureInterface
    {
        if (!$this->running) {
            $this->start();
        }

        $this->collect();

        if (count($this->futures) >= $this->queueMaxSize) {
            throw new ParallelException(
                '线程池队列已满',
                0,
                null,
                ['queue_max_size' => $this->queueMaxSize]
            );
        }

        $workerId = $this->selectWorker();

        if ($workerId === null) {
            throw new ParallelException('没有可用的工作线程');
        }

        try {
            $closure = \Closure::fromCallable($task);
            $future = new Future($this->runtimes[$workerId]['runtime']->run($closure, [$args]));
        } catch (\Throwable $e) {
            $this->runtimes[$workerId]['tasks']--;

            throw new ParallelException('任务提交失败: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        $this->futures[] = ['future' => $future, 'worker' => $workerId];

        return $future;
    }

    /**
     * 批量提交并按输入键序返回结果
     *
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
     * @return array<array-key, mixed>
     */
    public function map(iterable $items, callable $worker): array
    {
        $futures = [];

        foreach ($items as $key => $item) {
            $futures[$key] = $this->submit(
                static fn(array $args): mixed => ($args['worker'])($args['item'], $args['key']),
                ['worker' => $worker, 'item' => $item, 'key' => $key]
            );
        }

        $results = Futures::all($futures);
        $this->collect();

        return $results;
    }

    /**
     * 当前工作线程数
     */
    public function getWorkerCount(): int
    {
        return count($this->runtimes);
    }

    /**
     * 在途任务数
     */
    public function getActiveFutureCount(): int
    {
        $this->collect();

        return count($this->futures);
    }

    /**
     * 运行统计
     *
     * @return array{workers: int, min_size: int, max_size: int, queue_max_size: int, pending: int, completed: int, failed: int, running: bool}
     */
    public function stats(): array
    {
        $this->collect();

        return [
            'workers' => count($this->runtimes),
            'min_size' => $this->minSize,
            'max_size' => $this->maxSize,
            'queue_max_size' => $this->queueMaxSize,
            'pending' => count($this->futures),
            'completed' => $this->completed,
            'failed' => $this->failed,
            'running' => $this->running,
        ];
    }

    /**
     * 关闭线程池
     */
    public function shutdown(): void
    {
        $this->running = false;

        foreach ($this->futures as $entry) {
            if (!$entry['future']->done()) {
                $entry['future']->cancel();
            }
        }

        foreach ($this->runtimes as $worker) {
            try {
                $worker['runtime']->close();
            } catch (\Throwable) {
                // 线程可能已自行退出，忽略
            }
        }

        $this->runtimes = [];
        $this->futures = [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * 扩容
     */
    public function scaleUp(int $count = 1): int
    {
        $created = 0;
        $target = min(count($this->runtimes) + max(0, $count), $this->maxSize);

        while (count($this->runtimes) < $target) {
            $this->createWorker();
            $created++;
        }

        return $created;
    }

    /**
     * 缩容（不会低于 minSize，且跳过仍有在途任务的线程）
     */
    public function scaleDown(int $count = 1): int
    {
        $this->collect();

        $removed = 0;
        $target = max(count($this->runtimes) - max(0, $count), $this->minSize);

        foreach ($this->runtimes as $id => $worker) {
            if (count($this->runtimes) <= $target) {
                break;
            }

            if ($worker['tasks'] > 0) {
                continue;
            }

            try {
                $worker['runtime']->close();
            } catch (\Throwable) {
                // 忽略关闭异常
            }

            unset($this->runtimes[$id]);
            $removed++;
        }

        return $removed;
    }

    /**
     * 等待全部在途任务结束
     *
     * @return array<int, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public function waitAll(int $timeoutMs = 0): array
    {
        $futures = array_map(static fn(array $entry): FutureInterface => $entry['future'], $this->futures);
        $results = Futures::settle($futures, $timeoutMs);
        $this->collect();

        return $results;
    }

    private function createWorker(): void
    {
        if (count($this->runtimes) >= $this->maxSize) {
            return;
        }

        try {
            $this->runtimes[$this->workerSequence++] = [
                'runtime' => new \parallel\Runtime(),
                'tasks' => 0,
            ];
        } catch (\Throwable $e) {
            throw new ParallelException('创建工作线程失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 选择在途任务最少的线程；全忙且未达上限时自动扩容
     */
    private function selectWorker(): ?int
    {
        if ($this->runtimes === []) {
            return null;
        }

        $minTasks = PHP_INT_MAX;
        $selectedId = null;

        foreach ($this->runtimes as $id => $worker) {
            if ($worker['tasks'] < $minTasks) {
                $minTasks = $worker['tasks'];
                $selectedId = $id;
            }
        }

        if ($minTasks > 0 && count($this->runtimes) < $this->maxSize) {
            $this->createWorker();
            $selectedId = array_key_last($this->runtimes);
        }

        if ($selectedId !== null) {
            $this->runtimes[$selectedId]['tasks']++;
        }

        return $selectedId;
    }

    /**
     * 回收已完成任务，释放线程占用计数
     */
    private function collect(): void
    {
        foreach ($this->futures as $index => $entry) {
            if (!$entry['future']->done()) {
                continue;
            }

            try {
                $entry['future']->get();
                $this->completed++;
            } catch (\Throwable) {
                $this->failed++;
            }

            if (isset($this->runtimes[$entry['worker']])) {
                $this->runtimes[$entry['worker']]['tasks'] = max(
                    0,
                    $this->runtimes[$entry['worker']]['tasks'] - 1
                );
            }

            unset($this->futures[$index]);
        }
    }

    public function __destruct()
    {
        $this->shutdown();
    }
}
