<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

/**
 * Future 统一契约
 *
 * 无论底层由 ext-parallel 线程、pcntl 子进程还是同步回退引擎驱动，
 * 上层代码只依赖本接口，从而实现"一次编写、多引擎运行"。
 *
 * @since 1.6.0
 */
interface FutureInterface
{
    /**
     * 任务是否已结束（成功、失败或被取消都算结束）
     */
    public function done(): bool;

    /**
     * 获取任务返回值，未完成时阻塞等待
     *
     * @throws \Kode\Parallel\Exception\ParallelException 任务失败或已取消
     */
    public function get(): mixed;

    /**
     * 非阻塞获取，未完成返回 null
     */
    public function getOrNull(): mixed;

    /**
     * 等待任务完成
     *
     * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
     * @return bool 完成返回 true，超时返回 false
     */
    public function wait(int $timeoutMs = 0): bool;

    /**
     * 取消任务
     *
     * @return bool 取消成功返回 true；已完成的任务返回 false
     */
    public function cancel(): bool;

    /**
     * 是否已被取消
     */
    public function isCancelled(): bool;

    /**
     * 任务唯一标识
     */
    public function getId(): string;
}
