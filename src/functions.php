<?php

declare(strict_types=1);

namespace Kode\Parallel;

use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\AtomicLong;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Runtime\SharedRuntime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Util\Sys;

/**
 * 并行执行快捷函数
 *
 * 使用进程级共享 Runtime，自动选择最佳引擎。
 *
 * @param callable|\Closure $task 任务，签名为 fn(array $args): mixed
 * @param array<array-key, mixed> $args 任务参数
 * @param string|null $bootstrap 引导文件路径（仅首次调用生效）
 */
function run(callable|\Closure $task, array $args = [], ?string $bootstrap = null): FutureInterface
{
    return shared_runtime($bootstrap)->run($task, $args);
}

/**
 * 获取进程级共享 Runtime
 *
 * @param string|null $bootstrap 引导文件路径（仅首次创建时生效）
 */
function shared_runtime(?string $bootstrap = null): Runtime
{
    return SharedRuntime::get($bootstrap);
}

/**
 * 关闭进程级共享 Runtime，释放线程资源
 *
 * 共享 Runtime 尚未创建时为空操作。
 */
function close_shared_runtime(): void
{
    SharedRuntime::close();
}

/**
 * 创建新的 Runtime 实例
 *
 * @param string|null $bootstrap 引导文件路径
 * @param string|null $engine 引擎名（parallel / process / sync）
 */
function runtime(?string $bootstrap = null, ?string $engine = null): Runtime
{
    return new Runtime($bootstrap, $engine);
}

/**
 * 创建 Task 实例
 */
function task(\Closure $closure): Task
{
    return new Task($closure);
}

/**
 * 创建工作池
 *
 * @param int $concurrency 并发上限，<=0 按 CPU 核心数推荐
 * @param string|null $engine 引擎名
 */
function pool(int $concurrency = 0, ?string $engine = null): WorkerPool
{
    return new WorkerPool($concurrency, $engine);
}

/**
 * 并行映射：对集合每个元素并行执行 worker，按输入键序返回结果
 *
 * @param iterable<array-key, mixed> $items
 * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
 * @param int $concurrency 并发上限，<=0 按 CPU 核心数推荐
 * @return array<array-key, mixed>
 * @throws ParallelException 任一任务失败
 */
function map(iterable $items, callable $worker, int $concurrency = 0): array
{
    $pool = new WorkerPool($concurrency);

    try {
        return $pool->map($items, $worker);
    } finally {
        $pool->close();
    }
}

/**
 * 并行映射（容错版）：返回每个元素的 fulfilled / rejected 状态
 *
 * @param iterable<array-key, mixed> $items
 * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
 */
function map_settled(iterable $items, callable $worker, int $concurrency = 0): array
{
    $pool = new WorkerPool($concurrency);

    try {
        return $pool->mapSettled($items, $worker);
    } finally {
        $pool->close();
    }
}

/**
 * 等待全部 Future 完成
 *
 * @param iterable<array-key, FutureInterface> $futures
 * @return array<array-key, mixed>
 */
function all(iterable $futures, int $timeoutMs = 0): array
{
    return Futures::all($futures, $timeoutMs);
}

/**
 * 等待全部 Future 结束并返回状态数组
 *
 * @param iterable<array-key, FutureInterface> $futures
 * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
 */
function settle(iterable $futures, int $timeoutMs = 0): array
{
    return Futures::settle($futures, $timeoutMs);
}

/**
 * 取第一个成功的结果
 *
 * @param iterable<array-key, FutureInterface> $futures
 */
function any(iterable $futures, int $timeoutMs = 0): mixed
{
    return Futures::any($futures, $timeoutMs);
}

/**
 * 取第一个结束的结果
 *
 * @param iterable<array-key, FutureInterface> $futures
 */
function race(iterable $futures, int $timeoutMs = 0): mixed
{
    return Futures::race($futures, $timeoutMs);
}

/**
 * 等待单个 Future 并取值
 *
 * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
 * @throws ParallelException 超时或任务失败
 */
function await(FutureInterface $future, int $timeoutMs = 0): mixed
{
    if (!$future->wait($timeoutMs)) {
        throw new ParallelException(
            '等待任务超时',
            0,
            null,
            ['id' => $future->getId(), 'timeout_ms' => $timeoutMs]
        );
    }

    return $future->get();
}

/**
 * 当前生效的引擎名（parallel / process / sync）
 */
function engine(): string
{
    return EngineFactory::detect();
}

/**
 * CPU 逻辑核心数
 */
function cpus(): int
{
    return Sys::cpuCount();
}

/**
 * 引擎无关的互斥锁（无需 ext-parallel / ZTS）
 *
 * @param string|null $name 命名锁：多进程传相同名称即可共享同一把锁
 * @see Lock
 */
function sync_lock(?string $name = null): Lock
{
    return $name === null ? new Lock() : Lock::named($name);
}

/**
 * 引擎无关的整数原子计数器
 *
 * @param string|null $name 命名原子量：多进程传相同名称即可共享同一计数器
 * @see Atomic
 */
function atomic(int $initial = 0, ?string $name = null): Atomic
{
    return $name === null ? new Atomic($initial) : Atomic::named($initial, $name);
}

/**
 * 引擎无关的 64 位原子计数器
 *
 * @see AtomicLong
 */
function atomic_long(int $initial = 0, ?string $name = null): AtomicLong
{
    return $name === null ? new AtomicLong($initial) : AtomicLong::named($initial, $name);
}

/**
 * 引擎无关的屏障（线程/进程栅栏）
 *
 * @param string|null $name 命名屏障：多进程传相同名称即可同步
 * @see Barrier
 */
function barrier(int $count, ?string $name = null): Barrier
{
    return $name === null ? new Barrier($count) : Barrier::named($count, $name);
}

/**
 * 引擎无关的消息通道（单运行时队列）
 *
 * @see Channel
 */
function concurrent_channel(int $capacity = Channel::CAPACITY_UNBOUNDED): Channel
{
    return $capacity > 0 ? Channel::bounded($capacity) : Channel::make();
}

/**
 * 非阻塞选择：返回第一个已就绪的 Future 对象，超时无就绪则返回 null
 *
 * @see Futures::select()
 */
function futures_select(iterable $futures, int $timeoutMs = 0): ?FutureInterface
{
    return Futures::select($futures, $timeoutMs);
}
