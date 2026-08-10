<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\AtomicLong;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Concurrency\Semaphore;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Tests\Support\ForkRunner;
use PHPUnit\Framework\TestCase;

/**
 * 引擎无关同步原语测试（无需 ext-parallel / ZTS，对标 Swoole 6 Thread 原语）
 *
 * 跨进程用例直接 fork 验证：这些原语基于文件锁，其跨进程能力是原语自身属性，
 * 与执行引擎无关。
 */
final class ConcurrencyTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::setDefault(null);
        parent::tearDown();
    }

    private function skipWithoutFork(): void
    {
        if (!ForkRunner::supported()) {
            $this->markTestSkipped('当前环境不支持 pcntl fork，跳过跨进程用例');
        }
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

        $this->skipWithoutFork();

        for ($round = 0; $round < 8; $round++) {
            $name = 'ut_atomic_' . uniqid('', true);

            $results = ForkRunner::run($count, static function () use ($name) {
                return Atomic::named(0, $name)->inc();
            });

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

        $this->skipWithoutFork();

        for ($round = 0; $round < 8; $round++) {
            $name = 'ut_barrier_' . uniqid('', true);

            $pids = ForkRunner::run($count, static function () use ($count, $name) {
                Barrier::named($count, $name)->wait();

                return getmypid();
            });

            $this->assertCount($count, $pids, "第 {$round} 轮：全部参与者均应越过屏障");
            $this->assertCount($count, array_unique($pids), "第 {$round} 轮：每个参与者应是独立进程");
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

    public function testSemaphoreInProcess(): void
    {
        $sem = new Semaphore(2);
        $this->assertSame(2, $sem->getAvailable());

        $this->assertTrue($sem->tryAcquire(1));
        $this->assertSame(1, $sem->getAvailable());
        $this->assertFalse($sem->tryAcquire(2), '剩余 1 个许可，申请 2 个应失败');

        $sem->release(1);
        $this->assertSame(2, $sem->getAvailable());
        $this->assertSame('ok', $sem->withPermits(2, static fn () => 'ok'));
        $this->assertSame(2, $sem->getAvailable(), 'withPermits 结束后应自动释放');
    }

    /**
     * 命名信号量在多个 fork 子进程间共享同一许可额度，acquire/release 无丢失。
     * 重复多轮以暴露并发初始化竞态（回归防护）。
     */
    public function testNamedSemaphoreAcrossProcesses(): void
    {
        $count = 6;

        $this->skipWithoutFork();

        for ($round = 0; $round < 8; $round++) {
            $name = 'ut_sem_' . uniqid('', true);

            $during = ForkRunner::run($count, static function () use ($name) {
                $sem = Semaphore::named(1, $name);
                // 每个进程先占用 1 个许可，再释放，确保额度守恒
                $sem->acquire(1);
                $availDuringHold = $sem->getAvailable();
                $sem->release(1);

                return $availDuringHold;
            });

            // 命名信号量初始 1 个许可：任一进程持有期间，其余进程看到的可用数应为 0
            foreach ($during as $v) {
                $this->assertSame(0, $v, "第 {$round} 轮：单许可信号量被持有时其他进程应看到 0 可用");
            }

            // 释放后应恢复 1 个可用（额度守恒，无丢失）
            $check = Semaphore::named(1, $name);
            $this->assertSame(1, $check->getAvailable(), "第 {$round} 轮：释放后额度应恢复为 1");
        }
    }

    public function testLockWithLockTimeout(): void
    {
        $lock = new Lock();
        $this->assertSame('done', $lock->withLockTimeout(100, static fn () => 'done'));
        $this->assertFalse($lock->isLocked());
    }

    public function testLockWithLockTimeoutThrowsOnContention(): void
    {
        $name = 'ut_to_' . uniqid();
        $holder = Lock::named($name);
        $this->assertTrue($holder->tryLock());

        $waiter = Lock::named($name);
        $this->expectException(\Kode\Parallel\Exception\ParallelException::class);
        $waiter->withLockTimeout(50, static fn () => 'never');
    }

    public function testAtomicTryAddTrySub(): void
    {
        $a = new Atomic(0);
        $this->assertTrue($a->tryAdd(5));
        $this->assertSame(5, $a->get());
        $this->assertTrue($a->trySub(2));
        $this->assertSame(3, $a->get());
    }
}
