<?php

declare(strict_types=1);

namespace Kode\Parallel\Util;

use Kode\Parallel\Exception\ParallelException;

final class Installation
{
    private static ?bool $checked = null;
    private static ?bool $available = null;

    public static function check(): void
    {
        if (self::$checked === true) {
            return;
        }

        self::$checked = true;
        self::$available = extension_loaded('parallel');

        if (!self::$available) {
            self::fail();
        }
    }

    public static function isAvailable(): bool
    {
        if (self::$checked === null) {
            self::check();
        }

        return self::$available ?? false;
    }

    public static function assert(): void
    {
        if (!self::isAvailable()) {
            self::fail();
        }
    }

    private static function fail(): never
    {
        $message = PHP_EOL . PHP_EOL;
        $message .= "╔══════════════════════════════════════════════════════════════════╗" . PHP_EOL;
        $message .= "║                    Kode/Parallel 安装错误                         ║" . PHP_EOL;
        $message .= "╠══════════════════════════════════════════════════════════════════╣" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "║  错误: ext-parallel 扩展未安装                                    ║" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "║  当前 PHP 版本: " . PHP_VERSION . str_repeat(' ', max(0, 38 - strlen(PHP_VERSION))) . "║" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "╠══════════════════════════════════════════════════════════════════╣" . PHP_EOL;
        $message .= "║                        安装指南                                   ║" . PHP_EOL;
        $message .= "╠══════════════════════════════════════════════════════════════════╣" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "║  方法 1 - PECL (推荐):                                            ║" . PHP_EOL;
        $message .= "║    \$ pecl install parallel                                        ║" . PHP_EOL;
        $message .= "║    \$ echo 'extension=parallel.so' >> php.ini                       ║" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "║  方法 2 - 源码编译:                                               ║" . PHP_EOL;
        $message .= "║    \$ git clone https://github.com/krakjoe/parallel.git           ║" . PHP_EOL;
        $message .= "║    \$ cd parallel && phpize && ./configure && make && make install ║" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "║  方法 3 - Homebrew (macOS):                                       ║" . PHP_EOL;
        $message .= "║    \$ brew install php параллельно (俄语仓库)                     ║" . PHP_EOL;
        $message .= "║    或搜索第三方 PHP 并行扩展 tap                                   ║" . PHP_EOL;
        $message .= "║                                                                  ║" . PHP_EOL;
        $message .= "╚══════════════════════════════════════════════════════════════════╝" . PHP_EOL;
        $message .= PHP_EOL;

        throw new ParallelException($message);
    }

    public static function getInfo(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'parallel_loaded' => extension_loaded('parallel'),
            'parallel_version' => php_ini_loaded_file(),
            'extension_dir' => ini_get('extension_dir'),
            'kode_parallel_version' => self::getPackageVersion(),
        ];
    }

    private static function getPackageVersion(): ?string
    {
        $composerJson = __DIR__ . '/../../composer.json';

        if (file_exists($composerJson)) {
            $data = json_decode(file_get_contents($composerJson), true);
            return $data['version'] ?? null;
        }

        return null;
    }
}
