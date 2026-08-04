<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Future\FutureInterface;

/**
 * 并行执行引擎契约
 *
 * kode/parallel 自 1.6.0 起支持多引擎：
 * - parallel：基于 ext-parallel 的真多线程（最佳性能）
 * - process：基于 pcntl_fork 的多进程（无需扩展，类 UNIX 可用）
 * - sync：同步回退（Windows 无扩展环境或调试时保证可运行）
 *
 * @since 1.6.0
 */
interface EngineInterface
{
    /**
     * 引擎标识：parallel / process / sync
     */
    public function name(): string;

    /**
     * 当前环境是否可用
     */
    public static function supported(): bool;

    /**
     * 是否真正并行（sync 引擎为 false）
     */
    public function isConcurrent(): bool;

    /**
     * 提交任务
     *
     * @param \Closure $task 任务闭包，签名为 fn(array $args): mixed
     * @param array<array-key, mixed> $args 任务参数
     */
    public function submit(\Closure $task, array $args = []): FutureInterface;

    /**
     * 释放引擎持有的资源
     */
    public function close(): void;
}
