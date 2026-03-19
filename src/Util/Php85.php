<?php

declare(strict_types=1);

namespace Kode\Parallel\Util;

/**
 * PHP 8.5 特性兼容工具类
 *
 * 提供 PHP 8.5 新特性的前向兼容支持，
 * 包括管道操作符、Clone With 等。
 *
 * @since PHP 8.1+ 基础支持
 * @since PHP 8.5 完整支持
 */
final class Php85
{
    private const PHP_VERSION_ID = PHP_VERSION_ID;

    /**
     * 检查是否支持 PHP 8.5+ 特性
     */
    public static function isSupported(): bool
    {
        return self::PHP_VERSION_ID >= 80500;
    }

    /**
     * 获取 PHP 版本特性级别
     *
     * @return array<string, bool>
     */
    public static function getFeatures(): array
    {
        return [
            'pipe_operator' => self::PHP_VERSION_ID >= 80500,
            'clone_with' => self::PHP_VERSION_ID >= 80500,
            'uri_extension' => self::PHP_VERSION_ID >= 80500,
            'no_discard_attribute' => self::PHP_VERSION_ID >= 80500,
            'persistent_curl' => self::PHP_VERSION_ID >= 80500,
        ];
    }
}

/**
 * 管道操作符模拟（PHP 8.5 原生 |> 运算符的前向兼容）
 *
 * 允许从左到右连接可调用项，让数值在多个函数间顺畅传递
 *
 * @since PHP 8.1+
 * @example
 * $result = pipe($value, fn($x) => trim($x), fn($x) => strtoupper($x));
 */
if (!function_exists('pipe')) {
    function pipe(mixed $value, callable ...$functions): mixed
    {
        $result = $value;

        foreach ($functions as $function) {
            if (is_array($function) && isset($function[1]) && is_array($function[1])) {
                [$callback, $args] = $function;
                $result = $callback($result, ...$args);
            } else {
                $result = $function($result);
            }
        }

        return $result;
    }
}

/**
 * 管道操作符（使用展开参数版本）
 *
 * @since PHP 8.1+
 * @example
 * $result = pipe_with($value, [fn($x, $y) => str_replace($x, $y, ...), ['a', 'b']]);
 */
if (!function_exists('pipe_with')) {
    function pipe_with(mixed $value, callable|array ...$pipes): mixed
    {
        $result = $value;

        foreach ($pipes as $pipe) {
            if (is_array($pipe)) {
                [$callback, $args] = $pipe;
                $result = $callback($result, ...$args);
            } else {
                $result = $pipe($result);
            }
        }

        return $result;
    }
}

/**
 * Clone With - 克隆对象并更新属性
 *
 * 模拟 PHP 8.5 的 clone() 语法，可以在对象克隆时更新属性
 *
 * @since PHP 8.1+
 * @example
 * $cloned = clone_with($original, ['alpha' => 128]);
 */
if (!function_exists('clone_with')) {
    function clone_with(object $object, array $properties): object
    {
        $cloned = clone $object;

        foreach ($properties as $key => $value) {
            if (property_exists($cloned, $key)) {
                $cloned->{$key} = $value;
            }
        }

        return $cloned;
    }
}

/**
 * First-class 可调用函数创建
 *
 * @since PHP 8.1+
 */
if (!function_exists('first_class_callable')) {
    function first_class_callable(callable $callback): callable
    {
        return $callback(...);
    }
}
