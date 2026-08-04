<?php

declare(strict_types=1);

namespace Kode\Parallel\Util;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Exception\ParallelException;

/**
 * 安装与运行环境自检
 *
 * 自 1.6.0 起 ext-parallel 不再是硬性依赖：缺少扩展时会自动降级到
 * process / sync 引擎，因此 check() 只在"连同步引擎都不可用"时才失败。
 * 如果业务确实必须使用真线程，请调用 assertExtension()。
 */
final class Installation
{
    /** 本库要求的最低 PHP 版本 */
    public const string MIN_PHP_VERSION = '8.3.0';

    private static ?bool $extensionLoaded = null;

    /**
     * 环境自检
     *
     * @throws ParallelException PHP 版本过低或无任何可用引擎
     */
    public static function check(): void
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
            throw new ParallelException(
                sprintf(
                    'kode/parallel 需要 PHP >= %s，当前版本 %s',
                    self::MIN_PHP_VERSION,
                    PHP_VERSION
                )
            );
        }

        if (!in_array(true, EngineFactory::available(), true)) {
            throw new ParallelException('没有任何可用的执行引擎');
        }
    }

    /**
     * ext-parallel 是否已加载
     */
    public static function isAvailable(): bool
    {
        return self::$extensionLoaded ??= extension_loaded('parallel');
    }

    /**
     * 断言 ext-parallel 已安装（需要真线程的场景）
     *
     * @throws ParallelException 扩展缺失
     */
    public static function assertExtension(): void
    {
        if (!self::isAvailable()) {
            throw new ParallelException(self::installHint());
        }
    }

    /**
     * @deprecated 1.6.0 语义已变更，请使用 assertExtension()
     */
    public static function assert(): void
    {
        self::assertExtension();
    }

    /**
     * 环境信息
     *
     * @return array{php_version: string, min_php_version: string, php_ok: bool, parallel_loaded: bool, engine: string, engines: array<string, bool>, extension_dir: string|false, ini_file: string|false, cpu_count: int, os_family: string, kode_parallel_version: string|null}
     */
    public static function getInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'min_php_version' => self::MIN_PHP_VERSION,
            'php_ok' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'parallel_loaded' => self::isAvailable(),
            'engine' => EngineFactory::detect(),
            'engines' => EngineFactory::available(),
            'extension_dir' => ini_get('extension_dir'),
            'ini_file' => php_ini_loaded_file(),
            'cpu_count' => Sys::cpuCount(),
            'os_family' => PHP_OS_FAMILY,
            'kode_parallel_version' => self::getPackageVersion(),
        ];
    }

    /**
     * 生成可直接打印的诊断报告
     */
    public static function report(): string
    {
        $info = self::getInfo();
        $engines = [];

        foreach ($info['engines'] as $name => $ok) {
            $engines[] = ($ok ? '[可用] ' : '[不可用] ') . $name;
        }

        $lines = [
            'kode/parallel 环境诊断',
            str_repeat('-', 46),
            '包版本      : ' . ($info['kode_parallel_version'] ?? '未知'),
            'PHP 版本    : ' . $info['php_version'] . ($info['php_ok'] ? ' (满足 >= ' . $info['min_php_version'] . ')' : ' (低于要求 ' . $info['min_php_version'] . ')'),
            '操作系统    : ' . $info['os_family'],
            'CPU 核心    : ' . $info['cpu_count'],
            'ext-parallel: ' . ($info['parallel_loaded'] ? '已安装' : '未安装（将自动降级）'),
            '可用引擎    : ' . implode('  ', $engines),
            '当前引擎    : ' . $info['engine'],
        ];

        if (!$info['parallel_loaded']) {
            $lines[] = str_repeat('-', 46);
            $lines[] = self::installHint();
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /**
     * ext-parallel 安装提示
     */
    public static function installHint(): string
    {
        return implode(PHP_EOL, [
            '未检测到 ext-parallel，如需真线程并行请安装：',
            '  1) PECL（需 ZTS 版 PHP）: pecl install parallel',
            '  2) 源码编译: git clone https://github.com/krakjoe/parallel.git',
            '     cd parallel && phpize && ./configure && make && make install',
            '  3) 启用扩展: 在 php.ini 中加入 extension=parallel',
            '注意：ext-parallel 仅支持线程安全（ZTS）构建的 PHP。',
            '未安装时本库会自动使用 process（pcntl 多进程）或 sync 引擎。',
        ]);
    }

    /**
     * 重置缓存（测试用）
     */
    public static function reset(): void
    {
        self::$extensionLoaded = null;
    }

    private static function getPackageVersion(): ?string
    {
        $composerJson = __DIR__ . '/../../composer.json';

        if (!is_file($composerJson)) {
            return null;
        }

        $raw = file_get_contents($composerJson);

        if ($raw === false || !json_validate($raw)) {
            return null;
        }

        /** @var array{version?: string} $data */
        $data = json_decode($raw, true);

        return $data['version'] ?? null;
    }
}
