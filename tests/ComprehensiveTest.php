<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Future\Future;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Events\Events;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Sync\Mutex;
use Kode\Parallel\Sync\Semaphore;
use Kode\Parallel\Sync\Cond;
use Kode\Parallel\Sync\Barrier;
use Kode\Parallel\Curl\CurlMulti;
use Kode\Parallel\Pipe\Pipe;

class ComprehensiveTest extends TestCase
{
    private ?Runtime $runtime = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('parallel')) {
            $this->markTestSkipped('ext-parallel 未安装');
        }

        $this->runtime = new Runtime();
    }

    protected function tearDown(): void
    {
        $this->runtime?->close();
        $this->runtime = null;
        parent::tearDown();
    }

    public function testRuntimeCreation(): void
    {
        $runtime = new Runtime();
        $this->assertInstanceOf(Runtime::class, $runtime);
        $this->assertFalse($runtime->isRunning());
        $this->assertNull($runtime->getBootstrap());
        $runtime->close();
    }

    public function testRuntimeWithBootstrap(): void
    {
        $runtime = new Runtime(null);
        $this->assertNull($runtime->getBootstrap());
        $runtime->close();
    }

    public function testSimpleTaskExecution(): void
    {
        $future = $this->runtime->run(fn() => 42);
        $this->assertTrue($future->done());
        $this->assertEquals(42, $future->get());
    }

    public function testTaskWithArguments(): void
    {
        $future = $this->runtime->run(
            fn($args) => $args['a'] + $args['b'],
            ['a' => 10, 'b' => 20]
        );

        $this->assertTrue($future->done());
        $this->assertEquals(30, $future->get());
    }

    public function testTaskWithArrayOperations(): void
    {
        $future = $this->runtime->run(
            fn($args) => array_sum(range(1, $args['n'])),
            ['n' => 100]
        );

        $this->assertEquals(5050, $future->get());
    }

    public function testMultipleTaskExecution(): void
    {
        $futures = [];
        for ($i = 0; $i < 5; $i++) {
            $futures[] = $this->runtime->run(fn($args) => $args['i'] * 2, ['i' => $i]);
        }

        foreach ($futures as $index => $future) {
            $this->assertEquals($index * 2, $future->get());
        }
    }

    public function testFutureDone(): void
    {
        $future = $this->runtime->run(fn() => usleep(10000) . 'done', []);
        $this->assertFalse($future->done());

        $result = $future->wait(1000);
        $this->assertTrue($result);
        $this->assertTrue($future->done());
    }

    public function testFutureWaitWithTimeout(): void
    {
        $future = $this->runtime->run(fn() => sleep(10), []);

        $this->assertFalse($future->wait(100));
        $future->cancel();
    }

    public function testFutureCancel(): void
    {
        $future = $this->runtime->run(fn() => sleep(10), []);

        $this->assertTrue($future->cancel());
        $this->assertTrue($future->isCancelled());
        $this->assertFalse($future->cancel());
    }

    public function testFutureGetOrNull(): void
    {
        $future = $this->runtime->run(fn() => 42);

        $this->assertNull($future->getOrNull());
        $this->assertEquals(42, $future->get());
        $this->assertEquals(42, $future->getOrNull());
    }

    public function testTaskCreation(): void
    {
        $task = new Task(fn() => 'task_result');
        $future = $this->runtime->run($task);

        $this->assertEquals('task_result', $future->get());
    }

    public function testTaskFromClosure(): void
    {
        $task = Task::from(fn($args) => $args['x'] * $args['x']);
        $future = $this->runtime->run($task, ['x' => 5]);

        $this->assertEquals(25, $future->get());
    }

    public function testTaskExecute(): void
    {
        $task = new Task(fn($args) => $args['value'] * 3);
        $result = $task->execute(['value' => 7]);

        $this->assertEquals(21, $result);
    }

    public function testTaskValidationYield(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('yield');

        $code = 'return function() { yield 1; };';
        $taskClosure = eval($code);
        new Task($taskClosure);
    }

    public function testTaskValidationReference(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('引用');

        $code = 'return function() use (&$ref) { return $ref; };';
        $ref = 1;
        $taskClosure = eval($code);
        new Task($taskClosure);
    }

    public function testChannelMake(): void
    {
        $channel = Channel::make('test_channel');
        $this->assertInstanceOf(Channel::class, $channel);
        $this->assertEquals('test_channel', $channel->getName());
        $this->assertEquals(Channel::CAPACITY_UNBOUNDED, $channel->getCapacity());
    }

    public function testChannelBounded(): void
    {
        $channel = Channel::bounded(5, 'bounded_channel');
        $this->assertEquals(5, $channel->getCapacity());
        $this->assertEquals('bounded_channel', $channel->getName());
    }

    public function testChannelBoundedInvalidCapacity(): void
    {
        $this->expectException(ParallelException::class);
        Channel::bounded(0);
    }

    public function testChannelSendRecv(): void
    {
        $channel = Channel::make();

        $sendFuture = $this->runtime->run(fn($args) => $args['channel']->send('hello'), ['channel' => $channel]);
        $sendFuture->wait();

        $this->assertFalse($channel->isEmpty());

        $recvFuture = $this->runtime->run(fn($args) => $args['channel']->recv(), ['channel' => $channel]);
        $this->assertEquals('hello', $recvFuture->get());
    }

    public function testChannelClose(): void
    {
        $channel = Channel::bounded(1);
        $channel->send('data');
        $channel->close();

        $this->assertFalse($channel->isEmpty());
    }

    public function testEventsCreation(): void
    {
        $events = new Events();
        $this->assertCount(0, $events);
    }

    public function testEventsAttachFuture(): void
    {
        $events = new Events();
        $future = $this->runtime->run(fn() => 42);

        $events->attachFuture('test_future', $future);
        $this->assertCount(1, $events);
        $this->assertTrue($events->has('test_future'));
    }

    public function testEventsAttachChannel(): void
    {
        $events = new Events();
        $channel = Channel::make('events_channel');

        $events->attachChannel('test_channel', $channel);
        $this->assertTrue($events->has('test_channel'));
    }

    public function testEventsCancel(): void
    {
        $events = new Events();
        $channel = Channel::make();

        $events->attachChannel('cancel_test', $channel);
        $this->assertCount(1, $events);

        $events->cancel('cancel_test');
        $this->assertFalse($events->has('cancel_test'));
    }

    public function testEventsClear(): void
    {
        $events = new Events();
        $channel1 = Channel::make('ch1');
        $channel2 = Channel::make('ch2');

        $events->attachChannel('ch1', $channel1);
        $events->attachChannel('ch2', $channel2);
        $this->assertCount(2, $events);

        $events->clear();
        $this->assertCount(0, $events);
    }

    public function testFutureGetId(): void
    {
        $future = $this->runtime->run(fn() => 42);
        $id = $future->getId();

        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    public function testRuntimeIsRunning(): void
    {
        $runtime = new Runtime();
        $this->assertFalse($runtime->isRunning());

        $future = $runtime->run(fn() => usleep(10000) . 'running');
        $this->assertTrue($runtime->isRunning());

        $future->wait();
        $runtime->close();
    }

    public function testExceptionWrapping(): void
    {
        $this->expectException(ParallelException::class);

        $runtime = new Runtime('/nonexistent/path.php');
    }

    public function testTaskWithClosure(): void
    {
        $addition = fn($args) => $args['x'] + $args['y'] + $args['z'];
        $future = $this->runtime->run($addition, ['x' => 1, 'y' => 2, 'z' => 3]);

        $this->assertEquals(6, $future->get());
    }

    public function testTaskWithStringOperations(): void
    {
        $future = $this->runtime->run(
            fn($args) => strtoupper($args['str']),
            ['str' => 'hello world']
        );

        $this->assertEquals('HELLO WORLD', $future->get());
    }

    public function testTaskWithJsonOperations(): void
    {
        $future = $this->runtime->run(
            fn($args) => json_decode($args['json'], true),
            ['json' => '{"key": "value", "num": 123}']
        );

        $result = $future->get();
        $this->assertEquals(['key' => 'value', 'num' => 123], $result);
    }

    public function testMultipleFuturesWait(): void
    {
        $futures = [];
        for ($i = 0; $i < 3; $i++) {
            $futures[] = $this->runtime->run(fn($args) => array_sum(range(1, $args['n'])), ['n' => 1000 + $i]);
        }

        foreach ($futures as $future) {
            $this->assertTrue($future->wait(5000));
        }
    }

    public function testMutexCreation(): void
    {
        $mutex = new Mutex();
        $this->assertFalse($mutex->isLocked());

        $locked = $mutex->lock();
        $this->assertTrue($locked);
        $this->assertTrue($mutex->isLocked());

        $mutex->unlock();
        $this->assertFalse($mutex->isLocked());
    }

    public function testMutexTryLock(): void
    {
        $mutex = new Mutex();

        $this->assertTrue($mutex->tryLock());
        $this->assertTrue($mutex->isLocked());

        $this->assertFalse($mutex->tryLock());
        $this->assertTrue($mutex->isLocked());

        $mutex->unlock();
        $this->assertFalse($mutex->isLocked());
    }

    public function testMutexWithLock(): void
    {
        $mutex = new Mutex();
        $result = $mutex->withLock(function() {
            return 'protected_value';
        });

        $this->assertEquals('protected_value', $result);
        $this->assertFalse($mutex->isLocked());
    }

    public function testSemaphoreCreation(): void
    {
        $semaphore = new Semaphore(3);
        $this->assertEquals(3, $semaphore->getCount());
    }

    public function testSemaphoreInvalidCount(): void
    {
        $this->expectException(ParallelException::class);
        new Semaphore(0);
    }

    public function testBarrierCreation(): void
    {
        $barrier = new Barrier(4);
        $this->assertEquals(4, $barrier->getCount());
    }

    public function testBarrierInvalidCount(): void
    {
        $this->expectException(ParallelException::class);
        new Barrier(0);
    }

    public function testCondCreation(): void
    {
        $cond = new Cond();
        $mutex = new Mutex();

        $this->assertTrue($mutex->lock());

        $result = $cond->wait($mutex, 100);
        $this->assertFalse($result);

        $mutex->unlock();
    }

    public function testCurlMultiCreation(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl 未安装');
        }

        $curlMulti = new CurlMulti();
        $this->assertEquals(0, $curlMulti->count());
    }

    public function testCurlMultiAddRequest(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl 未安装');
        }

        $curlMulti = new CurlMulti();
        $key = $curlMulti->add('https://httpbin.org/get', [], 'test');

        $this->assertEquals('test', $key);
        $this->assertEquals(1, $curlMulti->count());
    }

    public function testCurlMultiGetRequest(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl 未安装');
        }

        $curlMulti = new CurlMulti();
        $curlMulti->get('https://httpbin.org/get', [], 'test_get');

        $this->assertEquals(1, $curlMulti->count());
    }

    public function testCurlMultiPostRequest(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl 未安装');
        }

        $curlMulti = new CurlMulti();
        $curlMulti->post('https://httpbin.org/post', ['key' => 'value'], [], 'test_post');

        $this->assertEquals(1, $curlMulti->count());
    }

    public function testProducerConsumerPattern(): void
    {
        $channel = Channel::bounded(10);
        $items = [];

        $producer = function() use ($channel) {
            for ($i = 0; $i < 100; $i++) {
                $channel->send($i);
            }
            $channel->close();
        };

        $consumer = function() use ($channel, &$items) {
            $count = 0;
            while (!$channel->isEmpty()) {
                $item = $channel->recv();
                $items[] = $item;
                $count++;
            }
            return $count;
        };

        $this->runtime->run($producer);
        $future = $this->runtime->run($consumer);

        $processedCount = $future->get();

        $this->assertEquals(100, $processedCount);
        $this->assertCount(100, $items);
    }

    public function testParallelSum(): void
    {
        $futures = [];
        $chunkSize = 100000;

        for ($i = 0; $i < 4; $i++) {
            $start = $i * $chunkSize + 1;
            $end = ($i + 1) * $chunkSize;
            $futures[] = $this->runtime->run(
                fn($args) => array_sum(range($args['start'], $args['end'])),
                ['start' => $start, 'end' => $end]
            );
        }

        $total = 0;
        foreach ($futures as $future) {
            $total += $future->get();
        }

        $expected = array_sum(range(1, 400000));
        $this->assertEquals($expected, $total);
    }

    public function testChannelNonBlockingOperations(): void
    {
        $channel = Channel::bounded(1);
        $channel->send('first');

        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('通道已满');

        $channel->sendNonBlocking('second');
    }

    public function testTaskFromFileNotExists(): void
    {
        $this->expectException(ParallelException::class);
        Task::fromFile('/nonexistent/file.php');
    }
}
