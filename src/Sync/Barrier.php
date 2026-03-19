<?php

declare(strict_types=1);

namespace Kode\Parallel\Sync;

use Kode\Parallel\Exception\ParallelException;

/**
 * Barrier 栅栏
 *
 * 栅栏用于同步一组线程，所有线程必须同时到达某一点后才能继续执行。
 * 适合用于需要多阶段并行计算的场景。
 *
 * @since PHP 8.1+
 */
final class Barrier
{
    private \parallel\Sync\Barrier $barrier;
    private readonly int $count;
    private readonly string $name;

    public function __construct(int $count, ?string $name = null)
    {
        if ($count < 1) {
            throw new ParallelException('栅栏计数必须 >= 1');
        }

        $this->count = $count;
        $this->name = $name ?? uniqid('barrier_', true);

        $this->barrier = new \parallel\Sync\Barrier($count, $this->name);
    }

    /**
     * 创建命名栅栏
     *
     * @param int $count 同步线程数
     * @param string $name 栅栏名称
     */
    public static function named(int $count, string $name): static
    {
        return new static($count, $name);
    }

    /**
     * 等待到达栅栏
     *
     * 当所有线程都调用此方法后，所有线程将同时解除阻塞继续执行。
     *
     * @param int $timeout 超时时间（毫秒），0 表示无限等待
     * @return bool 所有线程都到达返回 true，超时返回 false
     */
    public function wait(int $timeout = 0): bool
    {
        return $this->barrier->wait($timeout);
    }

    /**
     * 获取栅栏计数
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * 获取栅栏名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取内部栅栏对象
     *
     * @internal
     */
    public function getInnerBarrier(): \parallel\Sync\Barrier
    {
        return $this->barrier;
    }
}
