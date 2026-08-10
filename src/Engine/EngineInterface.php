<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Future\FutureInterface;

/**
 * 并行执行引擎契约
 *
 * kode/parallel 内置两个引擎：
 * - parallel：基于 ext-parallel 的真多线程（需 ZTS 构建），本库主线
 * - sync：同步回退（Windows / 无扩展环境 / 调试时保证可运行）
 *
 * 多进程后端（如 kode/process）可实现本接口，并通过
 * {@see EngineFactory::register()} 接入自动探测与统一调度。
 *
 * @since 1.6.0
 */
interface EngineInterface
{
    /**
     * 引擎标识，如 parallel / sync，或外部注册的引擎名
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
