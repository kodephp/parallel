<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Future\FutureInterface;
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

    /**
     * race() 必须能真正进入轮询路径：用「前 N 次 poll 才完成」的 fake future 强制其
     * 多次 usleep 后再判定首个 settle 者。此前 race() 在轮询分支引用了已删除的常量
     * self::POLL_INTERVAL_US，一旦进入此路径即 fatal——本测试锁定该回归。
     */
    public function testRacePollsAndReturnsFirstSettled(): void
    {
        $slow = $this->makePendingFuture('slow', 5);
        $fast = $this->makePendingFuture('fast', 1);

        $this->assertSame('fast', Futures::race([$slow, $fast]));
    }

    /**
     * any() 同样需能进入轮询路径并正确返回首个成功（而非首个失败）的结果。
     */
    public function testAnyPollsAndReturnsFirstResolved(): void
    {
        $fail = $this->makePendingFuture(null, 5, rejected: true);
        $ok = $this->makePendingFuture('ok', 1);

        $this->assertSame('ok', Futures::any([$fail, $ok]));
    }

    /**
     * 构造一个前 $pollsUntilDone 次 done() 都返回 false 的 fake future，
     * 用以确定性地逼出组合器的轮询路径（$polls 在每次 done() 调用时自增）。
     */
    private function makePendingFuture(mixed $value, int $pollsUntilDone, bool $rejected = false): FutureInterface
    {
        return new class($value, $pollsUntilDone, $rejected) implements \Kode\Parallel\Future\FutureInterface {
            public function __construct(
                private mixed $value,
                private int $pollsUntilDone,
                private bool $rejected,
                private int $polls = 0,
            ) {
            }

            public function done(): bool
            {
                $this->polls++;

                return $this->polls > $this->pollsUntilDone;
            }

            public function get(): mixed
            {
                if ($this->rejected) {
                    throw new \RuntimeException('boom');
                }

                return $this->value;
            }

            public function getOrNull(): mixed
            {
                return $this->done() ? $this->value : null;
            }

            public function wait(int $timeoutMs = 0): bool
            {
                return $this->done();
            }

            public function cancel(): bool
            {
                return false;
            }

            public function isCancelled(): bool
            {
                return false;
            }

            public function getId(): string
            {
                return 'fake_' . spl_object_id($this);
            }

            public function then(callable $onFulfilled, ?callable $onRejected = null): FutureInterface
            {
                return $this;
            }

            public function map(callable $transform): FutureInterface
            {
                return $this;
            }

            public function catch(callable $onRejected): FutureInterface
            {
                return $this;
            }
        };
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
