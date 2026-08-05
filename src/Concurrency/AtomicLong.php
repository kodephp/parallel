<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

/**
 * 引擎无关的 64 位原子计数器。
 *
 * 对标 Swoole 6 的 {@see \Swoole\Thread\Atomic\Long}。PHP 的整数本身为任意精度，
 * 因此本类与 {@see Atomic} 共用同一可移植实现，仅作为语义化的长整型别名存在。
 *
 * @see Atomic
 */
final class AtomicLong extends Atomic
{
}
