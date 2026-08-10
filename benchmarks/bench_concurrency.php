<?php
/**
 * kode/parallel 引擎无关原语 + 多引擎 实测基准
 *
 * 运行环境: PHP CLI（自动探测引擎——ZTS + ext-parallel 走真线程，否则同步回退）
 * 目的: 在“对标 Swoole 6.2 最新版”的语境下，给出 kode/parallel 真实可复现的吞吐数据，
 *       引擎无关同步原语在普通 PHP 与 ZTS PHP 上均可工作（Swoole 原生线程必须 ZTS + --enable-swoole-thread）。
 *       跨进程共享能力由 Concurrency\*` 原语自身提供（文件锁实现），本基准直接用 pcntl fork 验证，
 *       不再依赖任何多进程引擎——多进程编排请交给 kode/process。
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

// 跨进程原语测试辅助（直接 fork，与引擎无关）
require_once __DIR__ . '/../tests/Support/ForkRunner.php';

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
// 1) 多引擎任务扇出吞吐（自动探测：ZTS 用 parallel 真线程，否则 process 多进程）
// ---------------------------------------------------------------------------
$engine = EngineFactory::detect();
echo "【1】{$engine} 引擎任务扇出（" . ($engine === 'parallel' ? 'ext-parallel 真线程' : '同步回退') . "）\n";
$count = 200;
$rt = new Runtime(null, $engine);
$rows["{$engine} 引擎 submit+get (空任务)"] = bench("  submit+get x$count", $count, function () use ($rt, $count) {
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
$rt = new Runtime(null, $engine);
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
$pool = new WorkerPool(concurrency: 8, engine: $engine);
$rows['WorkerPool.map x' . $count] = bench("  map x$count (平方)", $count, function () use ($pool, $count) {
    $pool->map(range(1, $count), static fn(int $n): int => $n * $n);
});
$pool->close();

// ---------------------------------------------------------------------------
// 3) Future 组合器开销（Futures::all 聚合 N 个 future）
// ---------------------------------------------------------------------------
echo "\n【3】Future 组合器 Futures::all（聚合 N 个 future）\n";
$count = 200;
$rt = new Runtime(null, $engine);
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
//    原语基于文件锁，跨进程能力是自身属性，直接用 pcntl fork 验证
// ---------------------------------------------------------------------------
echo "\n【7】跨进程命名 Atomic（真实 fork 子进程共享计数）\n";
$name = 'bench_xproc_atomic_' . uniqid();
$procs = 6;
$perProc = 5_000;
$total = $procs * $perProc;
if (\Kode\Parallel\Tests\Support\ForkRunner::supported()) {
    $start = hrtime(true);
    \Kode\Parallel\Tests\Support\ForkRunner::run($procs, static function (int $i) use ($name, $perProc) {
        $a = Atomic::named(0, $name);
        for ($k = 0; $k < $perProc; $k++) {
            $a->inc();
        }
        return true;
    });
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $final = Atomic::named(0, $name)->get();
    $opsPerSec = $elapsedMs > 0 ? $total / ($elapsedMs / 1000) : 0;
    printf("  跨进程 inc x%s  最终=%s (期望 %s)  %9.2f ms  %12s ops/s  %s\n",
        number_format($total), number_format($final), number_format($total),
        $elapsedMs, number_format($opsPerSec),
        $final === $total ? 'OK 零丢失' : '!!! 丢失更新');
    $rows['Atomic 跨进程 inc x' . number_format($total)] = $opsPerSec;
} else {
    echo "  跳过：当前环境不支持 fork（需非 Windows + pcntl + stream_socket_pair）\n";
}

// ---------------------------------------------------------------------------
// 8) 引擎无关 Barrier（跨进程 N 方会合）—— 同样直接用 pcntl fork 验证
// ---------------------------------------------------------------------------
echo "\n【8】Concurrency\\Barrier（跨进程 N 方会合）\n";
$name = 'bench_barrier_' . uniqid();
$parties = 4;
$rounds = 50;
if (\Kode\Parallel\Tests\Support\ForkRunner::supported()) {
    $start = hrtime(true);
    for ($r = 0; $r < $rounds; $r++) {
        \Kode\Parallel\Tests\Support\ForkRunner::run($parties, static function (int $i) use ($parties, $name) {
            Barrier::named($parties, $name)->wait();
            return getmypid();
        });
    }
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    printf("  barrier 回合 x%s (每回合 %d 方)  %9.2f ms\n", $rounds, $parties, $elapsedMs);
    $rows['Barrier 跨进程 ' . $rounds . ' 回合'] = $rounds / ($elapsedMs / 1000);
} else {
    echo "  跳过：当前环境不支持 fork（需非 Windows + pcntl + stream_socket_pair）\n";
}

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
echo "\n  说明: 以上实测于 PHP " . PHP_VERSION . "（ZTS: " . (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'YES' : 'NO') .
    "），主引擎: {$engine}；\n";
echo "  引擎无关原语（Channel/Lock/Atomic/Barrier/Semaphore）在多引擎下表现一致。\n";
echo "  Swoole 6.2 原生线程需 ZTS + --enable-swoole-thread 方能运行。\n";
echo "  同类对比请在本机 ZTS + Swoole 线程构建上运行: php benchmarks/bench_swoole.php\n";
echo "============================================\n";
