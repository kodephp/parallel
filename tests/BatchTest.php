<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Pool\WorkerPool;
use PHPUnit\Framework\TestCase;

/**
 * 批量合并（mapBatch / mapBatchSettled）正确性与健壮性测试。
 * 在任意引擎（parallel / sync）下均可运行，验证：顺序保持、结果正确、
 * batchSize=1 退化为逐条、逐元素容错、空集合。
 */
final class BatchTest extends TestCase
{
    public function testMapBatchPreservesOrderAndResults(): void
    {
        $items = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
        $pool = new WorkerPool(4);
        $out = $pool->mapBatch($items, static fn (int $v, string $k): string => $k . ':' . $v, 2);
        $pool->close();

        self::assertSame(
            ['a' => 'a:1', 'b' => 'b:2', 'c' => 'c:3', 'd' => 'd:4', 'e' => 'e:5'],
            $out
        );
    }

    public function testMapBatchWithBatchSizeOneFallsBackToMap(): void
    {
        $items = [1, 2, 3, 4];
        $pool = new WorkerPool(4);
        $out = $pool->mapBatch($items, static fn (int $v): int => $v * 10, 1);
        $pool->close();

        self::assertSame([10, 20, 30, 40], array_values($out));
    }

    public function testMapBatchLargeBatchReducesOverhead(): void
    {
        // 仅验证正确性与稳定性：大批次不丢结果、顺序正确
        $n = 1000;
        $items = range(0, $n - 1);
        $pool = new WorkerPool(8);
        $out = $pool->mapBatch($items, static fn (int $v): int => $v + 1, 100);
        $pool->close();

        self::assertCount($n, $out);
        self::assertSame(1, $out[0]);
        self::assertSame($n, $out[$n - 1]);
    }

    public function testMapBatchSettledReportsPerItemFailure(): void
    {
        $items = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];
        $pool = new WorkerPool(4);
        $res = $pool->mapBatchSettled(
            $items,
            static function (int $v): int {
                if ($v === 3) {
                    throw new \RuntimeException('boom');
                }
                return $v * 2;
            },
            2
        );
        $pool->close();

        self::assertSame('fulfilled', $res['a']['status']);
        self::assertSame(2, $res['a']['value']);
        self::assertSame('rejected', $res['c']['status']);
        self::assertInstanceOf(\Kode\Parallel\Exception\ParallelException::class, $res['c']['reason']);
        self::assertStringContainsString('boom', $res['c']['reason']->getMessage());
        // 原始异常类名跨线程保留在上下文中
        self::assertSame(\RuntimeException::class, $res['c']['reason']->getContext()['class']);
        // 同批其他元素不受影响
        self::assertSame('fulfilled', $res['d']['status']);
        self::assertSame(8, $res['d']['value']);
    }

    public function testMapBatchEmpty(): void
    {
        $pool = new WorkerPool(4);
        $out = $pool->mapBatch([], static fn (): int => 1, 64);
        $pool->close();

        self::assertSame([], $out);
    }

    public function testMapBatchRecyclesPendingFutures(): void
    {
        // 批 future 在 mapBatch 结束后必须回收，否则会残留在 pending 中：
        // 污染 stats/计数，并让同一 pool 多次 mapBatch 时累积内存泄漏。
        $items = range(0, 499);
        $pool = new WorkerPool(8);
        $out = $pool->mapBatch($items, static fn (int $v): int => $v * 2, 50);
        $pool->close();

        self::assertCount(500, $out);
        self::assertSame(0, $pool->getPendingCount(), '批 future 应已回收，pending 不应残留');
        self::assertGreaterThan(0, $pool->stats()['completed'], 'completed 统计应被更新');
        self::assertSame(0, $pool->stats()['pending'], 'stats 中 pending 应与 getPendingCount 一致');
    }

    public function testMapBatchSettledRecyclesPendingFutures(): void
    {
        $items = range(0, 199);
        $pool = new WorkerPool(8);
        $res = $pool->mapBatchSettled(
            $items,
            static function (int $v): int {
                if ($v % 7 === 0) {
                    throw new \RuntimeException('boom');
                }
                return $v * 2;
            },
            20
        );
        $pool->close();

        self::assertCount(200, $res);
        self::assertSame(0, $pool->getPendingCount(), 'mapBatchSettled 也应回收 pending');
    }
}
