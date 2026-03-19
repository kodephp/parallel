<?php

declare(strict_types=1);

namespace Kode\Parallel\Sync;

/**
 * Mutex 互斥锁
 *
 * 提供基本的互斥功能，确保同一时刻只有一个任务可以访问共享资源。
 * 支持阻塞和非阻塞获取锁操作。
 *
 * @since PHP 8.1+ (基础版本)
 * @since PHP 8.5+ (增强版本，支持更多特性)
 */
final class Mutex
{
    private \parallel\Sync\Mutex $mutex;

    public function __construct()
    {
        $this->mutex = new \parallel\Sync\Mutex();
    }

    /**
     * 创建命名互斥锁
     *
     * @param string $name 锁名称，用于跨进程识别
     */
    public static function named(string $name): static
    {
        $mutex = new \parallel\Sync\Mutex($name);
        $instance = new static();
        $instance->mutex = $mutex;
        return $instance;
    }

    /**
     * 获取锁（阻塞）
     *
     * 如果锁已被占用，当前任务会阻塞直到锁可用。
     *
     * @param int $timeout 超时时间（毫秒），0 表示无限等待
     * @return bool 获取成功返回 true，超时返回 false
     */
    public function lock(int $timeout = 0): bool
    {
        return $this->mutex->lock($timeout);
    }

    /**
     * 释放锁
     *
     * 释放当前持有的锁，允许其他任务获取。
     */
    public function unlock(): void
    {
        $this->mutex->unlock();
    }

    /**
     * 尝试获取锁（非阻塞）
     *
     * 如果锁可用，立即获取并返回 true。
     * 如果锁不可用，立即返回 false，不会阻塞。
     *
     * @return bool 获取成功返回 true，锁不可用返回 false
     */
    public function tryLock(): bool
    {
        return $this->mutex->trylock();
    }

    /**
     * 检查锁是否被持有
     *
     * @return bool 锁被持有返回 true
     */
    public function isLocked(): bool
    {
        return $this->mutex->islocked();
    }

    /**
     * 获取内部 Mutex 对象
     *
     * @internal
     */
    public function getInnerMutex(): \parallel\Sync\Mutex
    {
        return $this->mutex;
    }

    /**
     * 自动锁助手
     *
     * @param callable $callback 持有锁期间执行的回调
     * @return mixed 回调返回值
     */
    public function withLock(callable $callback): mixed
    {
        $this->lock();
        try {
            return $callback();
        } finally {
            $this->unlock();
        }
    }
}
