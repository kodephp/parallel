<?php

declare(strict_types=1);

namespace Kode\Parallel\Sync;

use Kode\Parallel\Exception\ParallelException;

/**
 * Semaphore 信号量
 *
 * 信号量是一种更通用的同步原语，可以控制对共享资源的并发访问数量。
 * 允许指定数量的并发访问，适合实现连接池、限流等场景。
 *
 * @since PHP 8.1+
 * @since PHP 8.5+ (增强版本)
 */
final class Semaphore
{
    private \parallel\Sync\Semaphore $semaphore;
    private readonly int $count;
    private readonly string $name;

    public function __construct(int $count = 1, ?string $name = null)
    {
        if ($count < 1) {
            throw new ParallelException('信号量计数必须 >= 1');
        }

        $this->count = $count;
        $this->name = $name ?? uniqid('sem_', true);

        $this->semaphore = new \parallel\Sync\Semaphore($count, $name);
    }

    /**
     * 创建命名的信号量
     *
     * @param int $count 并发计数
     * @param string $name 信号量名称
     */
    public static function named(int $count, string $name): static
    {
        return new static($count, $name);
    }

    /**
     * 获取信号量（阻塞）
     *
     * 如果可用计数大于0，立即返回并减少计数。
     * 否则阻塞等待直到有可用计数。
     *
     * @param int $timeout 超时时间（毫秒），0 表示无限等待
     * @return bool 获取成功返回 true，超时返回 false
     */
    public function acquire(int $timeout = 0): bool
    {
        return $this->semaphore->acquire($timeout);
    }

    /**
     * 释放信号量
     *
     * 增加可用计数，允许其他等待的任务继续执行。
     */
    public function release(): void
    {
        $this->semaphore->release();
    }

    /**
     * 尝试获取信号量（非阻塞）
     *
     * @return bool 获取成功返回 true
     */
    public function tryAcquire(): bool
    {
        return $this->semaphore->trylock();
    }

    /**
     * 获取当前可用计数
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * 获取信号量名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取内部信号量对象
     *
     * @internal
     */
    public function getInnerSemaphore(): \parallel\Sync\Semaphore
    {
        return $this->semaphore;
    }

    /**
     * 自动管理助手
     *
     * @param callable $callback 持有信号量期间执行的回调
     * @return mixed 回调返回值
     */
    public function withResource(callable $callback): mixed
    {
        $this->acquire();
        try {
            return $callback();
        } finally {
            $this->release();
        }
    }
}
