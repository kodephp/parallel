<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Engine\SyncEngine;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Pool\WorkerPool;
use PHPUnit\Framework\TestCase;

/**
 * 工作池测试
 */
final class WorkerPoolTest extends TestCase
{
    public function testMapKeepsInputKeyOrder(): void
    {
        $pool = new WorkerPool(4, SyncEngine::NAME);

        $results = $pool->map(['a' => 1, 'b' => 2, 'c' => 3], static fn(int $n): int => $n * 10);

        $this->assertSame(['a' => 10, 'b' => 20, 'c' => 30], $results);
        $this->assertSame(SyncEngine::NAME, $pool->getEngineName());

        $pool->close();
    }

    public function testMapPassesKeyToWorker(): void
    {
        $pool = new WorkerPool(2, SyncEngine::NAME);

        $results = $pool->map(['x' => 1, 'y' => 2], static fn(int $n, string $key): string => $key . $n);

        $this->assertSame(['x' => 'x1', 'y' => 'y2'], $results);

        $pool->close();
    }

    public function testMapThrowsOnTaskFailure(): void
    {
        $pool = new WorkerPool(2, SyncEngine::NAME);

        try {
            $pool->map([1, 2], static function (int $n): int {
                if ($n === 2) {
                    throw new \RuntimeException('worker failed');
                }

                return $n;
            });
            $this->fail('应抛出 ParallelException');
        } catch (ParallelException $e) {
            $this->assertStringContainsString('worker failed', $e->getMessage());
        } finally {
            $pool->close();
        }
    }

    public function testMapSettledAggregatesFailures(): void
    {
        $pool = new WorkerPool(2, SyncEngine::NAME);

        $results = $pool->mapSettled([1, 2, 3], static function (int $n): int {
            if ($n === 2) {
                throw new \RuntimeException('bad');
            }

            return $n * 2;
        });

        $this->assertSame(Futures::STATUS_FULFILLED, $results[0]['status']);
        $this->assertSame(2, $results[0]['value']);
        $this->assertSame(Futures::STATUS_REJECTED, $results[1]['status']);
        $this->assertSame(6, $results[2]['value']);

        $pool->close();
    }

    public function testStatsTracksCompletionAndFailure(): void
    {
        $pool = new WorkerPool(2, SyncEngine::NAME);

        $pool->mapSettled([1, 2], static function (int $n): int {
            if ($n === 2) {
                throw new \RuntimeException('bad');
            }

            return $n;
        });

        $stats = $pool->stats();

        $this->assertSame(SyncEngine::NAME, $stats['engine']);
        $this->assertSame(2, $stats['submitted']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['pending']);
        $this->assertFalse($stats['concurrent']);

        $pool->close();
    }

    public function testClosedPoolRejectsSubmit(): void
    {
        $pool = new WorkerPool(1, SyncEngine::NAME);
        $pool->close();

        $this->assertTrue($pool->isClosed());
        $this->expectException(ParallelException::class);
        $pool->submit(static fn(array $args): int => 1);
    }

    public function testDefaultConcurrencyFollowsCpuCount(): void
    {
        $pool = new WorkerPool(0, SyncEngine::NAME);

        $this->assertGreaterThanOrEqual(2, $pool->getConcurrency());
        $this->assertLessThanOrEqual(32, $pool->getConcurrency());

        $pool->close();
    }

    public function testThreadPoolRespectsConcurrencyLimit(): void
    {
        $this->skipWithoutParallelEngine();

        $pool = new WorkerPool(2, ParallelEngine::NAME);
        $start = hrtime(true);

        $results = $pool->map(range(1, 4), static function (int $n): int {
            usleep(200_000);

            return $n;
        });

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame([1, 2, 3, 4], array_values($results));
        $this->assertGreaterThan(300, $elapsedMs, '并发上限为 2 时 4 个任务至少需要两批');
        $this->assertLessThan(900, $elapsedMs, '仍应明显快于全串行');

        $pool->close();
    }

    /**
     * 回归防护：单个 \parallel\Runtime 是 FIFO 串行的，
     * 工作池必须按并发上限开出多个线程，否则并发上限形同虚设。
     */
    public function testThreadPoolActuallyRunsInParallel(): void
    {
        $this->skipWithoutParallelEngine();

        $pool = new WorkerPool(4, ParallelEngine::NAME);
        $start = hrtime(true);

        $results = $pool->map(range(1, 4), static function (int $n): int {
            usleep(200_000);

            return $n * 2;
        });

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertSame([2, 4, 6, 8], array_values($results));
        $this->assertLessThan(
            500,
            $elapsedMs,
            '4 个 200ms 任务在 4 线程下应接近 200ms，而非串行的 800ms'
        );

        $pool->close();
    }

    public function testThreadPoolIsolatesTaskState(): void
    {
        $this->skipWithoutParallelEngine();

        $pool = new WorkerPool(3, ParallelEngine::NAME);

        // ext-parallel 每个线程是独立解释器，静态状态不共享
        $counters = $pool->map(range(1, 3), static function (int $n): int {
            static $calls = 0;
            $calls++;

            return $calls;
        });

        foreach ($counters as $value) {
            $this->assertSame(1, $value, '每个线程都应有独立的静态状态');
        }

        $pool->close();
    }

    public function testMapAutoBatchesLargeInputWhilePreservingOrder(): void
    {
        // 元素数远超阈值时应自动走批量合并快速路径，但结果与顺序必须与逐条一致
        $n = 2000;
        $pool = new WorkerPool(8);
        $out = $pool->map(range(0, $n - 1), static fn(int $v): int => $v * 3);
        $pool->close();

        $this->assertCount($n, $out);
        $this->assertSame(0, $out[0]);
        $this->assertSame(($n - 1) * 3, $out[$n - 1]);
        $expected = [];
        for ($i = 0; $i < $n; $i++) {
            $expected[$i] = $i * 3;
        }
        $this->assertSame($expected, $out);
    }

    public function testMapSettledAutoBatchesLargeInput(): void
    {
        $n = 1500;
        $pool = new WorkerPool(8);
        $res = $pool->mapSettled(range(0, $n - 1), static function (int $v): int {
            if ($v % 13 === 0) {
                throw new \RuntimeException('boom');
            }

            return $v * 2;
        });
        $pool->close();

        $this->assertCount($n, $res);
        $this->assertSame(Futures::STATUS_FULFILLED, $res[1]['status']);
        $this->assertSame(2, $res[1]['value']);
        $this->assertSame(Futures::STATUS_REJECTED, $res[0]['status']);
        $this->assertSame(Futures::STATUS_REJECTED, $res[13]['status']);
        $this->assertSame(0, $pool->getPendingCount());
    }

    private function skipWithoutParallelEngine(): void
    {
        if (!ParallelEngine::supported()) {
            $this->markTestSkipped('当前环境未加载 ext-parallel（需 ZTS 构建）');
        }
    }
}
