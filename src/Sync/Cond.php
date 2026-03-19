<?php

declare(strict_types=1);

namespace Kode\Parallel\Sync;

use Kode\Parallel\Exception\ParallelException;

/**
 * Cond 条件变量
 *
 * 条件变量用于线程间的等待和通知机制。
 * 通常与 Mutex 配合使用，实现复杂的同步逻辑。
 *
 * @since PHP 8.1+
 */
final class Cond
{
    private \parallel\Sync\Cond $cond;

    public function __construct()
    {
        $this->cond = new \parallel\Sync\Cond();
    }

    /**
     * 创建命名条件变量
     *
     * @param string $name 条件变量名称
     */
    public static function named(string $name): static
    {
        $cond = new \parallel\Sync\Cond($name);
        $instance = new static();
        $instance->cond = $cond;
        return $instance;
    }

    /**
     * 等待条件满足
     *
     * 调用时会释放关联的 Mutex 并阻塞，直到其他线程调用 signal 或 broadcast。
     *
     * @param Mutex $mutex 关联的互斥锁
     * @param int $timeout 超时时间（毫秒），0 表示无限等待
     * @return bool 成功返回 true，超时返回 false
     */
    public function wait(Mutex $mutex, int $timeout = 0): bool
    {
        return $this->cond->wait($mutex->getInnerMutex(), $timeout);
    }

    /**
     * 发送信号（唤醒一个等待的线程）
     *
     * 如果有多个线程在等待，只会唤醒其中一个。
     */
    public function signal(): void
    {
        $this->cond->signal();
    }

    /**
     * 广播（唤醒所有等待的线程）
     *
     * 会唤醒所有正在等待该条件变量的线程。
     */
    public function broadcast(): void
    {
        $this->cond->broadcast();
    }

    /**
     * 获取内部条件变量对象
     *
     * @internal
     */
    public function getInnerCond(): \parallel\Sync\Cond
    {
        return $this->cond;
    }
}
