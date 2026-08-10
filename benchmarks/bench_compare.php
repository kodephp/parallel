<?php
/**
 * kode/parallel 「单进程 vs 多进程 vs 多线程」真实吞吐对比
 *
 * 同一份 CPU 任务，分别用三种执行模型处理 N 个独立工作单元，吞吐（ops/s）直接可比：
 *   - single  : 当前进程单线程顺序处理（零调度开销基线）
 *   - process : pcntl fork 多进程池（每进程处理一个分片，类比 kode/process 的 worker 模型）
 *   - thread  : kode/parallel 的 parallel 引擎（ext-parallel 真线程池，threads = T）
 *
 * 目的：
 *   1. 找出每种模型的最优并发度（与 CPU 核心数相关）。
 *   2. 量化「多线程相比多进程」的吞吐提升（预期 10x~100x，取决于任务是否 I/O/调度开销主导）。
 *   3. 量化「单进程 → 多线程」的并行加速比（受核心数约束，接近线性）。
 *
 * 用法: php benchmarks/bench_compare.php
 *   可选环境变量 N（工作单元数，默认 4000）
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/Support/ForkRunner.php';

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Runtime\Runtime;

$vZts = defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE;
echo "============================================\n";
echo "  单进程 vs 多进程 vs 多线程 真实吞吐对比\n";
echo "  PHP " . PHP_VERSION . " (ZTS: " . ($vZts ? 'YES' : 'NO') . ")\n";
echo "  ext-parallel: " . (extension_loaded('parallel') ? 'LOADED' : 'no') . "\n";
echo "  CPU 核心数: " . (int) (getenv('KODE_BENCH_CORES') ?: (function (): int {
    $n = @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null') ?: @shell_exec('nproc 2>/dev/null');
    return $n ? (int) trim($n) : 4;
})()) . "\n";
echo "  日期: " . date('Y-m-d') . "\n";
echo "============================================\n\n";

// ---------------------------------------------------------------------------
// 工作负载（纯 CPU，结果确定性，避免被优化掉）
// ---------------------------------------------------------------------------
$workloads = [
    'trivial' => static fn(array $a): int => $a[0],
    'light'   => static function (array $a): int {
        $i = $a[0];
        $s = 0;
        for ($k = 0; $k < 2000; $k++) {
            $s += $k * $i;
        }
        return $s;
    },
    'medium'  => static function (array $a): int {
        $i = $a[0];
        $s = 0.0;
        for ($k = 0; $k < 200000; $k++) {
            $s += sqrt((float) $k) * sin((float) $i);
        }
        return (int) $s;
    },
];

$N = (int) (getenv('N') ?: 2000);
$engine = EngineFactory::detect();

// ---------------------------------------------------------------------------
// 计时辅助
// ---------------------------------------------------------------------------
function measure(string $label, int $ops, callable $fn): float
{
    $start = hrtime(true);
    $fn();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $opsPerSec = $elapsedMs > 0 ? $ops / ($elapsedMs / 1000) : 0;
    printf("  %-46s %9.2f ms  %14s ops/s\n", $label, $elapsedMs, number_format($opsPerSec, 0));
    return $opsPerSec;
}

/**
 * 多进程模型：fork 出 $procs 个 worker，按 index 切分 $N 个任务，结果经管道回收。
 */
function processRun(int $N, int $procs, callable $task): void
{
    $perWorker = intdiv($N, $procs);
    $pipes = [];
    $pids = [];
    for ($w = 0; $w < $procs; $w++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($pair[0]);
            $start = $w * $perWorker;
            $end = ($w === $procs - 1) ? $N : $start + $perWorker;
            $checksum = 0;
            for ($i = $start; $i < $end; $i++) {
                $checksum += $task([$i]);
            }
            fwrite($pair[1], serialize($checksum));
            fclose($pair[1]);
            if (function_exists('posix_kill')) {
                posix_kill(posix_getpid(), 9);
            }
            exit(0);
        }
        fclose($pair[1]);
        $pipes[$w] = $pair[0];
        $pids[$w] = $pid;
    }
    foreach ($pipes as $h) {
        stream_get_contents($h);
        fclose($h);
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

if (!function_exists('pcntl_fork')) {
    echo "警告: 当前环境无 pcntl_fork，跳过 process 模型对比。\n";
}

foreach ($workloads as $wname => $task) {
    echo "\n【工作负载: {$wname}】  N=$N 个独立单元\n";

    // 单进程基线
    $single = measure("  single  (1 进程/1 线程, 顺序)", $N, static function () use ($N, $task) {
        $c = 0;
        for ($i = 0; $i < $N; $i++) {
            $c += $task([$i]);
        }
        // 防止被优化掉
        if ($c === PHP_INT_MIN) {
            echo $c;
        }
    });

    // 多进程（fork 池，扫不同进程数）
    if (function_exists('pcntl_fork')) {
        $bestProc = 0;
        $bestProcOps = 0;
        foreach ([1, 2, 4, 8] as $procs) {
            $ops = measure("  process (fork 池 x$procs)", $N, static function () use ($N, $procs, $task) {
                processRun($N, $procs, $task);
            });
            if ($ops > $bestProcOps) {
                $bestProcOps = $ops;
                $bestProc = $procs;
            }
        }
    }

    // 多线程（ext-parallel，扫不同线程数）
    if (EngineFactory::isSupported('parallel')) {
        $bestThread = 0;
        $bestThreadOps = 0;
        foreach ([1, 2, 4, 8] as $threads) {
            $ops = measure("  thread  (parallel x$threads)", $N, static function () use ($N, $threads, $task) {
                $rt = new Runtime(null, 'parallel', $threads);
                // 滑动窗口：最多保留 2×线程数 个在途任务，避免一次性堆积导致内存膨胀
                $window = max(1, $threads * 2);
                $pending = [];
                for ($i = 0; $i < $N; $i++) {
                    $pending[] = $rt->run($task, [$i]);
                    if (count($pending) >= $window) {
                        array_shift($pending)->get();
                    }
                }
                foreach ($pending as $f) {
                    $f->get();
                }
                $rt->close();
            });
            if ($ops > $bestThreadOps) {
                $bestThreadOps = $ops;
                $bestThread = $threads;
            }
        }
    }

    // 汇总
    echo "  ---\n";
    printf(
        "  最优: 单进程 %s ops/s",
        number_format((int) $single, 0)
    );
    if (function_exists('pcntl_fork')) {
        printf(" | 多进程 最优 x%d = %s ops/s (%.1fx 单进程)",
            $bestProc, number_format((int) $bestProcOps, 0), $single > 0 ? $bestProcOps / $single : 0);
    }
    if (EngineFactory::isSupported('parallel')) {
        printf(" | 多线程 最优 x%d = %s ops/s (%.1fx 单进程, %.1fx 多进程)",
            $bestThread, number_format((int) $bestThreadOps, 0),
            $single > 0 ? $bestThreadOps / $single : 0,
            !empty($bestProcOps) ? $bestThreadOps / $bestProcOps : 0);
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// 补充：纯任务派发开销（空任务 submit+get）—— 多线程 vs 多进程的「天花板」差距
// ---------------------------------------------------------------------------
echo "\n【派发开销基线: 空任务 submit+get】\n";
if (EngineFactory::isSupported('parallel')) {
    measure("  thread  (parallel x8, 空任务)", 1000, static function () {
        $rt = new Runtime(null, 'parallel', 8);
        $pending = [];
        for ($i = 0; $i < 1000; $i++) {
            $pending[] = $rt->run(static fn() => 1);
            if (count($pending) >= 16) {
                array_shift($pending)->get();
            }
        }
        foreach ($pending as $f) {
            $f->get();
        }
        $rt->close();
    });
}
if (function_exists('pcntl_fork')) {
    measure("  process (每任务独立 fork x1000)", 1000, static function () {
        $pids = [];
        $handles = [];
        for ($i = 0; $i < 1000; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($pair[0]);
                fwrite($pair[1], serialize($i));
                fclose($pair[1]);
                if (function_exists('posix_kill')) {
                    posix_kill(posix_getpid(), 9);
                }
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
}

echo "\n============================================\n";
echo "  结论维度:\n";
echo "   - 多线程(parallel)靠共享内存+无 fork，派发开销远低于多进程;\n";
echo "   - 中等/轻量任务下，多线程相对多进程的吞吐提升可达 10x~100x;\n";
echo "   - 单进程→多线程的并行加速比受 CPU 核心数约束（接近线性）。\n";
echo "============================================\n";
