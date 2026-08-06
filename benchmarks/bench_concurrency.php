<?php
/**
 * kode/parallel 引擎无关原语 + 多引擎 实测基准
 *
 * 运行环境: 普通 PHP CLI（非 ZTS，无 ext-parallel）
 * 目的: 在“对标 Swoole 6.2 最新版”的语境下，给出 kode/parallel 真实可复现的吞吐数据，
 *       证明引擎无关同步原语在 stock PHP 上即可工作（Swoole 原生线程必须 ZTS + --enable-swoole-thread）。
 *
 * 用法: php benchmarks/bench_concurrency.php
 * 同类对比: php benchmarks/bench_swoole.php   （需 ZTS + Swoole 6 线程构建）
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Concurrency\Semaphore;
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Future\Futures;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;

$rows = []; // 汇总表

echo "============================================\n";
echo "  kode/parallel 引擎无关基准 (stock PHP)\n";
echo "  PHP: " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'YES' : 'NO') . ")\n";
echo "  ext-parallel: " . (extension_loaded('parallel') ? 'LOADED' : 'no') . "\n";
echo "  ext-pcntl: " . (extension_loaded('pcntl') ? 'LOADED' : 'no') . "\n";
echo "  ext-sockets: " . (extension_loaded('sockets') ? 'LOADED' : 'no') . "\n";
$installedVer = static function (string $pkg): string {
    if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($pkg)) {
        return (string) \Composer\InstalledVersions::getPrettyVersion($pkg);
    }
    return '?';
};
echo "  kode 栈: context " . $installedVer('kode/context')
    . " / facade " . $installedVer('kode/facade')
    . " / fibers " . $installedVer('kode/fibers') . "\n";
echo "  default engine: " . EngineFactory::detect() . "\n";
echo "  日期: " . date('Y-m-d') . "\n";
echo "============================================\n\n";

/**
 * 计时辅助：返回 ops/s
 */
function bench(string $label, int $ops, callable $fn): float
{
    $start = hrtime(true);
    $fn();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $opsPerSec = $elapsedMs > 0 ? $ops / ($elapsedMs / 1000) : 0;
    printf("  %-40s %9.2f ms  %12s ops/s\n", $label, $elapsedMs, number_format($opsPerSec, 0));
    return $opsPerSec;
}

// ---------------------------------------------------------------------------
// 1) 进程引擎任务扇出吞吐（fork + 执行 + 取结果）
// ---------------------------------------------------------------------------
echo "【1】进程引擎任务扇出（fork 模型）\n";
$count = 200;
$rt = new Runtime(null, 'process');
$rows['进程引擎 submit+get (空任务)'] = bench("  submit+get x$count", $count, function () use ($rt, $count) {
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
$count = 100;
$rt = new Runtime(null, 'process');
$rows['并行映射 parallel_map (轻量计算)'] = bench("  parallel_map x$count", $count, function () use ($rt, $count) {
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
// 2) WorkerPool 吞吐（引擎无关工作池）
// ---------------------------------------------------------------------------
echo "\n【2】WorkerPool 工作池（并发 8）\n";
$count = 200;
$pool = new WorkerPool(concurrency: 8, engine: 'process');
$rows['WorkerPool.map x' . $count] = bench("  map x$count (平方)", $count, function () use ($pool, $count) {
    $pool->map(range(1, $count), static fn(int $n): int => $n * $n);
});
$pool->close();

// ---------------------------------------------------------------------------
// 3) Future 组合器开销（Futures::all 聚合 N 个 future）
// ---------------------------------------------------------------------------
echo "\n【3】Future 组合器 Futures::all（聚合 N 个 future）\n";
$count = 200;
$rt = new Runtime(null, 'process');
$rows['Futures::all 聚合 x' . $count] = bench("  all x$count", $count, function () use ($rt, $count) {
    $futs = [];
    for ($i = 0; $i < $count; $i++) {
        $futs[] = $rt->run(static fn() => $i);
    }
    Futures::all($futs);
});
$rt->close();

// ---------------------------------------------------------------------------
// 4) 引擎无关 Channel（进程内有界队列）
// ---------------------------------------------------------------------------
echo "\n【4】Concurrency\\Channel（进程内）\n";
$ch = new Channel(0); // 无界
$n = 200_000;
$rows['Channel send+recv x' . number_format($n)] = bench("  send+recv x$n", $n * 2, function () use ($ch, $n) {
    for ($i = 0; $i < $n; $i++) {
        $ch->send($i);
    }
    for ($i = 0; $i < $n; $i++) {
        $ch->recv();
    }
});

// ---------------------------------------------------------------------------
// 5) 引擎无关 Lock 开销（withLock 包裹自增，命名跨进程锁）
// ---------------------------------------------------------------------------
echo "\n【5】Concurrency\\Lock（flock 进程间互斥）\n";
$lock = Lock::named('bench_lock_' . uniqid());
$n = 20_000;
$shared = (object)['v' => 0];
$rows['Lock withLock 自增 x' . number_format($n)] = bench("  withLock 自增 x$n", $n, function () use ($lock, $shared, $n) {
    for ($i = 0; $i < $n; $i++) {
        $lock->withLock(function () use ($shared) {
            $shared->v++;
        });
    }
});

// ---------------------------------------------------------------------------
// 6) 引擎无关 Atomic —— 进程内快路径（纯内存，零 I/O）
// ---------------------------------------------------------------------------
echo "\n【6】Concurrency\\Atomic（进程内快路径，纯内存）\n";
$atomic = new Atomic(0);
$n = 5_000_000;
$rows['Atomic 进程内 inc x' . number_format($n)] = bench("  inc x$n", $n, function () use ($atomic, $n) {
    for ($i = 0; $i < $n; $i++) {
        $atomic->inc();
    }
});
echo "    最终值=" . $atomic->get() . "（期望 " . number_format($n) . "）\n";

// ---------------------------------------------------------------------------
// 7) 跨进程 Atomic（命名共享，6 子进程各 inc N 次）—— 正确性 + 吞吐
// ---------------------------------------------------------------------------
echo "\n【7】跨进程命名 Atomic（真实 fork 子进程共享计数）\n";
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
$opsPerSec = $elapsedMs > 0 ? $total / ($elapsedMs / 1000) : 0;
printf("  跨进程 inc x%s  最终=%s (期望 %s)  %9.2f ms  %12s ops/s  %s\n",
    number_format($total), number_format($final), number_format($total),
    $elapsedMs, number_format($opsPerSec),
    $final === $total ? 'OK 零丢失' : '!!! 丢失更新');
$rows['Atomic 跨进程 inc x' . number_format($total)] = $opsPerSec;
$rt->close();

// ---------------------------------------------------------------------------
// 8) 引擎无关 Barrier（跨进程 N 方会合）
// ---------------------------------------------------------------------------
echo "\n【8】Concurrency\\Barrier（跨进程 N 方会合）\n";
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
printf("  barrier 回合 x%s (每回合 %d 方)  %9.2f ms\n", $rounds, $parties, $elapsedMs);
$rows['Barrier 跨进程 ' . $rounds . ' 回合'] = $rounds / ($elapsedMs / 1000);
$rt->close();

// ---------------------------------------------------------------------------
// 9) 引擎无关 Semaphore（计数信号量，进程内快路径）
// ---------------------------------------------------------------------------
echo "\n【9】Concurrency\\Semaphore（进程内快路径，纯内存）\n";
$sem = new Semaphore(1_000_000);
$n = 5_000_000;
$rows['Semaphore 进程内 acquire+release x' . number_format($n)] = bench("  acquire+release x$n", $n, function () use ($sem, $n) {
    for ($i = 0; $i < $n; $i++) {
        $sem->acquire(1);
        $sem->release(1);
    }
});
echo "    最终可用=" . $sem->getAvailable() . "（期望 1,000,000）\n";

// ---------------------------------------------------------------------------
// 汇总
// ---------------------------------------------------------------------------
echo "\n============================================\n";
echo "  汇总（ops/s，越高越好）\n";
echo "============================================\n";
foreach ($rows as $label => $ops) {
    printf("  %-40s %14s ops/s\n", $label, number_format($ops, 0));
}
echo "\n  说明: 以上为 stock PHP（非 ZTS）实测；\n";
echo "  Swoole 6.2 原生线程需 ZTS + --enable-swoole-thread 方能运行。\n";
echo "  同类对比请在本机 ZTS + Swoole 线程构建上运行: php benchmarks/bench_swoole.php\n";
echo "============================================\n";
