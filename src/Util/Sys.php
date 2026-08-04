<?php

declare(strict_types=1);

namespace Kode\Parallel\Util;

/**
 * 系统能力探测
 *
 * @since 1.6.0
 */
final class Sys
{
    /** 探测失败时的默认并发度 */
    public const int DEFAULT_CONCURRENCY = 4;

    private static ?int $cpuCount = null;

    /**
     * CPU 逻辑核心数（探测失败回退为 4）
     */
    public static function cpuCount(): int
    {
        if (self::$cpuCount !== null) {
            return self::$cpuCount;
        }

        $count = match (PHP_OS_FAMILY) {
            'Darwin' => self::fromCommand('sysctl -n hw.logicalcpu'),
            'Linux', 'BSD', 'Solaris' => self::fromCommand('nproc 2>/dev/null')
                ?? self::fromFile('/proc/cpuinfo'),
            'Windows' => self::fromEnvWindows(),
            default => null,
        };

        return self::$cpuCount = max(1, $count ?? self::DEFAULT_CONCURRENCY);
    }

    /**
     * 推荐并发度：核心数，但至少 2、至多 32
     */
    public static function recommendedConcurrency(): int
    {
        return min(32, max(2, self::cpuCount()));
    }

    /**
     * 运行环境概要
     *
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os_family' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'cpu_count' => self::cpuCount(),
            'memory_limit' => ini_get('memory_limit'),
            'pcntl' => extension_loaded('pcntl'),
            'posix' => extension_loaded('posix'),
            'sockets' => extension_loaded('sockets'),
            'parallel' => extension_loaded('parallel'),
        ];
    }

    /**
     * 重置缓存（测试用）
     */
    public static function reset(): void
    {
        self::$cpuCount = null;
    }

    private static function fromCommand(string $command): ?int
    {
        if (!function_exists('shell_exec') || self::isDisabled('shell_exec')) {
            return null;
        }

        $output = @shell_exec($command);

        if (!is_string($output) || trim($output) === '') {
            return null;
        }

        $value = (int) trim($output);

        return $value > 0 ? $value : null;
    }

    private static function fromFile(string $path): ?int
    {
        if (!is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $count = substr_count($content, 'processor');

        return $count > 0 ? $count : null;
    }

    private static function fromEnvWindows(): ?int
    {
        $value = getenv('NUMBER_OF_PROCESSORS');

        return is_string($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function isDisabled(string $function): bool
    {
        $disabled = (string) ini_get('disable_functions');

        return in_array($function, array_map('trim', explode(',', $disabled)), true);
    }
}
