<?php
/**
 * kode/parallel 引擎无关原语 + 多引擎 实测基准
 *
 * 运行环境: 普通 PHP CLI（非 ZTS，无 ext-parallel）
 * 目的: 在“对标 Swoole 6.2 最新版”的语境下，给出 kode/parallel 真实可复现的吞吐数据，
 *       证明引擎无关同步原语在 stock PHP 上即可工作（Swoole 原生线程必须 ZTS + --enable-swoole-thread）。
 *
 * 用法: php benchmarks/bench_concurrency.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Runtime\Runtime;

echo "============================================\n";
echo "  kode/parallel 引擎无关基准 (stock PHP)\n";
echo "  PHP: " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'YES' : 'NO') . ")\n";
echo "  ext-parallel: " . (extension_loaded('parallel') ? 'LOADED' : 'no') . "\n";
echo "  ext-pcntl: " . (extension_loaded('pcntl') ? 'LOADED' : 'no') . "\n";
echo "  default engine: " . EngineFactory::detect() . "\n";
echo "  日期: " . date('Y-m-d') . "\n";
echo "============================================\n\n";

/**
 * 计时辅助
 */
function bench(string $label, int $ops, callable $fn): float
{
    $start = hrtime(true);
    $fn();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $throughput = $ops / ($elapsedMs / 1000);
    printf("  %-34s %8.2f ms  (%s ops/s)\n",
        $label,
        $elapsedMs,
        number_format($throughput, 0)
    );
    return $elapsedMs;
}

// ---------------------------------------------------------------------------
// 1) 进程引擎任务扇出吞吐（fork + 执行 + 取结果）
// ---------------------------------------------------------------------------
echo "【1】进程引擎任务扇出（fork 模型，CPU 友好型任务）\n";
$count = 200;
$rt = new Runtime(null, 'process');
bench("  submit+get x$count (空任务)", $count, function () use ($rt, $count) {
    $futs = [];
    for ($i = 0; $i < $count; $i++) {
        $futs[] = $rt->run(static fn() => 1);
    }
    foreach ($futs as $f) {
        $f->get();
    }
});
$rt->close();

// CPU 密集型并行加速
echo "  并行映射（每个任务做轻量计算）\n";
$count = 100;
$rt = new Runtime(null, 'process');
bench("  parallel_map x$count", $count, function () use ($rt, $count) {
    $futs = [];
    for ($i = 0; $i < $count; $i++) {
        $futs[] = $rt->run(static function (array $a) {
            $n = $a[0];
            $s = 0;
            for ($k = 0; $k < 5000; $k++) {
                $s += $k * $n;
            }
            return $s;
        }, [$i]);
    }
    foreach ($futs as $f) {
        $f->get();
    }
});
$rt->close();

// ---------------------------------------------------------------------------
// 2) 引擎无关 Channel（进程内有界队列）
// ---------------------------------------------------------------------------
echo "\n【2】Concurrency\\Channel（进程内 SplQueue 封装）\n";
$ch = new Channel(0); // 无界
$n = 100_000;
bench("  send+recv x$n", $n * 2, function () use ($ch, $n) {
    for ($i = 0; $i < $n; $i++) {
        $ch->send($i);
    }
    for ($i = 0; $i < $n; $i++) {
        $ch->recv();
    }
});

// ---------------------------------------------------------------------------
// 3) 引擎无关 Lock 开销（withLock 包裹自增）
// ---------------------------------------------------------------------------
echo "\n【3】Concurrency\\Lock（flock 进程间互斥）\n";
$lock = Lock::named('bench_lock_' . uniqid());
$n = 20_000;
$shared = (object)['v' => 0];
bench("  withLock 自增 x$n", $n, function () use ($lock, $shared, $n) {
    for ($i = 0; $i < $n; $i++) {
        $lock->withLock(function () use ($shared) {
            $shared->v++;
        });
    }
});

// ---------------------------------------------------------------------------
// 4) 引擎无关 Atomic（进程内单实例复用）
// ---------------------------------------------------------------------------
echo "\n【4】Concurrency\\Atomic（单进程内复用实例）\n";
$atomic = new Atomic(0);
$n = 200_000;
bench("  inc x$n (同进程)", $n, function () use ($atomic, $n) {
    for ($i = 0; $i < $n; $i++) {
        $atomic->inc();
    }
});

// ---------------------------------------------------------------------------
// 5) 跨进程 Atomic（命名共享，6 子进程各 inc N 次）
// ---------------------------------------------------------------------------
echo "\n【5】跨进程命名 Atomic（真实 fork 子进程共享计数）\n";
$name = 'bench_xproc_atomic_' . uniqid();
$dataFile = sys_get_temp_dir() . '/kode_atomic_' . md5($name);
@unlink($dataFile);
$procs = 6;
$perProc = 5_000;
$total = $procs * $perProc;
$rt = new Runtime(null, 'process');
$start = hrtime(true);
$futs = [];
for ($i = 0; $i < $procs; $i++) {
    $futs[] = $rt->run(static function (array $a) {
        $a2 = Atomic::named(0, $a['name']);
        for ($k = 0; $k < $a['per']; $k++) {
            $a2->inc();
        }
        return true;
    }, ['name' => $name, 'per' => $perProc]);
}
foreach ($futs as $f) {
    $f->get();
}
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$final = (int) @file_get_contents($dataFile);
@unlink($dataFile);
printf("  cross-process inc x%s  最终结果=%s (期望 %s)  %8.2f ms  (%s ops/s) %s\n",
    number_format($total), number_format($final), number_format($total),
    $elapsedMs, number_format($total / ($elapsedMs / 1000)),
    $final === $total ? 'OK' : '!!! 丢失更新');
$rt->close();

// ---------------------------------------------------------------------------
// 6) 引擎无关 Barrier（跨进程 4 方会合）
// ---------------------------------------------------------------------------
echo "\n【6】Concurrency\\Barrier（跨进程 N 方会合）\n";
$name = 'bench_barrier_' . uniqid();
$parties = 4;
$rounds = 50;
$rt = new Runtime(null, 'process');
$start = hrtime(true);
for ($r = 0; $r < $rounds; $r++) {
    $futs = [];
    for ($i = 0; $i < $parties; $i++) {
        $futs[] = $rt->run(static function (array $a) {
            $b = Barrier::named($a['parties'], $a['name']);
            $b->wait();
            return getmypid();
        }, ['parties' => $parties, 'name' => $name]);
    }
    foreach ($futs as $f) {
        $f->get();
    }
}
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
printf("  barrier 回合 x%s (每回合 %d 方)  %8.2f ms\n", $rounds, $parties, $elapsedMs);
$rt->close();

echo "\n============================================\n";
echo "  说明: 以上为 stock PHP（非 ZTS）实测；\n";
echo "  Swoole 6.2 原生线程需 ZTS + --enable-swoole-thread 方能运行。\n";
echo "============================================\n";
