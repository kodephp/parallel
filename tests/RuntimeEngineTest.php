<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Engine\SyncEngine;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Util\Installation;
use Kode\Parallel\Util\Sys;
use PHPUnit\Framework\TestCase;

use function Kode\Parallel\all;
use function Kode\Parallel\await;
use function Kode\Parallel\cpus;
use function Kode\Parallel\engine;
use function Kode\Parallel\map;
use function Kode\Parallel\map_settled;
use function Kode\Parallel\runtime;

/**
 * Runtime 多引擎与全局助手函数测试
 */
final class RuntimeEngineTest extends TestCase
{
    public function testRuntimeWorksWithoutParallelExtension(): void
    {
        $runtime = new Runtime();

        $this->assertFalse($runtime->isClosed());
        $this->assertContains($runtime->getEngineName(), ['parallel', 'process', 'sync']);

        $future = $runtime->run(static fn(array $args): int => $args['a'] * $args['b'], ['a' => 6, 'b' => 7]);

        $this->assertInstanceOf(FutureInterface::class, $future);
        $this->assertSame(42, $future->get());
        $this->assertSame(1, $runtime->getTaskCount());

        $runtime->close();
        $this->assertTrue($runtime->isClosed());
    }

    public function testRuntimeAcceptsTaskObject(): void
    {
        $runtime = new Runtime(null, SyncEngine::NAME);

        $future = $runtime->run(Task::from(static fn(array $args): string => strtoupper($args['s'])), ['s' => 'kode']);

        $this->assertSame('KODE', $future->get());

        $runtime->close();
    }

    public function testRunAllReturnsFutureForEachTask(): void
    {
        $runtime = new Runtime(null, SyncEngine::NAME);

        $futures = $runtime->runAll([
            'x' => static fn(array $args): int => 1,
            'y' => static fn(array $args): int => 2,
        ]);

        $this->assertSame(['x' => 1, 'y' => 2], all($futures));
        $this->assertSame(2, $runtime->getTaskCount());

        $runtime->close();
    }

    public function testClosedRuntimeRejectsRun(): void
    {
        $runtime = new Runtime(null, SyncEngine::NAME);
        $runtime->close();

        $this->expectException(ParallelException::class);
        $runtime->run(static fn(array $args): int => 1);
    }

    public function testMissingBootstrapFileThrows(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('引导文件不存在');

        new Runtime('/tmp/kode-parallel-not-exists-' . bin2hex(random_bytes(4)) . '.php');
    }

    public function testRuntimeHelperCreatesIndependentInstances(): void
    {
        $a = runtime(null, SyncEngine::NAME);
        $b = runtime(null, SyncEngine::NAME);

        $this->assertNotSame($a, $b);

        $a->close();
        $b->close();
    }

    public function testAwaitHelperTimesOut(): void
    {
        if (!ParallelEngine::supported()) {
            $this->markTestSkipped('当前环境未加载 ext-parallel（需 ZTS 构建）');
        }

        $runtime = new Runtime(null, ParallelEngine::NAME);
        $future = $runtime->run(static function (array $args): int {
            sleep(10);

            return 1;
        });

        try {
            await($future, 50);
            $this->fail('应因超时抛出异常');
        } catch (ParallelException $e) {
            $this->assertStringContainsString('超时', $e->getMessage());
        } finally {
            $future->cancel();
            $runtime->close();
        }
    }

    public function testMapHelper(): void
    {
        $this->assertSame([1, 4, 9], array_values(map([1, 2, 3], static fn(int $n): int => $n * $n)));
    }

    public function testMapSettledHelper(): void
    {
        $results = map_settled([1, 2], static function (int $n): int {
            if ($n === 1) {
                throw new \RuntimeException('nope');
            }

            return $n;
        });

        $this->assertSame('rejected', $results[0]['status']);
        $this->assertSame('fulfilled', $results[1]['status']);
    }

    public function testEngineAndCpuHelpers(): void
    {
        $this->assertContains(engine(), ['parallel', 'process', 'sync']);
        $this->assertGreaterThanOrEqual(1, cpus());
        $this->assertSame(cpus(), Sys::cpuCount());
    }

    public function testInstallationReport(): void
    {
        Installation::check();
        $info = Installation::getInfo();

        $this->assertTrue($info['php_ok']);
        $this->assertSame('8.3.0', $info['min_php_version']);
        $packageVersion = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true)['version'];
        $this->assertSame($packageVersion, $info['kode_parallel_version']);
        $this->assertArrayHasKey('sync', $info['engines']);
        $this->assertStringContainsString('kode/parallel 环境诊断', Installation::report());
    }

    public function testAssertExtensionThrowsWhenMissing(): void
    {
        if (extension_loaded('parallel')) {
            Installation::assertExtension();
            $this->assertTrue(Installation::isAvailable());

            return;
        }

        $this->expectException(ParallelException::class);
        Installation::assertExtension();
    }

    public function testSysInfoSnapshot(): void
    {
        $info = Sys::info();

        $this->assertSame(PHP_VERSION, $info['php_version']);
        $this->assertSame(PHP_OS_FAMILY, $info['os_family']);
        $this->assertGreaterThanOrEqual(1, $info['cpu_count']);
        $this->assertGreaterThanOrEqual(2, Sys::recommendedConcurrency());
    }
}
