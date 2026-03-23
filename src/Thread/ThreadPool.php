<?php

declare(strict_types=1);

namespace Kode\Parallel\Thread;

use Kode\Parallel\Exception\ParallelException;

final class ThreadPool
{
    private int $minSize;
    private int $maxSize;
    private array $workers = [];
    private array $idleWorkers = [];
    private array $taskQueue = [];
    private int $activeCount = 0;
    private bool $running = false;
    private int $queueMaxSize;

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
        $this->queueMaxSize = $queueMaxSize;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        for ($i = 0; $i < $this->minSize; $i++) {
            $this->spawnWorker();
        }
    }

    public function submit(callable $task, array $args = []): bool
    {
        if (!$this->running) {
            $this->start();
        }

        if (count($this->taskQueue) >= $this->queueMaxSize) {
            return false;
        }

        $this->taskQueue[] = [
            'task' => $task,
            'args' => $args,
        ];

        $this->dispatchTask();

        return true;
    }

    public function submitBlocking(callable $task, array $args = [])
    {
        if (!$this->running) {
            $this->start();
        }

        $future = new \Kode\Parallel\Future\Future(null);
        $result = null;
        $exception = null;

        $wrapper = function($args) use ($task, &$result, &$exception, $future) {
            try {
                $result = $task($args);
                return $result;
            } catch (\Throwable $e) {
                $exception = $e;
                throw $e;
            }
        };

        $this->taskQueue[] = [
            'task' => $wrapper,
            'args' => $args,
            'future' => $future,
        ];

        $this->dispatchTask();

        return $future;
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    public function getIdleWorkerCount(): int
    {
        return count($this->idleWorkers);
    }

    public function getQueueSize(): int
    {
        return count($this->taskQueue);
    }

    public function getActiveCount(): int
    {
        return $this->activeCount;
    }

    public function shutdown(): void
    {
        $this->running = false;

        foreach ($this->workers as $worker) {
            if ($worker instanceof \parallel\Runtime) {
                $worker->close();
            }
        }

        $this->workers = [];
        $this->idleWorkers = [];
        $this->taskQueue = [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function scaleUp(int $count = 1): void
    {
        $newCount = min(count($this->workers) + $count, $this->maxSize);

        for ($i = count($this->workers); $i < $newCount; $i++) {
            $this->spawnWorker();
        }
    }

    public function scaleDown(int $count = 1): void
    {
        $targetSize = max(count($this->workers) - $count, $this->minSize);

        while (count($this->workers) > $targetSize) {
            $workerId = array_key_last($this->workers);
            $worker = $this->workers[$workerId];

            if ($worker instanceof \parallel\Runtime) {
                $worker->close();
            }

            unset($this->workers[$workerId]);
        }
    }

    private function spawnWorker(): void
    {
        if (count($this->workers) >= $this->maxSize) {
            return;
        }

        try {
            $runtime = new \parallel\Runtime();
            $workerId = count($this->workers);
            $this->workers[$workerId] = $runtime;
            $this->idleWorkers[$workerId] = true;

            $this->runWorkerLoop($workerId);
        } catch (\Throwable $e) {
            throw new ParallelException('创建工作线程失败: ' . $e->getMessage(), 0, $e);
        }
    }

    private function runWorkerLoop(int $workerId): void
    {
        $pool = $this;

        $worker = function() use ($pool, $workerId) {
            while ($pool->running) {
                if (empty($pool->taskQueue)) {
                    usleep(1000);
                    continue;
                }

                $task = array_shift($pool->taskQueue);

                if ($task === null) {
                    continue;
                }

                $pool->idleWorkers[$workerId] = false;
                $pool->activeCount++;

                try {
                    $closure = \Closure::fromCallable($task['task']);
                    $closure($task['args']);
                } catch (\Throwable $e) {
                    // 记录错误但不中断工作线程
                    error_log('ThreadPool worker error: ' . $e->getMessage());
                }

                $pool->activeCount--;
                $pool->idleWorkers[$workerId] = true;
            }
        };

        $runtime = $this->workers[$workerId];

        try {
            $runtime->run($worker);
        } catch (\parallel\Runtime\Error $e) {
            unset($this->workers[$workerId], $this->idleWorkers[$workerId]);
        }
    }

    private function dispatchTask(): void
    {
        if (empty($this->idleWorkers) && count($this->workers) < $this->maxSize) {
            $this->spawnWorker();
        }
    }

    public function waitAll(): void
    {
        while (!empty($this->taskQueue) || $this->activeCount > 0) {
            usleep(1000);
        }
    }
}
