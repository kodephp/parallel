<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\ProcessEngine;
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

    public function testProcessPoolRespectsConcurrencyLimit(): void
    {
        if (!ProcessEngine::supported()) {
            $this->markTestSkipped('当前环境不支持 pcntl 多进程引擎');
        }

        $pool = new WorkerPool(2, ProcessEngine::NAME);
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

    public function testProcessPoolIsolatesTaskMemory(): void
    {
        if (!ProcessEngine::supported()) {
            $this->markTestSkipped('当前环境不支持 pcntl 多进程引擎');
        }

        $pool = new WorkerPool(3, ProcessEngine::NAME);
        $parentPid = getmypid();

        $pids = $pool->map(range(1, 3), static fn(int $n): int => getmypid());

        foreach ($pids as $pid) {
            $this->assertNotSame($parentPid, $pid);
        }

        $this->assertCount(3, array_unique($pids), '每个任务都应有独立子进程');

        $pool->close();
    }
}
