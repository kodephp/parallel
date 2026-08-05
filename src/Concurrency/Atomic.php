<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎无关的整数原子计数器。
 *
 * 对标 Swoole 6 的 {@see \Swoole\Thread\Atomic} / {@see \Swoole\Thread\Atomic\Long}。
 * 无需 ZTS 或 ext-parallel：在 process / sync 引擎下使用 flock 保护的单文件存储，
 * 跨进程安全；可命名以便多进程共享同一计数器。
 *
 * 说明：即便运行在 parallel 引擎上下文，本类也是可移植实现（不依赖 parallel 原生
 * 共享内存），因此可作为跨引擎统一的原子原语使用。
 */
class Atomic
{
    /** @var resource */
    private $handle;

    private readonly string $file;

    public function __construct(int $initial = 0, ?string $name = null)
    {
        $this->file = $name !== null
            ? sys_get_temp_dir() . '/kode_atomic_' . md5($name)
            : tempnam(sys_get_temp_dir(), 'kode_atomic_');

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new ParallelException('无法创建原子计数器文件: ' . $this->file);
        }
        $this->handle = $handle;

        // 必须在排他锁保护下完成首次初始化：否则多进程并发构造时，
        // 某个子进程「看到文件为空→写入初始值」的动作可能晚于其他进程已完成的自增，
        // 其迟到的 ftruncate(0) 会覆盖掉已有计数，造成丢失更新。
        flock($this->handle, LOCK_EX);
        try {
            $stat = fstat($this->handle);
            if (($stat['size'] ?? 0) === 0) {
                $this->writeValue($initial);
            }
        } finally {
            flock($this->handle, LOCK_UN);
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
        $stat = fstat($this->handle);
        $size = $stat['size'] ?? 0;
        if ($size === 0) {
            return 0;
        }
        fseek($this->handle, 0);
        $raw = fread($this->handle, $size);
        return (int) ($raw !== false ? $raw : '0');
    }

    private function writeValue(int $value): void
    {
        fseek($this->handle, 0);
        ftruncate($this->handle, 0);
        fwrite($this->handle, (string) $value);
        fflush($this->handle);
    }

    public function get(): int
    {
        flock($this->handle, LOCK_SH);
        try {
            return $this->readValue();
        } finally {
            flock($this->handle, LOCK_UN);
        }
    }

    public function set(int $value): void
    {
        flock($this->handle, LOCK_EX);
        try {
            $this->writeValue($value);
        } finally {
            flock($this->handle, LOCK_UN);
        }
    }

    /**
     * 原子加，返回加之后的值。
     */
    public function add(int $delta = 1): int
    {
        flock($this->handle, LOCK_EX);
        try {
            $v = $this->readValue() + $delta;
            $this->writeValue($v);
            return $v;
        } finally {
            flock($this->handle, LOCK_UN);
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
        flock($this->handle, LOCK_EX);
        try {
            if ($this->readValue() === $expected) {
                $this->writeValue($new);
                return true;
            }
            return false;
        } finally {
            flock($this->handle, LOCK_UN);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }
}
