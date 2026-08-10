<?php

declare(strict_types=1);

namespace Kode\Parallel\Runtime;

/**
 * 进程级共享 Runtime 持有者
 *
 * 让 {@see \Kode\Parallel\shared_runtime()} 与 {@see \Kode\Parallel\Parallel}
 * 门面共用同一个实例，并支持在未创建时安全关闭（避免「为了关闭而先创建」）。
 *
 * @internal
 * @since 1.12.0
 */
final class SharedRuntime
{
    private static ?Runtime $instance = null;

    /**
     * 获取共享实例，必要时按需创建
     *
     * @param string|null $bootstrap 引导文件路径（仅首次创建时生效）
     */
    public static function get(?string $bootstrap = null): Runtime
    {
        if (self::$instance === null || self::$instance->isClosed()) {
            self::$instance = new Runtime($bootstrap);
        }

        return self::$instance;
    }

    /**
     * 是否已存在可用的共享实例
     */
    public static function exists(): bool
    {
        return self::$instance !== null && !self::$instance->isClosed();
    }

    /**
     * 关闭并释放共享实例；未创建时为空操作
     */
    public static function close(): void
    {
        self::$instance?->close();
        self::$instance = null;
    }
}
