<?php

declare(strict_types=1);

namespace Kode\Parallel;

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Future\Future;

/**
 * 并行执行快捷函数
 *
 * parallel\run() 是功能性的、更高级别的 API，
 * 提供了单一函数入口点来通过自动调度执行并行代码。
 *
 * @param callable|\Closure $task 要并行执行的任务
 * @param array $args 任务参数
 * @param string|null $bootstrap 引导文件路径
 * @return Future 未来对象，用于获取任务返回值
 */
function run(callable|\Closure $task, array $args = [], ?string $bootstrap = null): Future
{
    static $runtime = null;

    if ($runtime === null) {
        $runtime = new Runtime($bootstrap);
    }

    return $runtime->run($task, $args);
}

/**
 * 创建新的 Runtime 实例
 *
 * @param string|null $bootstrap 引导文件路径
 * @return Runtime Runtime 实例
 */
function runtime(?string $bootstrap = null): Runtime
{
    return new Runtime($bootstrap);
}

/**
 * 创建 Task 实例
 *
 * @param \Closure $closure 任务闭包
 * @return Task Task 实例
 */
function task(\Closure $closure): Task
{
    return new Task($closure);
}
