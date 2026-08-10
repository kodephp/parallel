<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\EngineInterface;
use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Engine\SyncEngine;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\ValueFuture;
use PHPUnit\Framework\TestCase;

/**
 * 引擎抽象层测试
 *
 * 本库内置 parallel（真线程）与 sync（回退）两个引擎；
 * 多进程等外部后端通过 EngineFactory::register() 接入。
 */
final class EngineTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::setDefault(null);

        foreach (EngineFactory::registered() as $name) {
            EngineFactory::unregister($name);
        }

        parent::tearDown();
    }

    public function testBuiltinEnginesAreThreadOriented(): void
    {
        $available = EngineFactory::available();

        $this->assertSame([ParallelEngine::NAME, SyncEngine::NAME], EngineFactory::names());
        $this->assertTrue($available[SyncEngine::NAME]);
        $this->assertSame(extension_loaded('parallel'), $available[ParallelEngine::NAME]);
    }

    public function testDetectReturnsHighestPriorityAvailableEngine(): void
    {
        $detected = EngineFactory::detect();

        $this->assertContains($detected, EngineFactory::names());
        $this->assertTrue(EngineFactory::isSupported($detected));

        $forced = getenv(EngineFactory::ENV_KEY);

        if (is_string($forced) && trim($forced) !== '') {
            $this->assertSame(strtolower(trim($forced)), $detected, '环境变量应覆盖自动探测');

            return;
        }

        $this->assertSame(
            ParallelEngine::supported() ? ParallelEngine::NAME : SyncEngine::NAME,
            $detected
        );
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

    public function testParallelEngineExecutesInSeparateThread(): void
    {
        $this->skipWithoutParallelEngine();

        $engine = new ParallelEngine();

        $future = $engine->submit(static fn(array $args): array => [
            'sum' => array_sum($args['numbers']),
            'zts' => ZEND_THREAD_SAFE,
        ], ['numbers' => [1, 2, 3, 4]]);

        $result = $future->get();

        $this->assertTrue($engine->isConcurrent());
        $this->assertSame(ParallelEngine::NAME, $engine->name());
        $this->assertSame(10, $result['sum']);
        $this->assertTrue($result['zts'], '任务应运行在 ZTS 线程中');
        $this->assertSame(1, $engine->getSubmittedCount());

        $engine->close();
    }

    public function testParallelEnginePropagatesTaskException(): void
    {
        $this->skipWithoutParallelEngine();

        $engine = new ParallelEngine();
        $future = $engine->submit(static function (array $args): never {
            throw new \LogicException('线程内异常');
        });

        try {
            $future->get();
            $this->fail('应抛出 ParallelException');
        } catch (ParallelException $e) {
            $this->assertStringContainsString('线程内异常', $e->getMessage());
            $this->assertSame(\LogicException::class, $e->getContext()['exception']);
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }

        $engine->close();
    }

    public function testSubmitAfterCloseThrows(): void
    {
        $this->skipWithoutParallelEngine();

        $engine = new ParallelEngine();
        $engine->close();

        $this->expectException(ParallelException::class);
        $engine->submit(static fn(array $args): int => 1);
    }

    public function testRegisterExternalEngineJoinsDetection(): void
    {
        StubExternalEngine::$available = true;

        EngineFactory::register(
            'stub',
            static fn(?string $bootstrap): EngineInterface => new StubExternalEngine(),
            static fn(): bool => StubExternalEngine::$available,
        );

        $this->assertContains('stub', EngineFactory::names());
        $this->assertSame(['stub'], EngineFactory::registered());
        $this->assertTrue(EngineFactory::isSupported('stub'));
        $this->assertSame('stub', EngineFactory::create('stub')->name());

        // 优先级：parallel(100) > stub(50) > sync(0)
        $this->assertSame(
            [ParallelEngine::NAME, 'stub', SyncEngine::NAME],
            EngineFactory::names()
        );
    }

    public function testExternalEngineIsChosenWhenThreadsUnavailable(): void
    {
        StubExternalEngine::$available = true;

        EngineFactory::register(
            'stub',
            static fn(?string $bootstrap): EngineInterface => new StubExternalEngine(),
            static fn(): bool => StubExternalEngine::$available,
        );

        // 真线程不可用时，外部引擎应优先于 sync 回退被选中
        if (!ParallelEngine::supported()) {
            $this->assertSame('stub', EngineFactory::detect());
        }

        StubExternalEngine::$available = false;
        $this->assertFalse(EngineFactory::isSupported('stub'));
    }

    public function testExternalEngineExecutesThroughRuntime(): void
    {
        EngineFactory::register(
            'stub',
            static fn(?string $bootstrap): EngineInterface => new StubExternalEngine(),
            static fn(): bool => true,
        );

        $runtime = new \Kode\Parallel\Runtime\Runtime(null, 'stub');

        $this->assertSame('stub', $runtime->getEngineName());
        $this->assertTrue($runtime->isConcurrent());
        $this->assertSame(6, $runtime->run(static fn(array $args): int => $args['a'] * 2, ['a' => 3])->get());

        $runtime->close();
    }

    public function testUnregisterRemovesExternalEngine(): void
    {
        EngineFactory::register(
            'stub',
            static fn(?string $bootstrap): EngineInterface => new StubExternalEngine(),
            static fn(): bool => true,
        );

        $this->assertTrue(EngineFactory::unregister('stub'));
        $this->assertFalse(EngineFactory::unregister('stub'), '重复注销应返回 false');
        $this->assertNotContains('stub', EngineFactory::names());
    }

    public function testCannotOverrideBuiltinEngine(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessageMatches('/不能覆盖内置引擎/');

        EngineFactory::register(
            SyncEngine::NAME,
            static fn(?string $bootstrap): EngineInterface => new StubExternalEngine(),
            static fn(): bool => true,
        );
    }

    public function testRegisterRejectsEmptyName(): void
    {
        $this->expectException(ParallelException::class);

        EngineFactory::register(
            '   ',
            static fn(?string $bootstrap): EngineInterface => new StubExternalEngine(),
            static fn(): bool => true,
        );
    }

    private function skipWithoutParallelEngine(): void
    {
        if (!ParallelEngine::supported()) {
            $this->markTestSkipped('当前环境未加载 ext-parallel（需 ZTS 构建）');
        }
    }
}

/**
 * 用于验证外部引擎注册机制的桩实现
 *
 * 真实场景下由 kode/process 等多进程组件提供。
 */
final class StubExternalEngine implements EngineInterface
{
    public static bool $available = true;

    public const string NAME = 'stub';

    private bool $closed = false;

    #[\Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function supported(): bool
    {
        return self::$available;
    }

    #[\Override]
    public function isConcurrent(): bool
    {
        return true;
    }

    #[\Override]
    public function submit(\Closure $task, array $args = []): FutureInterface
    {
        if ($this->closed) {
            throw new ParallelException('引擎已关闭，无法提交任务');
        }

        try {
            return ValueFuture::resolved($task($args));
        } catch (\Throwable $e) {
            return ValueFuture::rejected($e);
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }
}
