<?php

declare(strict_types=1);

/**
 * ThreadPool 基准：非阻塞派发 vs WorkerPool 槽位阻塞，以及预排大量任务的能力
 *
 * 用法（必须用 ZTS 构建的 PHP，否则 parallel 引擎不可用）：
 *   /opt/homebrew/opt/php@8.3-zts/bin/php -d memory_limit=1G benchmarks/bench_thread_pool.php
 */

use Kode\Parallel\Parallel;
use Kode\Parallel\Pool\ThreadPool;
use Kode\Parallel\Pool\WorkerPool;

require __DIR__ . '/../vendor/autoload.php';

if (!Parallel::isAvailable()) {
    fwrite(STDERR, "需要 ZTS + ext-parallel，当前后端: " . Parallel::backend() . "\n");
    exit(1);
}

$cpus = Parallel::info()['cpus'];
$threads = min($cpus, 8);

echo "=== ThreadPool 基准（ZTS, " . Parallel::backend() . ", {$threads} 线程, " . $cpus . " 核）===\n\n";

// ---- 1. 非阻塞提交：预排大量任务，submit 本身几乎零成本 ----
$n = 20000;
$pool = new ThreadPool($threads);
$t0 = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $pool->submit(static fn(array $a): int => $a['n'] + 1, ['n' => $i]);
}
$submitMs = (hrtime(true) - $t0) / 1_000_000;
$queued = $pool->getQueueLength();
$pool->wait();
$runMs = 0; // wait 内部已含
$pool->close();
echo "1) 非阻塞提交 {$n} 个任务：submit 总耗时 " . number_format($submitMs, 1) . " ms"
    . "（平均 " . number_format($submitMs / $n * 1000, 3) . " µs/submit），提交后队列积压 {$queued} 个\n";

// ---- 2. ThreadPool.map vs WorkerPool.map vs 串行 ----
function benchMap(int $n, int $threads, string $kind): array
{
    $worker = static function (int $x): int {
        // 中等 CPU 任务
        $s = 0;
        for ($i = 0; $i < 2000; $i++) {
            $s += ($x * $i) % 97;
        }

        return $s;
    };

    if ($kind === 'serial') {
        $t0 = hrtime(true);
        $r = [];
        foreach (range(1, $n) as $k => $x) {
            $r[$k] = $worker($x);
        }
        $ms = (hrtime(true) - $t0) / 1_000_000;

        return [$ms, $r];
    }

    if ($kind === 'thread') {
        $pool = new ThreadPool($threads);
        $t0 = hrtime(true);
        $r = $pool->map(range(1, $n), $worker);
        $ms = (hrtime(true) - $t0) / 1_000_000;
        $pool->close();

        return [$ms, $r];
    }

    $pool = new WorkerPool($threads);
    $t0 = hrtime(true);
    $r = $pool->map(range(1, $n), $worker);
    $ms = (hrtime(true) - $t0) / 1_000_000;
    $pool->close();

    return [$ms, $r];
}

foreach ([2000 => '轻-中'] as $n => $label) {
    [$serialMs, $serialR] = benchMap($n, $threads, 'serial');
    [$threadMs, $threadR] = benchMap($n, $threads, 'thread');
    [$workerMs, $workerR] = benchMap($n, $threads, 'worker');

    echo "\n2) {$label}任务（{$n} 个，中等 CPU）：\n";
    echo "   串行        : " . number_format($serialMs, 1) . " ms\n";
    echo "   ThreadPool  : " . number_format($threadMs, 1) . " ms  ≈ " . number_format($serialMs / $threadMs, 2) . "×\n";
    echo "   WorkerPool  : " . number_format($workerMs, 1) . " ms  ≈ " . number_format($serialMs / $workerMs, 2) . "×\n";
    // 正确性核对
    assert($threadR === $serialR);
    assert($workerR === $serialR);
}

echo "\n说明：ThreadPool 吞吐低于 WorkerPool（后者有自动批量合并，逐任务序列化开销更大）；"
    . "ThreadPool 的核心价值是非阻塞提交与常驻线程复用，而非原始吞吐，见 USE_CASES.md。\n";
