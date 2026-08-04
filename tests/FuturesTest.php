<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Future\ValueFuture;
use PHPUnit\Framework\TestCase;

/**
 * Future 组合器测试
 */
final class FuturesTest extends TestCase
{
    public function testValueFutureResolved(): void
    {
        $future = ValueFuture::resolved(42);

        $this->assertTrue($future->done());
        $this->assertFalse($future->isRejected());
        $this->assertFalse($future->isCancelled());
        $this->assertFalse($future->cancel());
        $this->assertTrue($future->wait(10));
        $this->assertSame(42, $future->get());
        $this->assertSame(42, $future->getOrNull());
        $this->assertStringStartsWith('value_', $future->getId());
    }

    public function testValueFutureRejected(): void
    {
        $future = ValueFuture::rejected(new \RuntimeException('failed'));

        $this->assertTrue($future->isRejected());
        $this->assertNull($future->getOrNull());
        $this->expectException(ParallelException::class);
        $future->get();
    }

    public function testAllReturnsResultsKeyedByInput(): void
    {
        $results = Futures::all([
            'a' => ValueFuture::resolved(1),
            'b' => ValueFuture::resolved(2),
        ]);

        $this->assertSame(['a' => 1, 'b' => 2], $results);
    }

    public function testAllThrowsOnFirstFailure(): void
    {
        $this->expectException(ParallelException::class);

        Futures::all([
            ValueFuture::resolved(1),
            ValueFuture::rejected(new \RuntimeException('bad')),
        ]);
    }

    public function testAllWithEmptyInput(): void
    {
        $this->assertSame([], Futures::all([]));
        $this->assertSame([], Futures::settle([]));
    }

    public function testSettleNeverThrows(): void
    {
        $results = Futures::settle([
            'ok' => ValueFuture::resolved('v'),
            'err' => ValueFuture::rejected(new \RuntimeException('bad')),
        ]);

        $this->assertSame(Futures::STATUS_FULFILLED, $results['ok']['status']);
        $this->assertSame('v', $results['ok']['value']);
        $this->assertSame(Futures::STATUS_REJECTED, $results['err']['status']);
        $this->assertInstanceOf(\Throwable::class, $results['err']['reason']);
    }

    public function testAnyReturnsFirstSuccess(): void
    {
        $value = Futures::any([
            ValueFuture::rejected(new \RuntimeException('bad')),
            ValueFuture::resolved('good'),
        ]);

        $this->assertSame('good', $value);
    }

    public function testAnyThrowsWhenAllFail(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('全部任务均失败');

        Futures::any([
            ValueFuture::rejected(new \RuntimeException('a')),
            ValueFuture::rejected(new \RuntimeException('b')),
        ]);
    }

    public function testAnyRejectsEmptyInput(): void
    {
        $this->expectException(ParallelException::class);
        Futures::any([]);
    }

    public function testRaceReturnsFirstSettled(): void
    {
        $this->assertSame('first', Futures::race([
            ValueFuture::resolved('first'),
            ValueFuture::resolved('second'),
        ]));
    }

    public function testCountDoneAndCancelAll(): void
    {
        $futures = [ValueFuture::resolved(1), ValueFuture::resolved(2)];

        $this->assertSame(2, Futures::countDone($futures));
        $this->assertSame(0, Futures::cancelAll($futures), '已完成任务不可取消');
    }

    public function testNormalizeRejectsInvalidInput(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('FutureInterface');

        /** @phpstan-ignore-next-line 故意传入非法类型 */
        Futures::all(['not-a-future']);
    }
}
