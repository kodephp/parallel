<?php

declare(strict_types=1);

namespace Kode\Parallel\Thread;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Future;

final class ThreadPool
{
    private int $minSize;
    private int $maxSize;
    private array $runtimes = [];
    private array $futures = [];
    private bool $running = false;

    public function __construct(int $minSize = 4, int $maxSize = 16, int $queueMaxSize = 100)
    {
        if ($minSize < 1) {
            throw new ParallelException('最小工作线程数必须 >= 1');
        }
        if ($maxSize < $minSize) {
            throw new ParallelException('最大工作线程数必须 >= 最小工作线程数');
        }
        $this->minSize = $minSize;
        $this->maxSize = $maxSize;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }
        $this->running = true;

        for ($i = 0; $i < $this->minSize; $i++) {
            $this->createWorker();
        }
    }

    public function submit(callable $task, array $args = []): ?Future
    {
        if (!$this->running) {
            $this->start();
        }

        $workerIndex = $this->selectWorker();
        if ($workerIndex === null) {
            return null;
        }

        try {
            $future = $this->runtimes[$workerIndex]['runtime']->run($task, [$args]);
            $wrapper = new Future($future);
            $this->futures[] = $wrapper;
            return $wrapper;
        } catch (\Throwable $e) {
            error_log('ThreadPool submit error: ' . $e->getMessage());
            return null;
        }
    }

    public function getWorkerCount(): int
    {
        return count($this->runtimes);
    }

    public function getActiveFutureCount(): int
    {
        return count($this->futures);
    }

    public function shutdown(): void
    {
        $this->running = false;

        foreach ($this->runtimes as $worker) {
            try {
                $worker['runtime']->close();
            } catch (\Throwable $e) {
            }
        }

        $this->runtimes = [];
        $this->futures = [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function scaleUp(int $count = 1): void
    {
        $target = min(count($this->runtimes) + $count, $this->maxSize);
        for ($i = count($this->runtimes); $i < $target; $i++) {
            $this->createWorker();
        }
    }

    public function scaleDown(int $count = 1): void
    {
        $target = max(count($this->runtimes) - $count, $this->minSize);
        while (count($this->runtimes) > $target) {
            $id = array_key_last($this->runtimes);
            try {
                $this->runtimes[$id]['runtime']->close();
            } catch (\Throwable $e) {
            }
            unset($this->runtimes[$id]);
        }
    }

    public function waitAll(): void
    {
        foreach ($this->futures as $future) {
            try {
                $future->get();
            } catch (\Throwable $e) {
            }
        }
        $this->futures = [];
    }

    private function createWorker(): void
    {
        if (count($this->runtimes) >= $this->maxSize) {
            return;
        }

        try {
            $runtime = new \parallel\Runtime();
            $id = count($this->runtimes);
            $this->runtimes[$id] = [
                'runtime' => $runtime,
                'tasks' => 0,
            ];
        } catch (\Throwable $e) {
            throw new ParallelException('创建工作线程失败: ' . $e->getMessage(), 0, $e);
        }
    }

    private function selectWorker(): ?int
    {
        if (empty($this->runtimes)) {
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

        if ($selectedId !== null) {
            $this->runtimes[$selectedId]['tasks']++;
        }

        return $selectedId;
    }
}
