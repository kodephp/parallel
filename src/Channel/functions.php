<?php

declare(strict_types=1);

namespace Kode\Parallel\Channel;

/**
 * 创建无界限通道
 *
 * @param string $name 通道名称
 * @return Channel 通道实例
 */
function make(string $name = ''): Channel
{
    return Channel::make($name);
}

/**
 * 创建有界限通道
 *
 * @param int $capacity 通道容量
 * @param string $name 通道名称
 * @return Channel 通道实例
 */
function bounded(int $capacity, string $name = ''): Channel
{
    return Channel::bounded($capacity, $name);
}
