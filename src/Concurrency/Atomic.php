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
 * 两种模式（按是否传 $name 自动选择）：
 * - **未命名（进程内）**：纯内存实现，零文件 I/O、零加锁，吞吐极高（百万级 ops/s）。
 *   未命名计数器在语义上本就不可能跨进程共享（路径随机且不可被发现），故不需要文件兜底。
 * - **已命名（跨进程）**：独立锁文件 + 数据文件，已通过高并发压测验证零丢失更新。
 *
 * 设计要点（跨进程模式，已验证）：
 * - 互斥由独立的锁文件保证（flock 在 macOS / Linux 下均可正确串行化多进程）；
 * - 计数本身存放在独立的数据文件中，每次读改写都通过 file_get_contents / file_put_contents
 *   以全新的文件描述符完成，避免在同一把锁句柄上混用 ftruncate/fseek/fread/fwrite 导致的
 *   高并发丢失更新问题；且每次加锁/解锁都打开并关闭一把新 fd，规避 macOS 上复用单 fd 的排他失效。
 *
 * 说明：即便运行在 parallel 引擎上下文，本类也是可移植实现（不依赖 parallel 原生
 * 共享内存），因此可作为跨引擎统一的原子原语使用。
 */
class Atomic
{
    /**
     * 是否跨进程共享模式（传了 $name 即为共享）。
     */
    private readonly bool $shared;

    private ?FileLock $lock = null;

    private ?string $dataFile = null;

    /** @var int|null 仅进程内（未命名）模式下持有计数值 */
    private ?int $memory = null;

    public function __construct(int $initial = 0, ?string $name = null)
    {
        $this->shared = $name !== null;

        if ($this->shared) {
            // 命名计数器：走「独立锁文件 + 数据文件」的跨进程安全实现。
            $this->dataFile = sys_get_temp_dir() . '/kode_atomic_' . md5($name);
            $this->lock = new FileLock('atomic_lk_' . $name);

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
        } else {
            // 未命名计数器：纯内存，零 I/O、零加锁，进程内极致吞吐。
            $this->memory = $initial;
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
        if ($this->shared) {
            $raw = @file_get_contents($this->dataFile);
            if ($raw === false || $raw === '') {
                return 0;
            }
            return (int) $raw;
        }
        return $this->memory;
    }

    private function writeValue(int $value): void
    {
        if ($this->shared) {
            $ok = @file_put_contents($this->dataFile, (string) $value);
            if ($ok === false) {
                throw new ParallelException('无法写入原子计数器文件: ' . $this->dataFile);
            }
            return;
        }
        $this->memory = $value;
    }

    public function get(): int
    {
        if (!$this->shared) {
            return $this->memory;
        }
        $this->lock->lock();
        try {
            return $this->readValue();
        } finally {
            $this->lock->unlock();
        }
    }

    public function set(int $value): void
    {
        if (!$this->shared) {
            $this->memory = $value;
            return;
        }
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
        if (!$this->shared) {
            $this->memory += $delta;
            return $this->memory;
        }
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
     * 非阻塞原子加：尝试获取底层锁，成功则执行并返回 true，锁被占用则立即返回 false。
     *
     * 用于自旋/退避调优：在弱竞争场景下避免阻塞等待，失败后可自行退避重试。
     */
    public function tryAdd(int $delta = 1): bool
    {
        if (!$this->shared) {
            $this->memory += $delta;
            return true;
        }
        if (!$this->lock->tryLock()) {
            return false;
        }
        try {
            $v = $this->readValue() + $delta;
            $this->writeValue($v);
            return true;
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * 非阻塞原子减：见 {@see tryAdd}。
     */
    public function trySub(int $delta = 1): bool
    {
        return $this->tryAdd(-$delta);
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
        if (!$this->shared) {
            if ($this->memory === $expected) {
                $this->memory = $new;
                return true;
            }
            return false;
        }
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
