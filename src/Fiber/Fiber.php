<?php

declare(strict_types=1);

namespace Kode\Parallel\Fiber;

use Kode\Parallel\Exception\ParallelException;

/**
 * Fiber 协程封装类
 *
 * 包装 PHP 内置 Fiber，提供更友好的接口和错误处理。
 * 支持 PHP 8.1+ 的 Fiber 特性。
 *
 * 可以与 kode/fibers 包配合使用以获得更多功能：
 * - Fiber 池化
 * - 超时控制
 * - 错误重试
 * - 上下文传递
 *
 * @since PHP 8.1
 * @see https://github.com/kodephp/fibers
 */
final class Fiber
{
    private \Fiber $fiber;
    private bool $started = false;
    private mixed $returnValue = null;
    private readonly string $id;

    public function __construct(callable $callback)
    {
        $this->fiber = new \Fiber($callback);
        $this->id = bin2hex(random_bytes(8));
    }

    /**
     * 创建 Fiber
     *
     * @param callable $callback Fiber 执行函数
     */
    public static function create(callable $callback): static
    {
        return new static($callback);
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
     * 检查 Fiber 是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->fiber->isRunning();
    }

    /**
     * 获取 Fiber 返回值
     *
     * @return mixed|\Throwable|null
     */
    public function getReturnValue(): mixed
    {
        return $this->returnValue;
    }

    /**
     * 获取 Fiber ID
     */
    public function getId(): string
    {
        return $this->id;
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

    /**
     * 执行并等待 Fiber 完成
     *
     * @param mixed ...$args 传递给 Fiber 的参数
     * @return mixed Fiber 返回值
     */
    public function run(mixed ...$args): mixed
    {
        if (!$this->started) {
            $this->start(...$args);
        }

        while (!$this->isTerminated() && $this->isSuspended()) {
            $this->resume(null);
        }

        if ($this->hasError()) {
            throw $this->getError();
        }

        return $this->getReturnValue();
    }

    /**
     * 转换为字符串表示
     */
    public function __toString(): string
    {
        $status = match (true) {
            !$this->started => 'created',
            $this->isTerminated() => 'terminated',
            $this->isSuspended() => 'suspended',
            $this->isRunning() => 'running',
            default => 'unknown',
        };

        return sprintf('Fiber(id=%s, status=%s)', $this->id, $status);
    }
}
