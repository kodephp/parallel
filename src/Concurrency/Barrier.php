<?php

declare(strict_types=1);

namespace Kode\Parallel\Concurrency;

use Kode\Parallel\Exception\ParallelException;

/**
 * 引擎无关的线程/进程屏障（Barrier）。
 *
 * 对标 Swoole 6 的 {@see \Swoole\Thread\Barrier}。无需 ZTS 或 ext-parallel：
 *
 * - 匿名屏障（不传 $name）：用于同一运行时内的多协程/多消费者协调。
 * - 命名屏障（传 $name）：多进程通过共享文件协作，各自创建同名实例即可同步。
 *
 * 采用「代际（generation）」机制实现自动复用：当第 $count 个参与者到达后整体放行，
 * 并自动重置进入下一代，无需手动 reset。
 */
final class Barrier
{
    private readonly FileLock $lock;

    private readonly string $stateFile;

    private readonly int $count;

    public function __construct(int $count, ?string $name = null)
    {
        if ($count < 1) {
            throw new ParallelException('屏障参与方数量必须 >= 1');
        }
        $this->count = $count;

        if ($name !== null) {
            $this->stateFile = sys_get_temp_dir() . '/kode_barrier_' . md5($name);
            $this->lock = new FileLock('barrier_lk_' . $name);
        } else {
            $this->stateFile = tempnam(sys_get_temp_dir(), 'kode_barrier_');
            $this->lock = new FileLock();
        }

        // 必须在锁保护下完成共享状态的首次初始化，否则多个进程并发构造时，
        // 某个进程「看到文件不存在→写入初始状态」的动作可能晚于其他进程已完成的到达计数，
        // 其迟到的 persist() 会把 arrived 重置为 0，造成同步错乱甚至死锁。
        $this->lock->lock();
        try {
            if (!file_exists($this->stateFile)) {
                $this->persist(['arrived' => 0, 'released' => false, 'gen' => 0]);
            }
        } finally {
            $this->lock->unlock();
        }
    }

    /**
     * 创建按名称共享的跨进程屏障。
     */
    public static function named(int $count, string $name): static
    {
        return new static($count, $name);
    }

    /**
     * 阻塞，直到 $count 个参与者都调用了 wait()（同一代），然后整体放行。
     */
    public function wait(): void
    {
        $this->lock->lock();
        $state = $this->load();
        $gen = $state['gen'];
        $state['arrived']++;

        if ($state['arrived'] < $this->count) {
            $this->persist($state);
            $this->lock->unlock();

            // 自旋等待：本代被放行（released）或进入下一代（gen 变化）
            while (true) {
                $this->lock->lock();
                $cur = $this->load();
                if ($cur['gen'] !== $gen || $cur['released']) {
                    $this->lock->unlock();
                    return;
                }
                $this->lock->unlock();
                usleep(500);
            }
        }

        // 最后到达者：放行本代，并自动重置进入下一代
        $state['released'] = true;
        $this->persist($state);
        $this->lock->unlock();

        $this->lock->lock();
        $cur = $this->load();
        if ($cur['gen'] === $gen && $cur['released']) {
            $cur['arrived'] = 0;
            $cur['released'] = false;
            $cur['gen']++;
            $this->persist($cur);
        }
        $this->lock->unlock();
    }

    /**
     * 当前代已到达的参与方数量。
     */
    public function arrived(): int
    {
        $this->lock->lock();
        $v = $this->load()['arrived'];
        $this->lock->unlock();
        return $v;
    }

    /**
     * 手动重置壁垒（通常无需调用，屏障会自动复用）。
     */
    public function reset(): void
    {
        $this->lock->lock();
        $this->persist(['arrived' => 0, 'released' => false, 'gen' => 0]);
        $this->lock->unlock();
    }

    /**
     * @return array{arrived: int, released: bool, gen: int}
     */
    private function load(): array
    {
        $raw = file_get_contents($this->stateFile);
        if ($raw === false) {
            return ['arrived' => 0, 'released' => false, 'gen' => 0];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded)
            ? $decoded + ['arrived' => 0, 'released' => false, 'gen' => 0]
            : ['arrived' => 0, 'released' => false, 'gen' => 0];
    }

    /**
     * @param array{arrived: int, released: bool, gen: int} $state
     */
    private function persist(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state));
    }
}
