<?php

declare(strict_types=1);

namespace Kode\Parallel\Thread;

use Kode\Parallel\Exception\ParallelException;
use ArrayObject;

final class ThreadMap
{
    private ArrayObject $storage;
    private array $locks = [];
    private int $lockSize;

    public function __construct(int $lockSize = 64)
    {
        $this->storage = new ArrayObject();
        $this->lockSize = max(1, $lockSize);
    }

    public function set(string $key, mixed $value): void
    {
        $lock = $this->acquireLock($key);

        try {
            $this->storage[$key] = $value;
        } finally {
            $this->releaseLock($key, $lock);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $lock = $this->acquireLock($key);

        try {
            return $this->storage[$key] ?? $default;
        } finally {
            $this->releaseLock($key, $lock);
        }
    }

    public function has(string $key): bool
    {
        $lock = $this->acquireLock($key);

        try {
            return isset($this->storage[$key]);
        } finally {
            $this->releaseLock($key, $lock);
        }
    }

    public function delete(string $key): bool
    {
        $lock = $this->acquireLock($key);

        try {
            if (isset($this->storage[$key])) {
                unset($this->storage[$key]);
                return true;
            }
            return false;
        } finally {
            $this->releaseLock($key, $lock);
        }
    }

    public function clear(): void
    {
        $lock = $this->acquireGlobalLock();

        try {
            $this->storage = new ArrayObject();
        } finally {
            $this->releaseLock('_global_', $lock);
        }
    }

    public function count(): int
    {
        return $this->storage->count();
    }

    public function keys(): array
    {
        return array_keys((array) $this->storage);
    }

    public function values(): array
    {
        return array_values((array) $this->storage);
    }

    public function toArray(): array
    {
        return (array) $this->storage;
    }

    public function merge(array $data): void
    {
        $lock = $this->acquireGlobalLock();

        try {
            foreach ($data as $key => $value) {
                $this->storage[$key] = $value;
            }
        } finally {
            $this->releaseLock('_global_', $lock);
        }
    }

    public function increment(string $key, int $step = 1): int
    {
        $lock = $this->acquireLock($key);

        try {
            $current = (int) ($this->storage[$key] ?? 0);
            $new = $current + $step;
            $this->storage[$key] = $new;
            return $new;
        } finally {
            $this->releaseLock($key, $lock);
        }
    }

    public function decrement(string $key, int $step = 1): int
    {
        return $this->increment($key, -$step);
    }

    public function append(string $key, mixed $value): void
    {
        $lock = $this->acquireLock($key);

        try {
            if (!isset($this->storage[$key])) {
                $this->storage[$key] = [];
            }

            if (!is_array($this->storage[$key])) {
                $this->storage[$key] = [$this->storage[$key]];
            }

            $this->storage[$key][] = $value;
        } finally {
            $this->releaseLock($key, $lock);
        }
    }

    private function acquireLock(string $key): int
    {
        $bucket = abs(crc32($key)) % $this->lockSize;

        if (!isset($this->locks[$bucket])) {
            $this->locks[$bucket] = false;
        }

        while ($this->locks[$bucket] === true) {
            usleep(100);
        }

        $this->locks[$bucket] = true;

        return $bucket;
    }

    private function acquireGlobalLock(): int
    {
        return $this->acquireLock('_global_');
    }

    private function releaseLock(string $key, int $bucket): void
    {
        $this->locks[$bucket] = false;
    }
}
