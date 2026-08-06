<?php
/**
 * 「同类对比」基线 #2：ext-parallel 原生真线程（ZTS + ext-parallel）
 *
 * 目的：在「真线程」这一档上给出参考数字，与 kode/parallel（process 引擎，非 ZTS）和
 *       Swoole 6 线程（bench_swoole.php）做三角对标。ext-parallel 与 Swoole 线程都要求 ZTS 构建，
 *       而 kode 在普通非 ZTS PHP 上即可运行——这是三者最关键的部署差异。
 *
 * 运行前提：PHP 必须启用 ZTS 且加载 ext-parallel（本机若无则优雅跳过，退出码 0）。
 * 用法: php benchmarks/bench_ext_parallel.php
 */

declare(strict_types=1);

$rows = [];

echo "============================================\n";
echo "  ext-parallel 原生真线程基线 (ZTS)\n";

if (!extension_loaded('parallel')) {
    echo "  跳过: ext-parallel 未加载。请在 ZTS + ext-parallel 构建上运行。\n";
    echo "============================================\n";
    exit(0);
}

if (!defined('ZEND_THREAD_SAFE') || !ZEND_THREAD_SAFE) {
    echo "  跳过: 当前 PHP 非 ZTS，ext-parallel 无法使用。\n";
    echo "============================================\n";
    exit(0);
}

echo "  ext-parallel: " . (new ReflectionExtension('parallel'))->getVersion() . "\n";
echo "  ZTS: YES\n";
echo "  日期: " . date('Y-m-d') . "\n";
echo "============================================\n\n";

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
// 1) 真线程任务派发吞吐（parallel\Runtime run + future 回收）
// ---------------------------------------------------------------------------
echo "【1】parallel\\Runtime 任务派发（真线程）\n";
$count = 200;
$rows['parallel run+get x' . $count] = bench("  run+get x$count", $count, static function () use ($count) {
    $runtime = new \parallel\Runtime();
    $futures = [];
    for ($i = 0; $i < $count; $i++) {
        $futures[] = $runtime->run(static fn () => 1);
    }
    foreach ($futures as $f) {
        $f->value();
    }
    $runtime->close();
});

// ---------------------------------------------------------------------------
// 2) 轻量计算（对标 kode parallel_map）
// ---------------------------------------------------------------------------
echo "\n【2】parallel\\Runtime 轻量计算\n";
$count = 100;
$rows['parallel run+calc x' . $count] = bench("  run+calc x$count", $count, static function () use ($count) {
    $runtime = new \parallel\Runtime();
    $futures = [];
    for ($i = 0; $i < $count; $i++) {
        $futures[] = $runtime->run(static function (int $n) {
            $s = 0;
            for ($k = 0; $k < 5000; $k++) {
                $s += $k * $n;
            }
            return $s;
        }, $i);
    }
    foreach ($futures as $f) {
        $f->value();
    }
    $runtime->close();
});

// ---------------------------------------------------------------------------
// 3) 共享原子计数（parallel\Sync\Atomic，进程内共享内存）
//    对标 kode Atomic 进程内快路径
// ---------------------------------------------------------------------------
echo "\n【3】parallel\\Sync\\Atomic（进程内共享内存）\n";
$atomic = new \parallel\Sync\Atomic(0);
$n = 5_000_000;
$rows['parallel\\Atomic inc x' . number_format($n)] = bench("  inc x$n", $n, static function () use ($atomic, $n) {
    for ($i = 0; $i < $n; $i++) {
        $atomic->inc();
    }
});
echo "    最终值=" . $atomic->get() . "（期望 " . number_format($n) . "）\n";

// 多线程并发自增
$procs = 6;
$perProc = 5_000;
$total = $procs * $perProc;
$shared = new \parallel\Sync\Atomic(0);
$start = hrtime(true);
$runtime = new \parallel\Runtime();
$futures = [];
for ($i = 0; $i < $procs; $i++) {
    $futures[] = $runtime->run(static function (\parallel\Sync\Atomic $a) {
        global $perProc;
        for ($k = 0; $k < $perProc; $k++) {
            $a->inc();
        }
    }, $shared);
}
foreach ($futures as $f) {
    $f->value();
}
$runtime->close();
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$opsPerSec = $elapsedMs > 0 ? $total / ($elapsedMs / 1000) : 0;
printf("  并发 inc x%s (6 线程)  最终=%s (期望 %s)  %9.2f ms  %12s ops/s  %s\n",
    number_format($total), number_format($shared->get()), number_format($total),
    $elapsedMs, number_format($opsPerSec),
    $shared->get() === $total ? 'OK' : '!!! 丢失');
$rows['parallel\\Atomic 并发 inc x' . number_format($total)] = $opsPerSec;

// ---------------------------------------------------------------------------
// 汇总
// ---------------------------------------------------------------------------
echo "\n============================================\n";
echo "  汇总（ops/s，越高越好）\n";
echo "============================================\n";
foreach ($rows as $label => $ops) {
    printf("  %-40s %14s ops/s\n", $label, number_format($ops, 0));
}
echo "\n  三角对标: kode(bench_concurrency) vs Swoole(bench_swoole) vs ext-parallel(本脚本)。\n";
echo "  kode 在「非 ZTS 普通 PHP」上即可跑；ext-parallel/Swoole 线程均强制 ZTS。\n";
echo "============================================\n";
