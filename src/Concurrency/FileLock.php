<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 内部可移植互斥锁（基于 flock 的文件锁）。
 *
 * 跨进程、跨协程安全，无需 ext-parallel / ZTS，可在任意 PHP CLI 下工作。
 * 这是 Lock / Atomic / Barrier 等原语的共享底层实现。
 *
 * 关键设计：锁文件与数据文件分离，且**每次加锁/解锁都打开并关闭一把全新的文件描述符**。
 * 之所以不复用同一个 fd 反复 flock：在 macOS / 部分 BSD 上，对同一 fd 反复
 * flock(LOCK_EX)/flock(LOCK_UN) 不能可靠地重新获取互斥锁（实测高并发下会丢失排他性，
 * 造成计数丢失）；每次使用新 fd 则稳定串行化（已通过 6 进程 × 数千次争用压测验证）。
 *
 * @internal
 */
final class FileLock
{
    private readonly string $file;

    /** @var resource|null 仅在持锁期间有效（每次加锁重新打开） */
    private $handle = null;

    private bool $locked = false;

    public function __construct(?string $name = null)
    {
        if ($name !== null) {
            $this->file = sys_get_temp_dir() . '/kode_lock_' . md5($name);
        } else {
            $this->file = tempnam(sys_get_temp_dir(), 'kode_lock_');
        }
    }

    /**
     * 阻塞加锁。
     *
     * @param int $timeoutMs 超时毫秒，-1 表示无限等待，0 表示非阻塞立即返回
     */
    public function lock(int $timeoutMs = -1): bool
    {
        if ($this->locked) {
            return true;
        }

        if ($timeoutMs === 0) {
            return $this->acquire(LOCK_EX | LOCK_NB);
        }

        if ($timeoutMs < 0) {
            return $this->acquire(LOCK_EX);
        }

        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        while (true) {
            if ($this->acquire(LOCK_EX | LOCK_NB)) {
                return true;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(1_000);
        }
    }

    /**
     * 使用一把全新的文件描述符获取锁；成功后保留该 fd 直到 unlock。
     */
    private function acquire(int $operation): bool
    {
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            throw new ParallelException('无法创建锁文件: ' . $this->file);
        }

        if (!flock($handle, $operation)) {
            fclose($handle);
            return false;
        }

        $this->handle = $handle;
        $this->locked = true;
        return true;
    }

    public function tryLock(): bool
    {
        return $this->lock(0);
    }

    public function unlock(): bool
    {
        if (!$this->locked) {
            return true;
        }

        $ok = flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
        $this->locked = false;

        return $ok;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function __destruct()
    {
        if ($this->locked && is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        $this->locked = false;
    }
}
