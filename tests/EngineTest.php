<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Engine\ProcessEngine;
use Kode\Parallel\Engine\SyncEngine;
use Kode\Parallel\Exception\ParallelException;
use PHPUnit\Framework\TestCase;

/**
 * 引擎抽象层测试（无需 ext-parallel）
 */
final class EngineTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::setDefault(null);
        parent::tearDown();
    }

    public function testAvailableEnginesAlwaysContainSync(): void
    {
        $available = EngineFactory::available();

        $this->assertArrayHasKey(SyncEngine::NAME, $available);
        $this->assertTrue($available[SyncEngine::NAME]);
        $this->assertSame(extension_loaded('parallel'), $available[ParallelEngine::NAME]);
    }

    public function testDetectReturnsHighestPriorityAvailableEngine(): void
    {
        $detected = EngineFactory::detect();

        $this->assertContains($detected, EngineFactory::PRIORITY);
        $this->assertTrue(EngineFactory::isSupported($detected));

        $forced = getenv(EngineFactory::ENV_KEY);

        if (is_string($forced) && trim($forced) !== '') {
            $this->assertSame(strtolower(trim($forced)), $detected, '环境变量应覆盖自动探测');

            return;
        }

        if (ParallelEngine::supported()) {
            $this->assertSame(ParallelEngine::NAME, $detected);
        } elseif (ProcessEngine::supported()) {
            $this->assertSame(ProcessEngine::NAME, $detected);
        } else {
            $this->assertSame(SyncEngine::NAME, $detected);
        }
    }

    public function testSetDefaultForcesEngine(): void
    {
        EngineFactory::setDefault(SyncEngine::NAME);

        $this->assertSame(SyncEngine::NAME, EngineFactory::detect());
        $this->assertSame(SyncEngine::NAME, EngineFactory::create()->name());
    }

    public function testUnknownEngineThrows(): void
    {
        $this->expectException(ParallelException::class);
        EngineFactory::create('quantum');
    }

    public function testSyncEngineRunsInline(): void
    {
        $engine = new SyncEngine();

        $future = $engine->submit(static fn(array $args): int => $args['a'] + $args['b'], ['a' => 2, 'b' => 3]);

        $this->assertSame(SyncEngine::NAME, $engine->name());
        $this->assertFalse($engine->isConcurrent());
        $this->assertTrue($future->done());
        $this->assertSame(5, $future->get());
        $this->assertSame(1, $engine->getExecutedCount());

        $engine->close();
    }

    public function testSyncEngineCapturesException(): void
    {
        $engine = new SyncEngine();
        $future = $engine->submit(static function (array $args): never {
            throw new \RuntimeException('boom');
        });

        $this->assertTrue($future->done());
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessageMatches('/boom/');
        $future->get();
    }

    public function testProcessEngineExecutesInChildProcess(): void
    {
        $this->skipWithoutProcessEngine();

        $engine = new ProcessEngine();
        $parentPid = getmypid();

        $future = $engine->submit(static fn(array $args): array => [
            'pid' => getmypid(),
            'sum' => array_sum($args['numbers']),
        ], ['numbers' => [1, 2, 3, 4]]);

        $result = $future->get();

        $this->assertTrue($engine->isConcurrent());
        $this->assertSame(10, $result['sum']);
        $this->assertNotSame($parentPid, $result['pid'], '任务应在子进程中执行');

        $engine->close();
    }

    public function testProcessEnginePropagatesTaskException(): void
    {
        $this->skipWithoutProcessEngine();

        $engine = new ProcessEngine();
        $future = $engine->submit(static function (array $args): never {
            throw new \LogicException('子进程异常', 42);
        });

        try {
            $future->get();
            $this->fail('应抛出 ParallelException');
        } catch (ParallelException $e) {
            $this->assertStringContainsString('子进程异常', $e->getMessage());
            $this->assertSame(42, $e->getCode());
            $this->assertSame(\LogicException::class, $e->getContext()['exception']);
        }

        $engine->close();
    }

    public function testProcessEngineRunsTasksConcurrently(): void
    {
        $this->skipWithoutProcessEngine();

        $engine = new ProcessEngine();
        $start = hrtime(true);

        $futures = [];
        for ($i = 0; $i < 4; $i++) {
            $futures[] = $engine->submit(static function (array $args): int {
                usleep(200_000);

                return $args['i'];
            }, ['i' => $i]);
        }

        foreach ($futures as $index => $future) {
            $this->assertSame($index, $future->get());
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $this->assertLessThan(600, $elapsedMs, '4 个 200ms 任务并行耗时应远小于串行的 800ms');

        $engine->close();
    }

    public function testProcessFutureCancelKillsChild(): void
    {
        $this->skipWithoutProcessEngine();

        $engine = new ProcessEngine();
        $future = $engine->submit(static function (array $args): int {
            sleep(30);

            return 1;
        });

        $this->assertFalse($future->done());
        $this->assertTrue($future->cancel());
        $this->assertTrue($future->isCancelled());
        $this->assertTrue($future->done());

        $engine->close();
    }

    public function testProcessFutureWaitTimeout(): void
    {
        $this->skipWithoutProcessEngine();

        $engine = new ProcessEngine();
        $future = $engine->submit(static function (array $args): int {
            usleep(400_000);

            return 7;
        });

        $this->assertFalse($future->wait(50));
        $this->assertTrue($future->wait(2000));
        $this->assertSame(7, $future->get());

        $engine->close();
    }

    public function testProcessEngineHandlesLargePayload(): void
    {
        $this->skipWithoutProcessEngine();

        $engine = new ProcessEngine();
        $future = $engine->submit(static fn(array $args): string => str_repeat('x', $args['size']), ['size' => 1_000_000]);

        $this->assertSame(1_000_000, strlen($future->get()), '超过 socket 缓冲区的大结果应完整回传');

        $engine->close();
    }

    private function skipWithoutProcessEngine(): void
    {
        if (!ProcessEngine::supported()) {
            $this->markTestSkipped('当前环境不支持 pcntl 多进程引擎');
        }
    }
}
