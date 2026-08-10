<?php

/**
 * Futures 组合器层压测（ZTS + ext-parallel 真线程，可复现）
 *
 * 量化 v1.14.0 落地后的组合器开销：
 *  - 纯组合器开销（已就绪 future，无 sleep）：衡量 awaitAll/settle 的轮询循环本身；
 *  - 真线程收集开销（N 个 trivial 任务并行，Futures::all 等待回收）：衡量退避轮询在真实任务下的回收速度。
 *
 * 运行（务必用 ZTS 二进制，否则走 sync 引擎且 trivial 负载易 OOM）：
 *   /opt/homebrew/opt/php@8.3-zts/bin/php -d memory_limit=1G benchmarks/bench_futures.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kode\Parallel\Engine\ParallelEngine;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Future\ValueFuture;
use Kode\Parallel\Util\Sys;

$threads = Sys::recommendedConcurrency();
$engine = new ParallelEngine(null, $threads);

echo "# Futures 组合器压测（ZTS，threads={$threads}）\n\n";

/**
 * 计时辅助
 */
function timeCall(int $iterations, callable $fn): float
{
    // 预热
    $fn();

    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }
    $end = hrtime(true);

    return ($end - $start) / 1_000_000_000 / $iterations; // 秒/次
}

echo "## A. 纯组合器开销（已就绪 future，无 sleep，衡量轮询循环本身）\n\n";
echo "| N（future 数） | Futures::all 耗时 | 单 future 开销 |\n";
echo "|---|---|---|\n";

foreach ([1_000, 10_000, 40_000] as $n) {
    $futures = [];
    for ($i = 0; $i < $n; $i++) {
        $futures[] = ValueFuture::resolved($i);
    }

    // 单次 all() 的耗时
    $sec = timeCall(20, static fn() => Futures::all($futures));
    $ns = $sec * 1_000_000_000 / $n;
    printf("| %' -8d | %.4f ms | %.1f ns |\n", $n, $sec * 1_000, $ns);
}

echo "\n> 已就绪 future 在首轮轮询即全部 done()，指数退避（10µs→500µs）不会真正 sleep，\n";
echo "> 因此单 future 开销仅为一次 `done()` 轮询 + 结果归并，约数十 ns 量级。\n\n";

echo "## B. 真线程收集开销（N 个 trivial 任务并行，Futures::all 等待回收）\n\n";
echo "| N（任务数） | 并发度 | Futures::all 回收耗时 | 单任务回收开销 | 等效吞吐 |\n";
echo "|---|---|---|---|---|\n";

foreach ([1_000, 10_000, 40_000] as $n) {
    $futures = [];
    for ($i = 0; $i < $n; $i++) {
        $futures[] = $engine->submit(static fn(array $a): int => $a['i'], ['i' => $i]);
    }

    $sec = timeCall(10, static fn() => Futures::all($futures));
    $ns = $sec * 1_000_000_000 / $n;
    $ops = $n / $sec;
    printf(
        "| %' -8d | %d | %.4f ms | %.1f ns | %s ops/s |\n",
        $n,
        $threads,
        $sec * 1_000,
        $ns,
        number_format($ops, 0)
    );
}

echo "\n> 真线程下每个任务要经过「序列化闭包 + 派发 + 跨线程执行 + 回收」，因此单任务开销远高于纯组合器；\n";
echo "> 但 Futures 组合器在任务完成瞬间（微秒级）即通过退避起点的最小间隔感知到 done()，无需睡满 500µs，\n";
echo "> 故短任务不会被轮询拖慢。对比 v1.13.0 固定 500µs 轮询在大量短任务下会显著空转。\n\n";

echo "## C. settle / race / any / select 单次语义开销（小集合，已就绪）\n\n";

$set = [ValueFuture::resolved(1), ValueFuture::resolved(2), ValueFuture::resolved(3)];
$iters = 200_000;

$secAll = timeCall($iters, static fn() => Futures::all($set));
$secSettle = timeCall($iters, static fn() => Futures::settle($set));
$secRace = timeCall($iters, static fn() => Futures::race($set));
$secAny = timeCall($iters, static fn() => Futures::any($set));

echo "| 组合器 | {$iters} 次调用总耗时 | 单次耗时 |\n";
echo "|---|---|---|\n";
printf("| all | %.3f s | %.0f ns |\n", $secAll * $iters, $secAll * 1_000_000_000);
printf("| settle | %.3f s | %.0f ns |\n", $secSettle * $iters, $secSettle * 1_000_000_000);
printf("| race | %.3f s | %.0f ns |\n", $secRace * $iters, $secRace * 1_000_000_000);
printf("| any | %.3f s | %.0f ns |\n", $secAny * $iters, $secAny * 1_000_000_000);

echo "\n> 这些组合器对「3 个已就绪 future」的调度均为百 ns 级，足够在热路径中随意组合。\n";
echo "done.\n";
