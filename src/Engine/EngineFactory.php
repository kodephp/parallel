<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎工厂
 *
 * 负责探测当前环境可用的执行引擎并按优先级选择：
 * parallel（真线程） > process（多进程） > sync（同步回退）。
 *
 * 可通过环境变量 KODE_PARALLEL_ENGINE 或 EngineFactory::setDefault() 强制指定。
 *
 * @since 1.6.0
 */
final class EngineFactory
{
    public const string ENV_KEY = 'KODE_PARALLEL_ENGINE';

    /** @var list<string> 按优先级排序 */
    public const array PRIORITY = [
        ParallelEngine::NAME,
        ProcessEngine::NAME,
        SyncEngine::NAME,
    ];

    private static ?string $forced = null;

    /**
     * 创建引擎实例
     *
     * @param string|null $engine 指定引擎名，null 表示自动探测
     * @param string|null $bootstrap 引导文件（任务执行前加载）
     * @throws ParallelException 指定的引擎不存在或不可用
     */
    public static function create(?string $engine = null, ?string $bootstrap = null): EngineInterface
    {
        $name = strtolower(trim($engine ?? self::detect()));

        return match ($name) {
            ParallelEngine::NAME => new ParallelEngine($bootstrap),
            ProcessEngine::NAME => new ProcessEngine($bootstrap),
            SyncEngine::NAME => new SyncEngine($bootstrap),
            default => throw new ParallelException(
                "未知引擎: {$name}，可选值: " . implode(', ', self::PRIORITY)
            ),
        };
    }

    /**
     * 探测最佳可用引擎名
     */
    public static function detect(): string
    {
        $forced = self::$forced ?? self::fromEnv();

        if ($forced !== null) {
            $forced = strtolower(trim($forced));

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

        foreach (self::PRIORITY as $name) {
            if (self::isSupported($name)) {
                return $name;
            }
        }

        return SyncEngine::NAME;
    }

    /**
     * 各引擎可用性
     *
     * @return array<string, bool>
     */
    public static function available(): array
    {
        return [
            ParallelEngine::NAME => ParallelEngine::supported(),
            ProcessEngine::NAME => ProcessEngine::supported(),
            SyncEngine::NAME => SyncEngine::supported(),
        ];
    }

    /**
     * 指定引擎是否可用
     */
    public static function isSupported(string $engine): bool
    {
        return self::available()[strtolower(trim($engine))] ?? false;
    }

    /**
     * 全局强制引擎（传 null 恢复自动探测）
     */
    public static function setDefault(?string $engine): void
    {
        if ($engine !== null && !in_array(strtolower(trim($engine)), self::PRIORITY, true)) {
            throw new ParallelException("未知引擎: {$engine}");
        }

        self::$forced = $engine === null ? null : strtolower(trim($engine));
    }

    /**
     * 当前强制引擎
     */
    public static function getDefault(): ?string
    {
        return self::$forced;
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
