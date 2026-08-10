<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎工厂
 *
 * kode/parallel 聚焦**多线程**，内置两个引擎：
 * - `parallel`：基于 ext-parallel 的真线程（需 ZTS 构建），本库主线
 * - `sync`：当前进程内顺序执行的回退实现，任何环境可用
 *
 * **多进程编排不属于本包职责**（请使用 kode/process）。若需让多进程后端
 * 参与本库的统一调度与自动探测，通过 {@see self::register()} 注册为外部引擎即可：
 *
     * ```php
     * EngineFactory::register(
     *     name: 'process',
     *     factory: static fn(?string $bootstrap, int $workers) => new MyProcessEngine($bootstrap, $workers),
     *     supported: static fn() => extension_loaded('pcntl'),
     *     priority: EngineFactory::PRIORITY_EXTERNAL,
     * );
     * ```
 *
 * 探测顺序按优先级数值从大到小；可用环境变量 `KODE_PARALLEL_ENGINE`
 * 或 {@see self::setDefault()} 强制指定。
 *
 * @since 1.6.0
 */
final class EngineFactory
{
    public const string ENV_KEY = 'KODE_PARALLEL_ENGINE';

    /** 内置真线程引擎优先级 */
    public const int PRIORITY_PARALLEL = 100;

    /** 外部引擎建议默认优先级（低于真线程，高于同步回退） */
    public const int PRIORITY_EXTERNAL = 50;

    /** 内置同步回退引擎优先级（始终垫底） */
    public const int PRIORITY_SYNC = 0;

    /** @var array<string, array{factory: \Closure, supported: \Closure, priority: int}> */
    private static array $registry = [];

    private static ?string $forced = null;

    /**
     * 注册外部引擎
     *
     * 供 kode/process 等外部并行后端接入，注册后即参与 {@see self::detect()}
     * 自动探测与 {@see self::create()} 实例化。
     *
     * @param string $name 引擎标识，不得与内置的 parallel / sync 冲突
     * @param \Closure(?string, int): EngineInterface $factory 工厂，入参为 bootstrap 文件路径与并发度提示
     * @param \Closure(): bool $supported 当前环境可用性判定
     * @param int $priority 优先级，数值越大越优先被自动探测选中
     * @throws ParallelException 名称非法或与内置引擎冲突
     */
    public static function register(
        string $name,
        \Closure $factory,
        \Closure $supported,
        int $priority = self::PRIORITY_EXTERNAL
    ): void {
        $name = self::normalize($name);

        if ($name === '') {
            throw new ParallelException('引擎名不能为空');
        }

        if (isset(self::builtin()[$name])) {
            throw new ParallelException("不能覆盖内置引擎: {$name}");
        }

        self::$registry[$name] = [
            'factory' => $factory,
            'supported' => $supported,
            'priority' => $priority,
        ];
    }

    /**
     * 注销外部引擎
     *
     * @return bool 是否确实移除了一个已注册引擎
     */
    public static function unregister(string $name): bool
    {
        $name = self::normalize($name);

        if (!isset(self::$registry[$name])) {
            return false;
        }

        unset(self::$registry[$name]);

        if (self::$forced === $name) {
            self::$forced = null;
        }

        return true;
    }

    /**
     * 已注册的外部引擎名
     *
     * @return list<string>
     */
    public static function registered(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * 全部引擎名（含内置与外部），按优先级从高到低排序
     *
     * @return list<string>
     */
    public static function names(): array
    {
        $all = self::all();

        uasort($all, static fn(array $a, array $b) => $b['priority'] <=> $a['priority']);

        return array_keys($all);
    }

    /**
     * 创建引擎实例
     *
     * @param string|null $engine 指定引擎名，null 表示自动探测
     * @param string|null $bootstrap 引导文件（任务执行前加载）
     * @param int $concurrency 并发度提示；对 parallel 引擎即线程数，<=1 表示单线程 FIFO
     * @throws ParallelException 指定的引擎不存在
     */
    public static function create(
        ?string $engine = null,
        ?string $bootstrap = null,
        int $concurrency = 1
    ): EngineInterface {
        $name = self::normalize($engine ?? self::detect());
        $all = self::all();

        if (!isset($all[$name])) {
            throw new ParallelException(
                "未知引擎: {$name}，可选值: " . implode(', ', self::names())
            );
        }

        return ($all[$name]['factory'])($bootstrap, $concurrency);
    }

    /**
     * 探测最佳可用引擎名
     *
     * @throws ParallelException 强制指定的引擎在当前环境不可用
     */
    public static function detect(): string
    {
        $forced = self::$forced ?? self::fromEnv();

        if ($forced !== null) {
            $forced = self::normalize($forced);

            if (self::isSupported($forced)) {
                return $forced;
            }

            throw new ParallelException(
                "强制指定的引擎 {$forced} 在当前环境不可用",
                0,
                null,
                ['available' => array_keys(array_filter(self::available()))]
            );
        }

        foreach (self::names() as $name) {
            if (self::isSupported($name)) {
                return $name;
            }
        }

        return SyncEngine::NAME;
    }

    /**
     * 各引擎可用性（含外部注册引擎）
     *
     * @return array<string, bool>
     */
    public static function available(): array
    {
        $result = [];

        foreach (self::names() as $name) {
            $result[$name] = self::isSupported($name);
        }

        return $result;
    }

    /**
     * 指定引擎是否可用
     */
    public static function isSupported(string $engine): bool
    {
        $entry = self::all()[self::normalize($engine)] ?? null;

        return $entry !== null && ($entry['supported'])() === true;
    }

    /**
     * 全局强制引擎（传 null 恢复自动探测）
     *
     * @throws ParallelException 引擎名未知
     */
    public static function setDefault(?string $engine): void
    {
        if ($engine === null) {
            self::$forced = null;

            return;
        }

        $name = self::normalize($engine);

        if (!isset(self::all()[$name])) {
            throw new ParallelException("未知引擎: {$engine}");
        }

        self::$forced = $name;
    }

    /**
     * 当前强制引擎
     */
    public static function getDefault(): ?string
    {
        return self::$forced;
    }

    /**
     * 内置引擎表
     *
     * @return array<string, array{factory: \Closure, supported: \Closure, priority: int}>
     */
    private static function builtin(): array
    {
        return [
            ParallelEngine::NAME => [
                'factory' => static fn(?string $bootstrap, int $concurrency) => new ParallelEngine($bootstrap, $concurrency),
                'supported' => static fn(): bool => ParallelEngine::supported(),
                'priority' => self::PRIORITY_PARALLEL,
            ],
            SyncEngine::NAME => [
                'factory' => static fn(?string $bootstrap, int $concurrency) => new SyncEngine($bootstrap),
                'supported' => static fn(): bool => SyncEngine::supported(),
                'priority' => self::PRIORITY_SYNC,
            ],
        ];
    }

    /**
     * 内置 + 外部引擎全表
     *
     * @return array<string, array{factory: \Closure, supported: \Closure, priority: int}>
     */
    private static function all(): array
    {
        return self::builtin() + self::$registry;
    }

    private static function normalize(string $name): string
    {
        return strtolower(trim($name));
    }

    private static function fromEnv(): ?string
    {
        $value = getenv(self::ENV_KEY);

        if ($value === false || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
