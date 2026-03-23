<?php

declare(strict_types=1);

namespace Kode\Parallel\Thread;

use Kode\Parallel\Exception\ParallelException;

final class ThreadBarrier
{
    private int $threshold;
    private int $count = 0;
    private int $generation = 0;
    private array $waiting = [];

    public function __construct(int $threshold)
    {
        if ($threshold < 1) {
            throw new ParallelException('屏障阈值必须 >= 1');
        }

        $this->threshold = $threshold;
    }

    public function wait(?callable $callback = null): int
    {
        $gen = $this->generation;
        $this->count++;

        if ($this->count >= $this->threshold) {
            $this->release($callback);
            return 0;
        }

        $this->waitOnGeneration($gen);

        if ($callback !== null) {
            $callback($this->count);
        }

        return $this->count;
    }

    public function waitFor(float $timeout, ?callable $callback = null): bool
    {
        $gen = $this->generation;
        $this->count++;

        if ($this->count >= $this->threshold) {
            $this->release($callback);
            return true;
        }

        $result = $this->waitOnGenerationWithTimeout($gen, $timeout);

        if ($callback !== null) {
            $callback($this->count);
        }

        return $result;
    }

    public function reset(): void
    {
        $this->count = 0;
        $this->generation++;
        $this->waiting = [];
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getWaitingCount(): int
    {
        return count($this->waiting);
    }

    public function isReleased(): bool
    {
        return $this->count >= $this->threshold;
    }

    private function release(?callable $callback): void
    {
        if ($callback !== null) {
            for ($i = 0; $i < $this->count; $i++) {
                $callback($i);
            }
        }

        $this->count = 0;
        $this->generation++;
    }

    private function waitOnGeneration(int $generation): void
    {
        while ($this->generation === $generation) {
            usleep(1000);
        }
    }

    private function waitOnGenerationWithTimeout(int $generation, float $timeout): bool
    {
        $startTime = microtime(true);

        while ($this->generation === $generation) {
            if (microtime(true) - $startTime >= $timeout) {
                return false;
            }
            usleep(1000);
        }

        return true;
    }
}
