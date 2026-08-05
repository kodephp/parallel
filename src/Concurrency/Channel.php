<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎无关的消息通道（单运行时队列）。
 *
 * 对标 Swoole 6 的 {@see \Swoole\Thread\Queue} 与 ext-parallel 的
 * {@see \parallel\Channel} 的单运行时语义。基于内存队列实现，无需 ext-parallel / ZTS，
 * 可在 sync / process 引擎下作为同一运行时内的生产者-消费者通道使用。
 *
 * 说明：跨进程的真实 IPC 通道（fork 后的父子进程之间）请使用 parallel 引擎的
 * {@see \Kode\Parallel\Channel\Channel} 或任务返回值/参数传递；本通道专注于
 * 同一运行时内的并发协调，可与 {@see Lock} / {@see Atomic} 组合构建流水线。
 */
final class Channel
{
    public const CAPACITY_UNBOUNDED = 0;

    /** @var \SplQueue<mixed> */
    private readonly \SplQueue $queue;

    private readonly int $capacity;

    private bool $closed = false;

    public function __construct(int $capacity = self::CAPACITY_UNBOUNDED)
    {
        if ($capacity < 0) {
            throw new ParallelException('通道容量不能为负');
        }
        $this->capacity = $capacity;
        $this->queue = new \SplQueue();
        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
    }

    /**
     * 创建一个无界通道。
     */
    public static function make(): static
    {
        return new static(self::CAPACITY_UNBOUNDED);
    }

    /**
     * 创建一个有界通道，满时 send 阻塞。
     */
    public static function bounded(int $capacity): static
    {
        return new static($capacity);
    }

    /**
     * 发送数据。有界通道已满时阻塞直到有空间。
     */
    public function send(mixed $value): void
    {
        if ($this->closed) {
            throw new ParallelException('通道已关闭，无法发送');
        }
        while ($this->capacity > 0 && $this->queue->count() >= $this->capacity) {
            usleep(1000);
        }
        $this->queue->enqueue($value);
    }

    /**
     * 非阻塞发送。有界通道已满时返回 false。
     */
    public function sendNonBlocking(mixed $value): bool
    {
        if ($this->closed) {
            return false;
        }
        if ($this->capacity > 0 && $this->queue->count() >= $this->capacity) {
            return false;
        }
        $this->queue->enqueue($value);
        return true;
    }

    /**
     * 接收数据。通道为空时阻塞直到有数据（或已关闭且为空则返回 null）。
     */
    public function recv(): mixed
    {
        while ($this->queue->isEmpty()) {
            if ($this->closed) {
                return null;
            }
            usleep(1000);
        }
        return $this->queue->dequeue();
    }

    /**
     * 非阻塞接收。为空时返回 null。
     */
    public function recvNonBlocking(): mixed
    {
        if ($this->queue->isEmpty()) {
            return null;
        }
        return $this->queue->dequeue();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function isFull(): bool
    {
        return $this->capacity > 0 && $this->queue->count() >= $this->capacity;
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * 关闭通道。已发送的数据仍可被接收，之后 recv 在清空后返回 null。
     */
    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
