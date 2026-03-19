<?php

declare(strict_types=1);

namespace Kode\Parallel\Fiber;

use Kode\Parallel\Exception\ParallelException;

/**
 * FiberManager - Fiber 管理器
 *
 * 提供 Fiber 生命周期管理，用于在并行任务中协调多个 Fiber 的执行。
 * 支持 PHP 8.1+ 的 Fiber 特性，并在 PHP 8.5+ 中提供增强功能。
 *
 * @since PHP 8.1 支持基础 Fiber
 * @since PHP 8.5 提供增强的调度和错误处理
 */
final class FiberManager
{
    /** @var array<string, Fiber> */
    private array $fibers = [];
    private bool $isRunning = false;

    public function __construct()
    {
    }

    /**
     * 创建并启动一个 Fiber
     *
     * @param string $name Fiber 名称
     * @param callable $callback Fiber 执行函数
     * @param array<string, mixed> $args 传递给 Fiber 的参数
     */
    public function spawn(string $name, callable $callback, array $args = []): Fiber
    {
        $fiber = new Fiber($callback);
        $this->fibers[$name] = $fiber;

        if ($this->isRunning) {
            $fiber->start(...$args);
        }

        return $fiber;
    }

    /**
     * 获取指定名称的 Fiber
     */
    public function get(string $name): ?Fiber
    {
        return $this->fibers[$name] ?? null;
    }

    /**
     * 检查 Fiber 是否存在
     */
    public function has(string $name): bool
    {
        return isset($this->fibers[$name]);
    }

    /**
     * 启动所有已注册的 Fiber
     *
     * @param array<string, array<int, mixed>> $arguments 每 Fiber 的参数
     */
    public function startAll(array $arguments = []): void
    {
        $this->isRunning = true;

        foreach ($this->fibers as $name => $fiber) {
            $args = $arguments[$name] ?? [];
            $fiber->start(...$args);
        }
    }

    /**
     * 恢复指定 Fiber 的执行
     *
     * @param string $name Fiber 名称
     * @param mixed $value 传递给 Fiber 的值
     */
    public function resume(string $name, mixed $value = null): mixed
    {
        $fiber = $this->fibers[$name] ?? null;

        if ($fiber === null) {
            throw new ParallelException("Fiber '{$name}' 不存在");
        }

        if (!$fiber->isStarted()) {
            throw new ParallelException("Fiber '{$name}' 尚未启动");
        }

        return $fiber->resume($value);
    }

    /**
     * 让出 Fiber 执行权
     *
     * @param mixed $value 传递给调用者的值
     */
    public function yield(mixed $value = null): mixed
    {
        return Fiber::suspend($value);
    }

    /**
     * 获取所有 Fiber 的状态
     *
     * @return array<string, string> Fiber 名称到状态的映射
     */
    public function getStatus(): array
    {
        $status = [];

        foreach ($this->fibers as $name => $fiber) {
            $status[$name] = $this->getFiberState($fiber);
        }

        return $status;
    }

    /**
     * 获取单个 Fiber 的状态字符串
     */
    private function getFiberState(Fiber $fiber): string
    {
        if (!$fiber->isStarted()) {
            return 'created';
        }

        if ($fiber->isTerminated()) {
            return 'terminated';
        }

        if ($fiber->isSuspended()) {
            return 'suspended';
        }

        return 'running';
    }

    /**
     * 获取所有已终止的 Fiber 并清理
     *
     * @return array<string, mixed> 已终止 Fiber 的返回值
     */
    public function collect(): array
    {
        $results = [];

        foreach ($this->fibers as $name => $fiber) {
            if ($fiber->isTerminated()) {
                $results[$name] = $fiber->getReturnValue();
                unset($this->fibers[$name]);
            }
        }

        return $results;
    }

    /**
     * 获取当前活跃的 Fiber 数量
     */
    public function count(): int
    {
        return count($this->fibers);
    }

    /**
     * 清除所有 Fiber
     */
    public function clear(): void
    {
        $this->fibers = [];
        $this->isRunning = false;
    }
}
