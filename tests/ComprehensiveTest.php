<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Future\Future;
use Kode\Parallel\Exception\ParallelException;
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
        $this->assertEquals(42, $future->get());
        $this->assertTrue($future->done());
    }

    public function testTaskWithArguments(): void
    {
        $future = $this->runtime->run(
            fn($args) => $args['a'] + $args['b'],
            ['a' => 10, 'b' => 20]
        );

        $this->assertEquals(30, $future->get());
        $this->assertTrue($future->done());
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

        $future->wait(1000);
        $this->assertNotNull($future->getOrNull());
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

        $taskClosure = static function () {
            yield 1;
        };
        new Task($taskClosure);
    }

    public function testTaskValidationReference(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('引用');

        $ref = 1;
        $taskClosure = function () use (&$ref) {
            return $ref;
        };
        new Task($taskClosure);
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
        $this->assertFalse($runtime->isRunning());
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

    public function testTaskFromFileNotExists(): void
    {
        $this->expectException(ParallelException::class);
        Task::fromFile('/nonexistent/file.php');
    }
}
