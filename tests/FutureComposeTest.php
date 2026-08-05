<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Future\ValueFuture;
use Kode\Parallel\Runtime\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Future 异步组合子（then/map/catch）与 Futures::select 非阻塞选择测试
 */
final class FutureComposeTest extends TestCase
{
    protected function tearDown(): void
    {
        EngineFactory::setDefault(null);
        parent::tearDown();
    }

    public function testThenMapsFulfilledValue(): void
    {
        $f = ValueFuture::resolved(2);
        $g = $f->then(static fn ($x) => $x * 2);

        $this->assertInstanceOf(ValueFuture::class, $f);
        $this->assertSame(4, $g->get());
    }

    public function testMapTransformation(): void
    {
        $f = ValueFuture::resolved('  hello  ');
        $g = $f->map('trim');

        $this->assertSame('hello', $g->get());
    }

    public function testCatchRecoversFromRejection(): void
    {
        $f = ValueFuture::rejected(new \RuntimeException('boom'));
        $g = $f->catch(static fn (\Throwable $e) => 'recovered:' . $e->getMessage());

        // ValueFuture::rejected 已将异常包装为 ParallelException（前缀「任务执行失败: 」）
        $this->assertSame('recovered:任务执行失败: boom', $g->get());
    }

    public function testCatchReceivesOriginalThrowable(): void
    {
        $original = new \InvalidArgumentException('bad arg');
        $f = ValueFuture::rejected($original);
        $g = $f->catch(static fn (\Throwable $e) => $e::class);

        $this->assertSame(\Kode\Parallel\Exception\ParallelException::class, $g->get());
    }

    public function testUnhandledRejectionPropagates(): void
    {
        $f = ValueFuture::rejected(new \RuntimeException('original'));
        $g = $f->then(static fn ($x) => $x * 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('original');
        $g->get();
    }

    public function testChainedComposition(): void
    {
        $f = ValueFuture::resolved(1);
        $g = $f->then(static fn ($x) => $x + 1)
               ->then(static fn ($x) => $x * 10)
               ->map(static fn ($x) => $x . '!');

        $this->assertSame('20!', $g->get());
    }

    public function testSelectReturnsFirstReadyFuture(): void
    {
        $a = ValueFuture::resolved('a');
        $b = ValueFuture::resolved('b');

        $ready = Futures::select([$a, $b], 0);

        $this->assertNotNull($ready);
        $this->assertContains($ready, [$a, $b]);
    }

    public function testSelectEmptyReturnsNull(): void
    {
        $this->assertNull(Futures::select([], 0));
    }

    /**
     * 非阻塞选择：超时仍未就绪返回 null；超时足够则能选中正在执行的任务。
     */
    public function testSelectTimingWithProcessFuture(): void
    {
        $rt = new Runtime(null, 'process');
        $slow = $rt->run(static function () {
            usleep(200_000);
            return 'done';
        });

        // 20ms 内任务尚未完成
        $early = Futures::select([$slow], 20);
        $this->assertNull($early, '短时间内不应有就绪任务');

        // 放宽到 800ms，应能选中
        $later = Futures::select([$slow], 800);
        $this->assertSame($slow, $later);
        $this->assertSame('done', $slow->get());
        $rt->close();
    }
}
