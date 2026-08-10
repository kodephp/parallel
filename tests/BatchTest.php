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
}
