<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 内部可移植互斥锁（基于 flock 的文件锁）。
 *
 * 跨进程、跨协程安全，无需 ext-parallel / ZTS，可在任意 PHP CLI 下工作。
 * 这是 Lock / Atomic / Barrier 等原语的共享底层实现。
 *
 * @internal
 */
final class FileLock
{
    private readonly string $file;

    /** @var resource */
    private $handle;

    private bool $locked = false;

    public function __construct(?string $name = null)
    {
        if ($name !== null) {
            $dir = sys_get_temp_dir();
            $this->file = $dir . '/kode_lock_' . md5($name);
        } else {
            $this->file = tempnam(sys_get_temp_dir(), 'kode_lock_');
        }

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new ParallelException('无法创建锁文件: ' . $this->file);
        }
        $this->handle = $handle;
    }

    /**
     * 阻塞加锁。
     *
     * @param int $timeoutMs 超时毫秒，-1 表示无限等待
     */
    public function lock(int $timeoutMs = -1): bool
    {
        if ($this->locked) {
            return true;
        }

        if ($timeoutMs === 0) {
            $ok = flock($this->handle, LOCK_EX | LOCK_NB);
            $this->locked = $ok;
            return $ok;
        }

        if ($timeoutMs < 0) {
            $ok = flock($this->handle, LOCK_EX);
            $this->locked = $ok;
            return $ok;
        }

        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        while (true) {
            if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                $this->locked = true;
                return true;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(1_000);
        }
    }

    public function tryLock(): bool
    {
        return $this->lock(0);
    }

    public function unlock(): bool
    {
        if (!$this->locked) {
            return true;
        }
        $ok = flock($this->handle, LOCK_UN);
        $this->locked = !$ok;
        return $ok;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}

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
}
