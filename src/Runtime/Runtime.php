<?php

declare(strict_types=1);

namespace Kode\Parallel\Runtime;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Future\Future;

/**
 * Runtime 表示 PHP 解释器线程
 *
 * 将可选的 bootstrap 文件传递给 Runtime::__construct() 可用于配置 Runtime，
 * 这通常是自动加载器或者一些其它预加载程序：引导文件将在任何任务执行之前加载。
 *
 * 构造之后，Runtime 在 PHP 对象正常作用域规则关闭、杀死或者销毁之前一直可用。
 * Runtime::run() 允许程序员安排并行执行的任务。Runtime 有 FIFO 调度，
 * 任务将按照调度的顺序执行。
 */
final class Runtime
{
    private ?\parallel\Runtime $runtime = null;
    private readonly ?string $bootstrap;
    private bool $running = false;

    public function __construct(?string $bootstrap = null)
    {
        $this->bootstrap = $bootstrap;
        $this->initialize();
    }

    private function initialize(): void
    {
        try {
            $this->runtime = $this->bootstrap !== null
                ? new \parallel\Runtime($this->bootstrap)
                : new \parallel\Runtime();
        } catch (\parallel\Runtime\Error\Bootstrap $e) {
            throw new ParallelException(
                '引导文件加载失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e,
                ['bootstrap' => $this->bootstrap]
            );
        } catch (\parallel\Runtime\Error $e) {
            throw new ParallelException(
                'Runtime 初始化失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 执行任务
     *
     * @param Task|callable $task 任务闭包
     * @param array<string, mixed> $args 任务参数
     * @return Future 未来对象，用于获取任务返回值
     */
    public function run(Task|callable $task, array $args = []): Future
    {
        if ($this->runtime === null) {
            throw new ParallelException('Runtime 未正确初始化');
        }

        $closure = $task instanceof Task
            ? $task->getClosure()
            : \Closure::fromCallable($task);

        try {
            $this->running = true;
            $future = $this->runtime->run($closure, [$args]);
            $this->running = false;
            return new Future($future);
        } catch (\parallel\Runtime\Error\Bootstrap $e) {
            throw new ParallelException(
                '任务执行失败 - 引导错误: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        } catch (\parallel\Runtime\Error\Task $e) {
            throw new ParallelException(
                '任务执行失败 - 任务错误: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        } catch (\parallel\Runtime\Error $e) {
            throw new ParallelException(
                '任务执行失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 检查 Runtime 是否正在运行任务
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * 获取引导文件路径
     */
    public function getBootstrap(): ?string
    {
        return $this->bootstrap;
    }

    /**
     * 关闭 Runtime
     */
    public function close(): void
    {
        $this->runtime = null;
        $this->running = false;
    }

    public function __destruct()
    {
        $this->close();
    }
}
