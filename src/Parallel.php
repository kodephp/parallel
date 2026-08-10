<?php

declare(strict_types=1);

namespace Kode\Parallel;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Engine\SyncEngine;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Pool\ThreadPool;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Runtime\SharedRuntime;

/**
 * kode/parallel 统一门面
 *
 * 面向「多线程」的单一入口：环境探测、任务派发、结果聚合、资源管理，
 * 以及供 kode/process 等**多进程后端**接入的引擎注册。
 *
 * ```php
 * use Kode\Parallel\Parallel;
 *
 * if (Parallel::isAvailable()) {           // ZTS + ext-parallel → 真线程
 *     $future = Parallel::run(fn(array $a) => $a['n'] ** 2, ['n' => 9]);
 *     echo Parallel::await($future);       // 81
 * }
 *
 * $results = Parallel::map(range(1, 100), fn(int $n) => $n * 2, concurrency: 8);
 * ```
 *
 * 生态约定：kode/process 通过 `class_exists(\Kode\Parallel\Parallel::class)`
 * 探测本库是否可用，并据此选择多线程后端。
 *
 * @since 1.12.0
 */
final class Parallel
{
    /**
     * 当前 PHP 是否为 ZTS（线程安全）构建
     */
    public static function isZts(): bool
    {
        return defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE === true;
    }

    /**
     * 是否已加载 ext-parallel 扩展
     */
    public static function hasExtension(): bool
    {
        return ParallelEngine::supported();
    }

    /**
     * 是否支持真正的多线程并行（ZTS + ext-parallel）
     */
    public static function isAvailable(): bool
    {
        return self::isZts() && self::hasExtension();
    }

    /**
     * 当前实际生效的执行后端
     *
     * - `ext-parallel`：真线程
     * - `sync`：同进程顺序执行（回退）
     * - 其他：外部注册引擎名（如 kode/process 提供的 `process`）
     */
    public static function backend(): string
    {
        $engine = EngineFactory::detect();

        return match ($engine) {
            ParallelEngine::NAME => 'ext-parallel',
            SyncEngine::NAME => SyncEngine::NAME,
            default => $engine,
        };
    }

    /**
     * 当前生效的引擎名
     */
    public static function engine(): string
    {
        return EngineFactory::detect();
    }

    /**
     * 各引擎可用性（含外部注册引擎）
     *
     * @return array<string, bool>
     */
    public static function engines(): array
    {
        return EngineFactory::available();
    }

    /**
     * 当前引擎是否真正并发执行（sync 引擎为 false）
     */
    public static function isConcurrent(): bool
    {
        return EngineFactory::detect() !== SyncEngine::NAME;
    }

    /**
     * 运行环境摘要，便于诊断与日志
     *
     * @return array{zts: bool, ext_parallel: bool, threads: bool, engine: string, backend: string, engines: array<string, bool>, cpus: int}
     */
    public static function info(): array
    {
        return [
            'zts' => self::isZts(),
            'ext_parallel' => self::hasExtension(),
            'threads' => self::isAvailable(),
            'engine' => self::engine(),
            'backend' => self::backend(),
            'engines' => self::engines(),
            'cpus' => cpus(),
        ];
    }

    /**
     * 提交任务到进程级共享 Runtime
     *
     * @param callable|\Closure $task 任务，签名为 fn(array $args): mixed
     * @param array<array-key, mixed> $args 任务参数
     * @param string|null $bootstrap 引导文件路径（仅首次创建共享 Runtime 时生效）
     */
    public static function run(callable|\Closure $task, array $args = [], ?string $bootstrap = null): FutureInterface
    {
        return run($task, $args, $bootstrap);
    }

    /**
     * 等待单个 Future 并取值
     *
     * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
     * @throws Exception\ParallelException 超时或任务失败
     */
    public static function await(FutureInterface $future, int $timeoutMs = 0): mixed
    {
        return await($future, $timeoutMs);
    }

    /**
     * 等待全部 Future 完成，任一失败即抛出
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @return array<array-key, mixed>
     */
    public static function all(iterable $futures, int $timeoutMs = 0): array
    {
        return Futures::all($futures, $timeoutMs);
    }

    /**
     * 等待全部 Future 结束并返回 fulfilled / rejected 状态
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public static function settle(iterable $futures, int $timeoutMs = 0): array
    {
        return Futures::settle($futures, $timeoutMs);
    }

    /**
     * 并行映射，按输入键序返回结果
     *
     * @param iterable<array-key, mixed> $items
     * @param callable $worker 签名为 fn(mixed $item, array-key $key): mixed
     * @param int $concurrency 并发上限，<=0 按 CPU 核心数推荐
     * @return array<array-key, mixed>
     */
    public static function map(iterable $items, callable $worker, int $concurrency = 0): array
    {
        return map($items, $worker, $concurrency);
    }

    /**
     * 并行映射（容错版），逐项返回状态而非抛出
     *
     * @param iterable<array-key, mixed> $items
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public static function mapSettled(iterable $items, callable $worker, int $concurrency = 0): array
    {
        return map_settled($items, $worker, $concurrency);
    }

    /**
     * 创建独立 Runtime
     *
     * @param string|null $engine 引擎名，null 表示自动探测
     */
    public static function runtime(?string $bootstrap = null, ?string $engine = null): Runtime
    {
        return new Runtime($bootstrap, $engine);
    }

    /**
     * 进程级共享 Runtime
     */
    public static function shared(?string $bootstrap = null): Runtime
    {
        return shared_runtime($bootstrap);
    }

    /**
     * 创建工作池
     *
     * @param int $concurrency 并发上限，<=0 按 CPU 核心数推荐
     */
    public static function pool(int $concurrency = 0, ?string $engine = null): WorkerPool
    {
        return new WorkerPool($concurrency, $engine);
    }

    /**
     * 创建多线程池（非阻塞派发 + 常驻 worker 线程）
     *
     * 与 {@see self::pool()} 的区别：{@see ThreadPool::submit()} 永不阻塞，任务进入
     * 进程内队列立即返回 Future，由 N 个常驻线程自行拉取执行。适合生产者远快于消费者、
     * 需要预排大量任务或在协程/事件循环中非阻塞提交的场景。
     *
     * @param int $size 工作线程数（并发上限），<=0 按 CPU 核心数推荐
     * @param string|null $bootstrap 引导文件（通常为 vendor/autoload.php），用于任务闭包内使用业务类
     * @throws \Kode\Parallel\Exception\ParallelException 环境无 ext-parallel 真线程
     */
    public static function threadPool(int $size = 0, ?string $bootstrap = null): ThreadPool
    {
        return new ThreadPool($size, $bootstrap);
    }

    /**
     * 关闭进程级共享 Runtime，释放线程资源
     */
    public static function close(): void
    {
        SharedRuntime::close();
    }

    /**
     * 注册外部执行引擎（供 kode/process 等多进程后端接入）
     *
     * @param \Closure(?string): Engine\EngineInterface $factory
     * @param \Closure(): bool $supported
     * @see EngineFactory::register()
     */
    public static function registerEngine(
        string $name,
        \Closure $factory,
        \Closure $supported,
        int $priority = EngineFactory::PRIORITY_EXTERNAL
    ): void {
        EngineFactory::register($name, $factory, $supported, $priority);
    }

    /**
     * 注销外部执行引擎
     */
    public static function unregisterEngine(string $name): bool
    {
        return EngineFactory::unregister($name);
    }
}
