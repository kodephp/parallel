<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\AtomicLong;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Runtime\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * 引擎无关同步原语测试（无需 ext-parallel / ZTS，对标 Swoole 6 Thread 原语）
 */
final class ConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::setDefault(null);
        parent::tearDown();
    }

    public function testLockWithLockReturnsCallbackValue(): void
    {
        $lock = new Lock();
        $result = $lock->withLock(static fn () => 42);

        $this->assertSame(42, $result);
        $this->assertFalse($lock->isLocked());
    }

    public function testNamedLockMutualExclusion(): void
    {
        $name = 'ut_lock_' . uniqid();
        $a = Lock::named($name);

        $this->assertTrue($a->tryLock());

        $b = Lock::named($name);
        $this->assertFalse($b->tryLock(), '同名锁应互斥');

        $a->unlock();
        $this->assertTrue($b->tryLock(), '释放后应可获取');
        $b->unlock();
    }

    public function testAtomicArithmetic(): void
    {
        $a = new Atomic(10);

        $this->assertSame(10, $a->get());
        $this->assertSame(11, $a->inc());
        $this->assertSame(9, $a->sub(2));
        $a->set(5);
        $this->assertSame(5, $a->get());
        $this->assertTrue($a->compareAndSwap(5, 100));
        $this->assertSame(100, $a->get());
        $this->assertFalse($a->compareAndSwap(5, 200), '值已变化，CAS 应失败');
        $this->assertSame(100, $a->get());
    }

    public function testAtomicLongArithmetic(): void
    {
        $a = new AtomicLong(0);
        $a->add(1_000_000_000);
        $this->assertSame(1_000_000_000, $a->get());
    }

    /**
     * 命名原子量在多个 fork 子进程间共享，flock 保证无丢失更新。
     * 重复多轮以暴露并发初始化竞态（回归防护）。
     */
    public function testNamedAtomicSharedAcrossProcesses(): void
    {
        $count = 6;

        for ($round = 0; $round < 8; $round++) {
            $name = 'ut_atomic_' . uniqid('', true);
            $rt = new Runtime(null, 'process');
            $futures = [];
            for ($i = 0; $i < $count; $i++) {
                $futures[] = $rt->run(static function (array $args) {
                    $a = Atomic::named(0, $args['name']);
                    return $a->inc();
                }, ['name' => $name]);
            }

            $results = [];
            foreach ($futures as $f) {
                $results[] = $f->get();
            }
            $rt->close();

            sort($results);
            $this->assertSame(
                range(1, $count),
                $results,
                "第 {$round} 轮：{$count} 个进程各 inc 一次，结果应为 1..{$count} 的排列"
            );
        }
    }

    public function testBarrierSinglePartyReleasesImmediately(): void
    {
        $barrier = new Barrier(1);
        $barrier->wait(); // 不应阻塞
        $this->assertSame(0, $barrier->arrived());
    }

    /**
     * 命名屏障在多个 fork 子进程间同步：N 个参与者全部到达后才整体放行。
     * 重复多轮以暴露并发初始化竞态（回归防护）。
     */
    public function testNamedBarrierAcrossProcesses(): void
    {
        $count = 4;

        for ($round = 0; $round < 8; $round++) {
            $name = 'ut_barrier_' . uniqid('', true);
            $rt = new Runtime(null, 'process');
            $futures = [];
            for ($i = 0; $i < $count; $i++) {
                $futures[] = $rt->run(static function (array $args) {
                    $barrier = Barrier::named($args['count'], $args['name']);
                    $barrier->wait();
                    return getmypid();
                }, ['count' => $count, 'name' => $name]);
            }

            $pids = [];
            foreach ($futures as $f) {
                $pids[] = $f->get();
            }
            $rt->close();

            $this->assertCount($count, $pids, "第 {$round} 轮：全部参与者均应越过屏障");
        }
    }

    public function testChannelSendRecv(): void
    {
        $ch = new Channel();
        $ch->send('hello');
        $ch->send([1, 2, 3]);

        $this->assertSame('hello', $ch->recv());
        $this->assertSame([1, 2, 3], $ch->recv());
        $this->assertTrue($ch->isEmpty());
    }

    public function testBoundedChannelRejectsWhenFull(): void
    {
        $ch = Channel::bounded(1);
        $this->assertTrue($ch->sendNonBlocking('a'));
        $this->assertTrue($ch->isFull());
        $this->assertFalse($ch->sendNonBlocking('b'), '有界通道满时应拒绝非阻塞发送');
        $this->assertSame('a', $ch->recvNonBlocking());
    }
}
