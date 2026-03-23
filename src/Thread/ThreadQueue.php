<?php

declare(strict_types=1);

namespace Kode\Parallel\Thread;

use Kode\Parallel\Exception\ParallelException;

final class ThreadQueue
{
    public const PUSH = 1;
    public const SHIFT = 2;
    public const MODE = 3;

    private array $queue = [];
    private int $maxSize;
    private int $mode;

    public function __construct(int $maxSize = 0, int $mode = self::PUSH)
    {
        $this->maxSize = $maxSize;
        $this->mode = $mode;
    }

    public function push(mixed $data): bool
    {
        if ($this->maxSize > 0 && count($this->queue) >= $this->maxSize) {
            return false;
        }

        $this->queue[] = $data;
        return true;
    }

    public function shift(): mixed
    {
        if (empty($this->queue)) {
            return null;
        }

        return array_shift($this->queue);
    }

    public function pop(): mixed
    {
        if (empty($this->queue)) {
            return null;
        }

        return array_pop($this->queue);
    }

    public function enqueue(mixed $data): bool
    {
        return $this->push($data);
    }

    public function dequeue(): mixed
    {
        return $this->mode === self::SHIFT ? $this->shift() : $this->pop();
    }

    public function peek(): mixed
    {
        if (empty($this->queue)) {
            return null;
        }

        return $this->queue[0];
    }

    public function end(): mixed
    {
        if (empty($this->queue)) {
            return null;
        }

        return $this->queue[count($this->queue) - 1];
    }

    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    public function isFull(): bool
    {
        return $this->maxSize > 0 && count($this->queue) >= $this->maxSize;
    }

    public function count(): int
    {
        return count($this->queue);
    }

    public function clear(): void
    {
        $this->queue = [];
    }

    public function toArray(): array
    {
        return $this->queue;
    }

    public function getCapacity(): int
    {
        return $this->maxSize;
    }

    public function setCapacity(int $maxSize): void
    {
        $this->maxSize = $maxSize;
    }

    public function getMode(): int
    {
        return $this->mode;
    }

    public function setMode(int $mode): void
    {
        $this->mode = $mode;
    }
}
