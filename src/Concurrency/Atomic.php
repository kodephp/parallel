<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎无关的整数原子计数器。
 *
 * 对标 Swoole 6 的 {@see \Swoole\Thread\Atomic} / {@see \Swoole\Thread\Atomic\Long}。
 * 无需 ZTS 或 ext-parallel：在 process / sync 引擎下使用「独立锁文件（flock）+ 数据文件」
 * 实现跨进程安全，可命名以便多进程共享同一计数器。
 *
 * 设计要点（已通过高并发压测验证）：
 * - 互斥由独立的锁文件保证（flock 在 macOS / Linux 下均可正确串行化多进程）；
 * - 计数本身存放在独立的数据文件中，每次读改写都通过 file_get_contents / file_put_contents
 *   以全新的文件描述符完成，避免在同一把锁句柄上混用 ftruncate/fseek/fread/fwrite 导致的
 *   高并发丢失更新问题。
 *
 * 说明：即便运行在 parallel 引擎上下文，本类也是可移植实现（不依赖 parallel 原生
 * 共享内存），因此可作为跨引擎统一的原子原语使用。
 */
class Atomic
{
    private readonly FileLock $lock;

    private readonly string $dataFile;

    public function __construct(int $initial = 0, ?string $name = null)
    {
        $this->dataFile = $name !== null
            ? sys_get_temp_dir() . '/kode_atomic_' . md5($name)
            : tempnam(sys_get_temp_dir(), 'kode_atomic_');

        // 锁文件名与数据文件解耦，确保 flock 仅用于互斥，不参与数据读写。
        $this->lock = new FileLock($name !== null ? 'atomic_lk_' . $name : null);

        // 必须在排他锁保护下完成首次初始化：否则多进程并发构造时，
        // 某个子进程「看到文件为空→写入初始值」的动作可能晚于其他进程已完成的自增，
        // 其迟到的写入会覆盖掉已有计数，造成丢失更新。
        $this->lock->lock();
        try {
            if (!file_exists($this->dataFile) || filesize($this->dataFile) === 0) {
                $this->writeValue($initial);
            }
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * 创建按名称共享的跨进程原子计数器。
     */
    public static function named(int $initial, string $name): static
    {
        return new static($initial, $name);
    }

    private function readValue(): int
    {
        $raw = @file_get_contents($this->dataFile);
        if ($raw === false || $raw === '') {
            return 0;
        }
        return (int) $raw;
    }

    private function writeValue(int $value): void
    {
        $ok = @file_put_contents($this->dataFile, (string) $value);
        if ($ok === false) {
            throw new ParallelException('无法写入原子计数器文件: ' . $this->dataFile);
        }
    }

    public function get(): int
    {
        $this->lock->lock();
        try {
            return $this->readValue();
        } finally {
            $this->lock->unlock();
        }
    }

    public function set(int $value): void
    {
        $this->lock->lock();
        try {
            $this->writeValue($value);
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * 原子加，返回加之后的值。
     */
    public function add(int $delta = 1): int
    {
        $this->lock->lock();
        try {
            $v = $this->readValue() + $delta;
            $this->writeValue($v);
            return $v;
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * 原子减，返回减之后的值。
     */
    public function sub(int $delta = 1): int
    {
        return $this->add(-$delta);
    }

    /**
     * 原子自增 1，返回加之后的值。
     */
    public function inc(): int
    {
        return $this->add(1);
    }

    /**
     * 原子自减 1，返回减之后的值。
     */
    public function dec(): int
    {
        return $this->add(-1);
    }

    /**
     * 比较并交换：若当前值等于 $expected 则设为 $new，返回是否成功。
     */
    public function compareAndSwap(int $expected, int $new): bool
    {
        $this->lock->lock();
        try {
            if ($this->readValue() === $expected) {
                $this->writeValue($new);
                return true;
            }
            return false;
        } finally {
            $this->lock->unlock();
        }
    }
}
