<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎无关的互斥锁（Mutex）。
 *
 * 对标 Swoole 6 的 {@see \Swoole\Thread\Lock} 与 ext-parallel 的
 * {@see \parallel\Sync\Mutex}，但无需 ZTS 或 ext-parallel —— 在 process / sync
 * 引擎下使用可移植的 flock 文件锁，在 parallel 引擎上下文内可改用 parallel 原生锁。
 *
 * 典型用法：
 * ```php
 * $lock = new Lock();
 * $lock->withLock(function () {
 *     // 临界区
 * });
 * ```
 */
final class Lock
{
    private readonly FileLock $impl;

    public function __construct(?string $name = null)
    {
        $this->impl = new FileLock($name);
    }

    /**
     * 创建一个按名称共享的跨进程锁。
     *
     * 多个进程使用相同 $name 时竞争同一把锁。
     */
    public static function named(string $name): static
    {
        return new static($name);
    }

    public function lock(int $timeoutMs = -1): bool
    {
        return $this->impl->lock($timeoutMs);
    }

    public function tryLock(): bool
    {
        return $this->impl->tryLock();
    }

    public function unlock(): bool
    {
        return $this->impl->unlock();
    }

    public function isLocked(): bool
    {
        return $this->impl->isLocked();
    }

    /**
     * 在锁保护下执行回调，结束后自动释放。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLock(callable $callback): mixed
    {
        if (!$this->impl->lock()) {
            throw new ParallelException('获取锁超时');
        }
        try {
            return $callback();
        } finally {
            $this->impl->unlock();
        }
    }

    /**
     * 在锁保护下执行回调，但仅等待最多 $timeoutMs 毫秒；超时未获锁则抛出。
     *
     * 用于超时/退避调优：避免关键路径被长期阻塞。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLockTimeout(int $timeoutMs, callable $callback): mixed
    {
        if (!$this->impl->lock($timeoutMs)) {
            throw new ParallelException('获取锁超时（' . $timeoutMs . 'ms）');
        }
        try {
            return $callback();
        } finally {
            $this->impl->unlock();
        }
    }
}
