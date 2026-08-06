<?php
/**
 * 「同类对比」基线 #1：裸 pcntl_fork + stream_socket_pair（不含任何库）
 *
 * 目的：测量 kode/parallel 进程引擎所依赖的底层原语（fork + 管道 + serialize）的
 *       真实开销「地板」。kode 的 process 引擎在此之上只叠加了极薄的 Future 封装，
 *       因此本脚本的数字 ≈ kode bench_concurrency.php 中「进程引擎 submit+get」的理论下限。
 *
 * 与 benchmarks/bench_concurrency.php / bench_swoole.php / bench_ext_parallel.php
 * 同口径（均为「派发 N 个空任务并回收结果」），可并排比较。
 *
 * 运行前提：类 UNIX + pcntl 扩展（本仓库默认引擎即 process，普通环境即可跑）。
 * 用法: php benchmarks/bench_pcntl.php
 */

declare(strict_types=1);

if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
    echo "跳过: 需要 pcntl_fork + stream_socket_pair（类 UNIX）。\n";
    exit(0);
}

$rows = [];

echo "============================================\n";
echo "  裸 pcntl_fork 基线（无第三方库）\n";
echo "  PHP: " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'YES' : 'NO') . ")\n";
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

/**
 * 派发 $count 个空任务：fork 子进程 → 执行 → 经管道回传 1 → 父进程回收。
 * 与 kode 进程引擎的 submit+get 一一对应。
 */
function rawForkRoundtrip(int $count): void
{
    $pids = [];
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            fwrite($pair[1], serialize(1));
            fclose($pair[1]);
            posix_kill(posix_getpid(), 9);
            exit(0);
        }
        fclose($pair[1]);
        $pids[] = $pid;
        $handles[] = $pair[0];
    }
    foreach ($handles as $h) {
        stream_get_contents($h);
        fclose($h);
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

echo "【裸 fork + 管道 roundtrip（空任务）】\n";
$count = 200;
$rows['裸 pcntl fork+pipe roundtrip x' . $count] = bench("  fork+get x$count", $count, static fn () => rawForkRoundtrip($count));

// 带点计算量，更接近真实任务
echo "\n【裸 fork + 轻量计算 roundtrip】\n";
$count = 100;
$rows['裸 pcntl fork+计算 x' . $count] = bench("  fork+calc x$count", $count, static function () use ($count) {
    $pids = [];
    $handles = [];
    for ($i = 0; $i < $count; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            $s = 0;
            for ($k = 0; $k < 5000; $k++) {
                $s += $k * $i;
            }
            fwrite($pair[1], serialize($s));
            fclose($pair[1]);
            posix_kill(posix_getpid(), 9);
            exit(0);
        }
        fclose($pair[1]);
        $pids[] = $pid;
        $handles[] = $pair[0];
    }
    foreach ($handles as $h) {
        stream_get_contents($h);
        fclose($h);
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
});

echo "\n============================================\n";
echo "  汇总（ops/s，越高越好）\n";
echo "============================================\n";
foreach ($rows as $label => $ops) {
    printf("  %-40s %14s ops/s\n", $label, number_format($ops, 0));
}
echo "\n  对比: 同环境下的 kode 进程引擎见 benchmarks/bench_concurrency.php（[1] 进程引擎任务扇出）。\n";
echo "  若两者接近，说明 kode 在 fork 之上几乎零开销；kode 的额外价值在 Future 组合子/引擎降级/跨进程原语。\n";
echo "============================================\n";
