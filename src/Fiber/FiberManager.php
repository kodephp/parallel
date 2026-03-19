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
 * 可以与 kode/fibers 包配合使用以获得更多功能：
 * - Fiber 池化
 * - 超时控制
 * - 错误重试
 * - 上下文传递
 *
 * @since PHP 8.1
 * @see https://github.com/kodephp/fibers
 */
final class FiberManager
{
    /** @var array<string, Fiber> */
    private array $fibers = [];
    private bool $isRunning = false;
    private readonly string $id;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(8));
    }

    /**
     * 创建并启动一个 Fiber
     *
     * @param string $name Fiber 名称
     * @param callable $callback Fiber 执行函数
     * @param array<int, mixed> $args 传递给 Fiber 的参数
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
     * 创建并立即启动一个 Fiber
     *
     * @param string $name Fiber 名称
     * @param callable $callback Fiber 执行函数
     * @param array<int, mixed> $args 传递给 Fiber 的参数
     */
    public function spawnAndStart(string $name, callable $callback, array $args = []): Fiber
    {
        $fiber = new Fiber($callback);
        $fiber->start(...$args);
        $this->fibers[$name] = $fiber;
        $this->isRunning = true;

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
    public static function yield(mixed $value = null): mixed
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

        if ($fiber->isRunning()) {
            return 'running';
        }

        return 'unknown';
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
     * 获取管理器 ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 检查是否正在运行
     */
    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    /**
     * 清除所有 Fiber
     */
    public function clear(): void
    {
        $this->fibers = [];
        $this->isRunning = false;
    }

    /**
     * 等待指定 Fiber 完成
     *
     * @param string $name Fiber 名称
     * @param int $timeoutMs 超时时间（毫秒）
     */
    public function wait(string $name, int $timeoutMs = 0): bool
    {
        $fiber = $this->fibers[$name] ?? null;

        if ($fiber === null) {
            return false;
        }

        if (!$fiber->isStarted()) {
            return false;
        }

        if ($fiber->isTerminated()) {
            return true;
        }

        $startTime = hrtime(true);
        $timeoutNs = $timeoutMs * 1_000_000;

        while (!$fiber->isTerminated() && $fiber->isSuspended()) {
            $fiber->resume(null);

            if ($timeoutMs > 0 && (hrtime(true) - $startTime) >= $timeoutNs) {
                return false;
            }
        }

        return true;
    }

    /**
     * 等待所有 Fiber 完成
     *
     * @param int $timeoutMs 每个 Fiber 的超时时间（毫秒）
     */
    public function waitAll(int $timeoutMs = 0): bool
    {
        $allDone = true;

        foreach (array_keys($this->fibers) as $name) {
            if (!$this->wait($name, $timeoutMs)) {
                $allDone = false;
            }
        }

        return $allDone;
    }

    /**
     * 执行批量操作（PHP 8.5+ 管道操作符模式）
     *
     * 将数据通过多个函数管道式处理
     *
     * @param mixed $initial 初始值
     * @param callable ...$pipes 处理函数列表
     * @return mixed 最终结果
     */
    public function pipe(mixed $initial, callable ...$pipes): mixed
    {
        $result = $initial;

        foreach ($pipes as $pipe) {
            if (is_array($pipe)) {
                [$callback, $args] = $pipe;
                $result = $callback($result, ...$args);
            } else {
                $result = $pipe($result);
            }
        }

        return $result;
    }

    /**
     * 转换为字符串表示
     */
    public function __toString(): string
    {
        return sprintf(
            'FiberManager(id=%s, fibers=%d, running=%s)',
            $this->id,
            $this->count(),
            $this->isRunning ? 'yes' : 'no'
        );
    }
}
