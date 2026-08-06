<?php
/**
 * kode/parallel 的「同类对比」基准 —— 对标 Swoole 6.2 原生线程原语
 *
 * 目的: 在【同一口径】下，把 Swoole 6.2 的 Thread 同步原语与 kode/parallel 的引擎无关
 *       原语做可对齐的吞吐对比。两个脚本输出格式一致（总结均按 ops/s），便于并排比较。
 *
 * 运行前提（硬性）:
 *   - PHP 必须启用 ZTS（线程安全）；可用 `php -i | grep "Thread Safety"` 确认；
 *   - 必须安装启用线程的 Swoole 6 扩展：`--enable-swoole-thread` 编译，且 pthreads 关闭。
 *   否则 `class Swoole\Thread` 不存在，本脚本会优雅跳过并给出说明（退出码 0）。
 *
 * 架构差异（对标的关键点，详见 docs/SWOOLE_COMPARISON.md）:
 *   - Swoole Thread 是 *进程内* 多线程，原语共享的是进程共享内存（Thread\Atomic/
 *     Thread\Map/Queue 在创建它们的进程内可见，无法跨进程/跨机器）。
 *   - kode/parallel 原语引擎无关：stock PHP（非 ZTS）即可运行；命名原语跨进程/跨机器
 *     通过文件锁 + 数据文件实现，可水平扩展到集群（见 CLUSTER.md）。
 *
 * 用法: php benchmarks/bench_swoole.php
 */

declare(strict_types=1);

$rows = [];

echo "============================================\n";
echo "  Swoole 6.2 原生线程基准 (ZTS + Thread)\n";

if (!extension_loaded('swoole')) {
    echo "  跳过: ext-swoole 未加载。请在 ZTS + --enable-swoole-thread 构建上运行。\n";
    echo "============================================\n";
    exit(0);
}

if (!class_exists(\Swoole\Thread::class)) {
    echo "  跳过: class Swoole\\Thread 不存在 —— 当前 Swoole 非线程构建。\n";
    echo "  需以 --enable-swoole-thread 重新编译（且禁用 pthreads）后运行。\n";
    echo "============================================\n";
    exit(0);
}

echo "  ext-swoole: " . (new ReflectionExtension('swoole'))->getVersion() . "\n";
echo "  ZTS: " . (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'YES' : 'NO') . "\n";
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
// 1) 原生线程任务派发吞吐（创建线程 + 执行 + join）
//    对标 kode 进程引擎 submit+get
// ---------------------------------------------------------------------------
echo "【1】Swoole\\Thread 任务派发（线程模型）\n";
$count = 200;
$rows['Thread 创建+join (空任务)'] = bench("  create+join x$count", $count, function () use ($count) {
    $threads = [];
    for ($i = 0; $i < $count; $i++) {
        $threads[] = new \Swoole\Thread(static fn() => 1);
    }
    foreach ($threads as $t) {
        $t->join();
    }
});

// ---------------------------------------------------------------------------
// 2) Thread\Atomic（进程内共享内存原子计数器）
//    对标 kode Atomic：共享内存 >> 文件锁，本应是 Swoole 的强项
// ---------------------------------------------------------------------------
echo "\n【2】Swoole\\Thread\\Atomic（进程内共享内存）\n";
$atomic = new \Swoole\Thread\Atomic(0);
$n = 5_000_000;
$rows['Thread\\Atomic inc x' . number_format($n)] = bench("  inc x$n", $n, function () use ($atomic, $n) {
    // 单线程内串行自增（多线程共享需在线程闭包内做）
    for ($i = 0; $i < $n; $i++) {
        $atomic->add(1);
    }
});
echo "    最终值=" . $atomic->get() . "（期望 " . number_format($n) . "）\n";

// 多线程并发自增（共享内存，真正发挥线程优势）
$procs = 6;
$perProc = 5_000;
$total = $procs * $perProc;
$shared = new \Swoole\Thread\Atomic(0);
$start = hrtime(true);
$threads = [];
for ($i = 0; $i < $procs; $i++) {
    $threads[] = new \Swoole\Thread(static function (\Swoole\Thread\Atomic $a) {
        global $perProc;
        for ($k = 0; $k < $perProc; $k++) {
            $a->add(1);
        }
    }, $shared);
}
foreach ($threads as $t) {
    $t->join();
}
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
$opsPerSec = $elapsedMs > 0 ? $total / ($elapsedMs / 1000) : 0;
printf("  并发 inc x%s (6 线程)  最终=%s (期望 %s)  %9.2f ms  %12s ops/s  %s\n",
    number_format($total), number_format($shared->get()), number_format($total),
    $elapsedMs, number_format($opsPerSec),
    $shared->get() === $total ? 'OK' : '!!! 丢失');
$rows['Thread\\Atomic 并发 inc x' . number_format($total)] = $opsPerSec;

// ---------------------------------------------------------------------------
// 3) Thread\Lock（互斥锁）+ 受保护自增
//    对标 kode Lock withLock
// ---------------------------------------------------------------------------
echo "\n【3】Swoole\\Thread\\Lock（互斥锁）\n";
$lock = new \Swoole\Thread\Lock();
$guard = new \Swoole\Thread\Atomic(0);
$n = 20_000;
$rows['Thread\\Lock 受保护自增 x' . number_format($n)] = bench("  lock+inc x$n", $n, function () use ($lock, $guard, $n) {
    for ($i = 0; $i < $n; $i++) {
        $lock->lock();
        try {
            $guard->add(1);
        } finally {
            $lock->unlock();
        }
    }
});
echo "    最终值=" . $guard->get() . "（期望 " . number_format($n) . "）\n";

// ---------------------------------------------------------------------------
// 4) Thread\Queue（进程内共享队列，Channel 对标物）
// ---------------------------------------------------------------------------
echo "\n【4】Swoole\\Thread\\Queue（进程内队列）\n";
$q = new \Swoole\Thread\Queue();
$m = 200_000;
$rows['Thread\\Queue push+pop x' . number_format($m)] = bench("  push+pop x$m", $m * 2, function () use ($q, $m) {
    for ($i = 0; $i < $m; $i++) {
        $q->push($i);
    }
    for ($i = 0; $i < $m; $i++) {
        $q->pop();
    }
});

// ---------------------------------------------------------------------------
// 5) Thread\Barrier（N 方会合）
// ---------------------------------------------------------------------------
echo "\n【5】Swoole\\Thread\\Barrier（N 方会合）\n";
$parties = 4;
$rounds = 50;
$start = hrtime(true);
for ($r = 0; $r < $rounds; $r++) {
    $barrier = new \Swoole\Thread\Barrier($parties);
    $threads = [];
    for ($i = 0; $i < $parties; $i++) {
        $threads[] = new \Swoole\Thread(static function (\Swoole\Thread\Barrier $b) {
            $b->wait();
        }, $barrier);
    }
    foreach ($threads as $t) {
        $t->join();
    }
}
$elapsedMs = (hrtime(true) - $start) / 1_000_000;
printf("  barrier 回合 x%s (每回合 %d 方)  %9.2f ms\n", $rounds, $parties, $elapsedMs);
$rows['Thread\\Barrier ' . $rounds . ' 回合'] = $rounds / ($elapsedMs / 1000);

// ---------------------------------------------------------------------------
// 汇总
// ---------------------------------------------------------------------------
echo "\n============================================\n";
echo "  汇总（ops/s，越高越好）\n";
echo "============================================\n";
foreach ($rows as $label => $ops) {
    printf("  %-40s %14s ops/s\n", $label, number_format($ops, 0));
}
echo "\n  说明: 以上为 ZTS + Swoole 6 线程构建实测。\n";
echo "  与 benchmarks/bench_concurrency.php 的 kode 数据并排对比时，请注意架构差异：\n";
echo "  Swoole 原语是进程内共享内存（无法跨进程/跨机器）；kode 原语引擎无关、可跨进程/跨机器。\n";
echo "============================================\n";
