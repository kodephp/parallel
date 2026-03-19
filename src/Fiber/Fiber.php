<?php

declare(strict_types=1);

namespace Kode\Parallel\Fiber;

use Kode\Parallel\Exception\ParallelException;

/**
 * Fiber 协程包装类
 *
 * 包装 PHP 内置 Fiber，提供更友好的接口和错误处理。
 * 支持 PHP 8.1+ 的 Fiber 特性。
 *
 * @since PHP 8.1
 */
final class Fiber
{
    private \Fiber $fiber;
    private bool $started = false;
    private mixed $returnValue = null;

    public function __construct(callable $callback)
    {
        $this->fiber = new \Fiber($callback);
    }

    /**
     * 启动 Fiber
     *
     * @param mixed ...$args 传递给 Fiber 的参数
     * @throws ParallelException 如果 Fiber 已经启动
     */
    public function start(mixed ...$args): void
    {
        if ($this->started) {
            throw new ParallelException('Fiber 已经启动');
        }

        $this->started = true;

        try {
            $this->returnValue = $this->fiber->start(...$args);
        } catch (\Throwable $e) {
            $this->returnValue = $e;
            throw new ParallelException(
                'Fiber 启动失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 恢复 Fiber 执行
     *
     * @param mixed $value 传递给 Fiber 的值
     * @return mixed Fiber suspend 或 terminate 前的返回值
     * @throws ParallelException 如果 Fiber 未启动或已终止
     */
    public function resume(mixed $value = null): mixed
    {
        if (!$this->started) {
            throw new ParallelException('Fiber 尚未启动');
        }

        if ($this->isTerminated()) {
            throw new ParallelException('Fiber 已终止，无法恢复');
        }

        try {
            $this->returnValue = $this->fiber->resume($value);
            return $this->returnValue;
        } catch (\Throwable $e) {
            $this->returnValue = $e;
            throw new ParallelException(
                'Fiber 恢复失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 挂起 Fiber
     *
     * @param mixed $value 传递给调用者的值
     * @return mixed 调用 resume 时传递的值
     * @throws ParallelException 如果 Fiber 未启动或已终止
     */
    public static function suspend(mixed $value = null): mixed
    {
        try {
            return \Fiber::suspend($value);
        } catch (\Throwable $e) {
            throw new ParallelException(
                'Fiber 挂起失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 检查 Fiber 是否已启动
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * 检查 Fiber 是否已终止
     */
    public function isTerminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    /**
     * 检查 Fiber 是否挂起
     */
    public function isSuspended(): bool
    {
        return $this->fiber->isSuspended();
    }

    /**
     * 获取 Fiber 返回值
     *
     * @return mixed|FiberError|null
     */
    public function getReturnValue(): mixed
    {
        return $this->returnValue;
    }

    /**
     * 获取原始 Fiber 对象
     *
     * @internal
     */
    public function getInnerFiber(): \Fiber
    {
        return $this->fiber;
    }

    /**
     * 检查是否有错误
     */
    public function hasError(): bool
    {
        return $this->returnValue instanceof \Throwable;
    }

    /**
     * 获取错误（如果有）
     */
    public function getError(): ?\Throwable
    {
        return $this->returnValue instanceof \Throwable ? $this->returnValue : null;
    }
}
