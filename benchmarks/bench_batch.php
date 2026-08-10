<?php
/**
 * 批量合并自对比基准（mapBatch vs map）
 *
 * 目的：验证「把高频短任务批量合并为一次引擎提交」确实降低 ext-parallel 序列化开销。
 * 运行：php benchmarks/bench_batch.php [函数名] [并发] [批次]
 *   - 默认 ZTS + ext-parallel 下运行；无 ext-parallel 时用 sync 引擎（仍可对比串行差异）
 */

declare(strict_types=1);

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;

require_once __DIR__ . '/../vendor/autoload.php';

$php = PHP_VERSION;
$zts = defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE === true ? 'YES' : 'NO';
$engine = EngineFactory::detect();
$cpus = (new \Kode\Parallel\Util\Sys())->cpuCount();

echo "========================================\n";
echo "    kode/parallel 批量合并自对比 (v1.13.0)\n";
echo "    PHP {$php} | ZTS: {$zts} | engine: {$engine} | cpus: {$cpus}\n";
echo "========================================\n\n";

$concurrency = (int) ($argv[2] ?? max(1, $cpus));
$batchSize = (int) ($argv[3] ?? 128);

// 三种负载（与 bench_compare 口径一致）
$workloads = [
    'trivial' => ['task' => static fn(int $i): int => $i, 'N' => 40_000],
    'light'   => ['task' => static function (int $i): int {
        $s = 0;
        for ($k = 0; $k < 2000; $k++) {
            $s += $k * $i;
        }
        return $s;
    }, 'N' => 20_000],
    'medium'  => ['task' => static function (int $i): int {
        $s = 0.0;
        for ($k = 0; $k < 200000; $k++) {
            $s += sqrt((float) $k) * sin((float) $i);
        }
        return (int) $s;
    }, 'N' => 2_000],
];

function measure(string $label, int $N, callable $fn): float
{
    $start = hrtime(true);
    $fn();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $ops = $elapsedMs > 0 ? ($N / ($elapsedMs / 1000)) : 0;
    printf("  %-34s %9.2f ms   %12s ops/s\n", $label, $elapsedMs, number_format($ops));
    return $ops;
}

foreach ($workloads as $wname => $def) {
    $task = $def['task'];
    $N = $def['N'];

    echo "\n【负载: {$wname}】  N={$N} 并发={$concurrency} 批次={$batchSize}\n";

    $single = measure("  single  (顺序, 1 线程)", $N, static function () use ($N, $task) {
        $c = 0;
        for ($i = 0; $i < $N; $i++) {
            $c += $task($i);
        }
        if ($c === PHP_INT_MIN) {
            echo $c;
        }
    });

    if ($engine === 'sync') {
        // sync 引擎无序列化开销，批量无收益，仅展示基线
        measure("  map     (逐条提交)", $N, static function () use ($N, $task) {
            $rt = new Runtime(null, 'sync');
            $futs = [];
            for ($i = 0; $i < $N; $i++) {
                $futs[] = $rt->run($task, [$i]);
            }
            foreach ($futs as $f) {
                $f->get();
            }
            $rt->close();
        });
        measure("  mapBatch (打包 x{$batchSize})", $N, static function () use ($N, $task, $batchSize) {
            $rt = new Runtime(null, 'sync');
            $futs = [];
            foreach (array_chunk(range(0, $N - 1), $batchSize) as $chunk) {
                $futs[] = $rt->run(static function (array $a) use ($task) {
                    $out = [];
                    foreach ($a['items'] as $i) {
                        $out[] = $task($i);
                    }
                    return $out;
                }, [['items' => $chunk]]);
            }
            foreach ($futs as $f) {
                $f->get();
            }
            $rt->close();
        });
        echo "  （sync 引擎无序列化，多线程/批量对比见 ZTS + ext-parallel 环境）\n";
        continue;
    }

    $perTask = measure("  map     (逐条提交)", $N, static function () use ($N, $task, $concurrency) {
        $pool = new WorkerPool($concurrency);
        $pool->map(range(0, $N - 1), static fn(int $i): int => $task($i));
        $pool->close();
    });

    $batched = measure("  mapBatch (打包 x{$batchSize})", $N, static function () use ($N, $task, $batchSize, $concurrency) {
        $pool = new WorkerPool($concurrency);
        $pool->mapBatch(range(0, $N - 1), static fn(int $i): int => $task($i), $batchSize);
        $pool->close();
    });

    $factor = $perTask > 0 ? $batched / $perTask : 0;
    printf("  >>> 批量 vs 逐条提速: %.1fx\n", $factor);
    if ($factor > 1.5) {
        echo "  ✅ 批量合并显著降低序列化开销\n";
    } elseif ($factor >= 0.9) {
        echo "  ➖ 该负载下批量收益有限（任务越重、并行度越主导）\n";
    } else {
        echo "  ⚠️ 批量反而变慢（批次过大或并发不足，可调小批次/提高并发）\n";
    }
}

echo "\n提示：负载越轻（trivial/light），批量收益越大；medium 这类 CPU 密集任务主要由并发度决定。\n";
