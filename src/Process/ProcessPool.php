<?php

declare(strict_types=1);

namespace Kode\Parallel\Process;

use Kode\Parallel\Exception\ParallelException;

final class ProcessPool
{
    private int $workerNum;
    private array $workers = [];
    private array $taskQueue = [];
    private bool $running = false;
    private int $maxQueueSize;
    private string $bootstrap;

    public function __construct(int $workerNum = 4, int $maxQueueSize = 100, string $bootstrap = '')
    {
        if ($workerNum < 1) {
            throw new ParallelException('工作进程数必须 >= 1');
        }

        $this->workerNum = $workerNum;
        $this->maxQueueSize = $maxQueueSize;
        $this->bootstrap = $bootstrap;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        if (!function_exists('pcntl_fork')) {
            throw new ParallelException('需要 ext-pcntl 扩展支持');
        }

        for ($i = 0; $i < $this->workerNum; $i++) {
            $this->forkWorker($i);
        }

        $this->waitForChildren();
    }

    private function forkWorker(int $workerId): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new ParallelException("Fork worker {$workerId} 失败");
        }

        if ($pid === 0) {
            $this->runChildWorker($workerId);
            exit(0);
        }

        $this->workers[$pid] = [
            'id' => $workerId,
            'pid' => $pid,
            'status' => 'running',
        ];
    }

    private function runChildWorker(int $workerId): void
    {
        pcntl_signal(SIGTERM, function() {
            exit(0);
        });

        while ($this->running) {
            pcntl_signal_dispatch();

            if (empty($this->taskQueue)) {
                usleep(10000);
                continue;
            }

            $task = array_shift($this->taskQueue);

            if ($task === null) {
                continue;
            }

            try {
                $closure = \Closure::fromCallable($task['task']);
                $result = $closure($task['args']);

                if (isset($task['pipe'])) {
                    $pipe = fopen($task['pipe'], 'w');
                    if ($pipe) {
                        fwrite($pipe, serialize(['success' => true, 'result' => $result]) . "\n");
                        fclose($pipe);
                    }
                }
            } catch (\Throwable $e) {
                if (isset($task['pipe'])) {
                    $pipe = fopen($task['pipe'], 'w');
                    if ($pipe) {
                        fwrite($pipe, serialize(['success' => false, 'error' => $e->getMessage()]) . "\n");
                        fclose($pipe);
                    }
                }
            }
        }
    }

    private function waitForChildren(): void
    {
        while ($this->running && count($this->workers) > 0) {
            pcntl_signal_dispatch();

            $status = 0;
            $pid = pcntl_wait($status, WNOHANG);

            if ($pid > 0 && isset($this->workers[$pid])) {
                unset($this->workers[$pid]);

                if ($this->running) {
                    $newPid = pcntl_fork();

                    if ($newPid === 0) {
                        $workerId = $this->workers[array_key_first($this->workers)]['id'] ?? 0;
                        $this->runChildWorker($workerId);
                        exit(0);
                    }

                    if ($newPid > 0) {
                        $this->workers[$newPid] = [
                            'id' => $workerId ?? 0,
                            'pid' => $newPid,
                            'status' => 'running',
                        ];
                    }
                }
            }

            usleep(10000);
        }
    }

    public function submit(callable $task, array $args = []): bool
    {
        if (!$this->running) {
            $this->start();
        }

        if (count($this->taskQueue) >= $this->maxQueueSize) {
            return false;
        }

        $this->taskQueue[] = [
            'task' => $task,
            'args' => $args,
        ];

        return true;
    }

    public function submitAsync(callable $task, array $args = []): array
    {
        $pipePath = '/tmp/process_pool_' . uniqid() . '.pipe';
        posix_mkfifo($pipePath, 0600);

        $this->taskQueue[] = [
            'task' => $task,
            'args' => $args,
            'pipe' => $pipePath,
        ];

        return [
            'pipe' => $pipePath,
            'result' => null,
        ];
    }

    public function submitAndWait(callable $task, array $args = []): mixed
    {
        $pipePath = '/tmp/process_pool_' . uniqid() . '.pipe';
        posix_mkfifo($pipePath, 0600);

        $this->taskQueue[] = [
            'task' => $task,
            'args' => $args,
            'pipe' => $pipePath,
        ];

        $pipe = fopen($pipePath, 'r');
        $data = fgets($pipe);
        fclose($pipe);
        unlink($pipePath);

        $result = unserialize(trim($data));

        if (!$result['success']) {
            throw new ParallelException($result['error']);
        }

        return $result['result'];
    }

    public function getWorkerCount(): int
    {
        return count($this->workers);
    }

    public function getQueueSize(): int
    {
        return count($this->taskQueue);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function shutdown(): void
    {
        $this->running = false;

        foreach ($this->workers as $pid => $worker) {
            posix_kill($pid, SIGTERM);
        }

        $this->workers = [];
        $this->taskQueue = [];
    }

    public function scale(int $num): void
    {
        if ($num > count($this->workers)) {
            for ($i = count($this->workers); $i < $num; $i++) {
                $this->forkWorker($i);
            }
        } elseif ($num < count($this->workers)) {
            $toKill = array_slice(array_keys($this->workers), 0, count($this->workers) - $num);

            foreach ($toKill as $pid) {
                posix_kill($pid, SIGTERM);
                unset($this->workers[$pid]);
            }
        }
    }
}
