<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎无关的计数信号量（Semaphore）。
 *
 * 对标 ext-parallel 的 {@see \parallel\Sync\Semaphore} 与 pthreads 的计数信号量。
 * 无需 ext-parallel / ZTS：在 process / sync 引擎下基于「独立锁文件（flock）+ 数据文件」
 * 实现跨进程安全；命名信号量可在多个进程间共享同一许可额度。
 *
 * 两种模式（按是否传 $name 自动选择）：
 * - **未命名（进程内）**：纯内存实现，零文件 I/O、零加锁，吞吐极高。
 * - **已命名（跨进程）**：独立锁文件 + 数据文件，已通过高并发压测验证零丢失更新
 *   （与 {@see Atomic} 同源的 FileLock 实现，规避 macOS 复用单 fd 的排他失效）。
 *
 * 典型用法：
 * ```php
 * $sem = new Semaphore(4);                 // 进程内 4 个许可
 * $sem->withPermits(1, fn () => heavyIO()); // 至多 4 个并发
 *
 * $db = Semaphore::named(8, 'db_pool');     // 跨进程共享 8 个数据库连接许可
 * ```
 */
class Semaphore
{
    /** 重试等待的轮询间隔（微秒） */
    private const int RETRY_US = 1000;

    private readonly bool $shared;

    private ?FileLock $lock = null;

    private ?string $dataFile = null;

    /** @var int|null 仅进程内（未命名）模式下持有可用许可数 */
    private ?int $memory = null;

    public function __construct(int $permits = 1, ?string $name = null)
    {
        if ($permits < 0) {
            throw new ParallelException('信号量许可数不能为负');
        }

        $this->shared = $name !== null;

        if ($this->shared) {
            $this->dataFile = sys_get_temp_dir() . '/kode_sem_' . md5($name);
            $this->lock = new FileLock('sem_lk_' . $name);

            // 必须在排他锁保护下完成首次初始化：否则多进程并发构造时，
            // 某个子进程「看到文件为空→写入初始值」的动作可能晚于其他进程已完成的 acquire/release，
            // 其迟到的写入会覆盖掉已有计数，造成许可丢失。
            $this->lock->lock();
            try {
                if (!file_exists($this->dataFile) || filesize($this->dataFile) === 0) {
                    $this->writeAvailable($permits);
                }
            } finally {
                $this->lock->unlock();
            }
        } else {
            $this->memory = $permits;
        }
    }

    /**
     * 创建按名称共享的跨进程计数信号量。
     */
    public static function named(int $permits, string $name): static
    {
        return new static($permits, $name);
    }

    private function readAvailable(): int
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

    private function writeAvailable(int $value): void
    {
        if ($this->shared) {
            $ok = @file_put_contents($this->dataFile, (string) $value);
            if ($ok === false) {
                throw new ParallelException('无法写入信号量文件: ' . $this->dataFile);
            }
            return;
        }
        $this->memory = $value;
    }

    /**
     * 当前可用许可数。
     */
    public function getAvailable(): int
    {
        if (!$this->shared) {
            return $this->memory;
        }
        $this->lock->lock();
        try {
            return $this->readAvailable();
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * 非阻塞获取 $n 个许可；不足则立即返回 false，不修改状态。
     */
    public function tryAcquire(int $n = 1): bool
    {
        if ($n <= 0) {
            return false;
        }

        if ($this->shared) {
            $this->lock->lock();
            try {
                $cur = $this->readAvailable();
                if ($cur >= $n) {
                    $this->writeAvailable($cur - $n);
                    return true;
                }
                return false;
            } finally {
                $this->lock->unlock();
            }
        }

        if ($this->memory >= $n) {
            $this->memory -= $n;
            return true;
        }
        return false;
    }

    /**
     * 获取 $n 个许可；不足时阻塞直到可用或超时。
     *
     * @param int $n 许可数
     * @param int $timeoutMs 超时毫秒，-1 表示无限等待
     * @return bool 是否成功获取
     */
    public function acquire(int $n = 1, int $timeoutMs = -1): bool
    {
        if ($n <= 0) {
            return false;
        }

        $deadline = $timeoutMs < 0 ? null : hrtime(true) + $timeoutMs * 1_000_000;

        while (true) {
            if ($this->tryAcquire($n)) {
                return true;
            }
            if ($deadline !== null && hrtime(true) >= $deadline) {
                return false;
            }
            usleep(self::RETRY_US);
        }
    }

    /**
     * 释放 $n 个许可。
     */
    public function release(int $n = 1): void
    {
        if ($n <= 0) {
            return;
        }

        if ($this->shared) {
            $this->lock->lock();
            try {
                $this->writeAvailable($this->readAvailable() + $n);
            } finally {
                $this->lock->unlock();
            }
            return;
        }

        $this->memory += $n;
    }

    /**
     * 在获取 $n 个许可的保护下执行回调，结束后自动释放。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withPermits(int $n, callable $callback): mixed
    {
        if (!$this->acquire($n)) {
            throw new ParallelException('获取信号量超时');
        }
        try {
            return $callback();
        } finally {
            $this->release($n);
        }
    }
}
