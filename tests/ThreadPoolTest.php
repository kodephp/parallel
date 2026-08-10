<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Pool\ThreadPool;
use PHPUnit\Framework\TestCase;

/**
 * 多线程池测试（仅 ext-parallel / ZTS 环境下运行）
 */
final class ThreadPoolTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ParallelEngine::supported()) {
            $this->markTestSkipped('当前环境未加载 ext-parallel（需 ZTS 构建）');
        }
    }

    public function testSubmitIsNonBlockingAndResolvesAll(): void
    {
        $pool = new ThreadPool(4);

        try {
            // 提交远超线程数的任务：submit 必须立即返回，不阻塞等待完成
            $futures = [];
            for ($i = 0; $i < 50; $i++) {
                $futures[$i] = $pool->submit(static fn(array $a): int => $a['n'] ** 2, ['n' => $i]);
            }

            // 提交 50 个任务后，队列里应有积压（证明没有阻塞到全部完成）
            $this->assertGreaterThan(0, $pool->getQueueLength());

            $got = Futures::all($futures);
            $this->assertCount(50, $got);
            $this->assertSame(49 ** 2, $got[49]);
        } finally {
            $pool->close();
        }
    }

    public function testMapKeepsKeyOrder(): void
    {
        $pool = new ThreadPool(3);

        try {
            $results = $pool->map(
                ['a' => 2, 'b' => 3, 'c' => 4],
                static fn(int $n, string $key): string => $key . '=' . ($n * 2)
            );

            $this->assertSame(['a' => 'a=4', 'b' => 'b=6', 'c' => 'c=8'], $results);
        } finally {
            $pool->close();
        }
    }

    public function testMapSettledMarksFailures(): void
    {
        $pool = new ThreadPool(2);

        try {
            $results = $pool->mapSettled(
                ['a' => 1, 'b' => 2, 'c' => 3],
                static function (int $n): int {
                    if ($n === 2) {
                        throw new \RuntimeException('boom');
                    }

                    return $n;
                }
            );

            $this->assertSame(Futures::STATUS_FULFILLED, $results['a']['status']);
            $this->assertSame(1, $results['a']['value']);
            $this->assertSame(Futures::STATUS_REJECTED, $results['b']['status']);
            $this->assertStringContainsString('boom', $results['b']['reason']->getMessage());
            $this->assertSame(Futures::STATUS_FULFILLED, $results['c']['status']);
            $this->assertSame(3, $results['c']['value']);
        } finally {
            $pool->close();
        }
    }

    public function testTaskFailureThrowsOnGet(): void
    {
        $pool = new ThreadPool(2);

        try {
            $future = $pool->submit(static function (): int {
                throw new \RuntimeException('worker exploded');
            });

            try {
                $future->get();
                $this->fail('应抛出 ParallelException');
            } catch (ParallelException $e) {
                $this->assertStringContainsString('worker exploded', $e->getMessage());
            }
        } finally {
            $pool->close();
        }
    }

    public function testCancelQueuedTask(): void
    {
        $pool = new ThreadPool(1);

        try {
            // 1 个线程被一个长任务占住，其余任务堆积在队列
            $blocker = $pool->submit(static function (): int {
                usleep(200_000);

                return 1;
            });

            $queued = $pool->submit(static fn(): int => 42);
            $this->assertGreaterThan(0, $pool->getQueueLength());

            $this->assertTrue($queued->cancel());
            $this->assertTrue($queued->isCancelled());

            try {
                $queued->get();
                $this->fail('已取消任务应抛异常');
            } catch (ParallelException $e) {
                $this->assertStringContainsString('取消', $e->getMessage());
            }

            // 阻塞任务仍正常完成
            $this->assertSame(1, $blocker->get());
        } finally {
            $pool->close();
        }
    }

    public function testStatsReflectsActivity(): void
    {
        $pool = new ThreadPool(4);

        try {
            for ($i = 0; $i < 10; $i++) {
                $pool->submit(static fn(array $a): int => $a['n'] + 1, ['n' => $i]);
            }

            $stats = $pool->stats();
            $this->assertSame('parallel', $stats['engine']);
            $this->assertSame(4, $stats['size']);
            $this->assertSame(10, $stats['submitted']);
            $this->assertGreaterThanOrEqual(0, $stats['queue']);
            $this->assertLessThanOrEqual(4, $stats['busy']);

            $pool->wait();
            $done = $pool->stats();
            $this->assertSame(10, $done['completed']);
            $this->assertSame(0, $done['busy']);
            $this->assertSame(0, $done['queue']);
        } finally {
            $pool->close();
        }
    }

    public function testConstructThrowsWithoutParallel(): void
    {
        // 本用例仅在非 parallel 环境下有意义；若支持则跳过（由 setUp 已保证为 parallel 环境）
        if (ParallelEngine::supported()) {
            $this->markTestSkipped('当前为 parallel 环境，无法验证无 parallel 时的异常');
        }

        $this->expectException(ParallelException::class);
        new ThreadPool(2);
    }
}
