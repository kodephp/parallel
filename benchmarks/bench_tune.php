<?php

declare(strict_types=1);

/**
 * 配置寻优：回答「本机什么配置最优」
 *
 * 两张网格：
 *   A. 线程数 × 批大小   —— 单进程内的最优组合
 *   B. 进程数 × 线程数   —— 多进程（kode/process）与本包线程的最优乘积
 *
 * 结论会随负载类型变化，所以支持三档负载：
 *   PROFILE=short  高频短任务（推送通知）  —— 派发开销占主导，批量最关键
 *   PROFILE=medium 中等任务（站内信渲染）
 *   PROFILE=large  重任务（富文本邮件/图片处理）—— 并行度最关键
 *
 * 用法：
 *   php benchmarks/bench_tune.php
 *   PROFILE=short N=50000 php benchmarks/bench_tune.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Util\Sys;

$PROFILE = getenv('PROFILE') ?: 'medium';
$cpus = Sys::cpuCount();

$profiles = [
    'short'  => ['bytes' => 120,   'n' => 50000],
    'medium' => ['bytes' => 4096,  'n' => 20000],
    'large'  => ['bytes' => 65536, 'n' => 2000],
];

if (!isset($profiles[$PROFILE])) {
    fwrite(STDERR, "未知 PROFILE：{$PROFILE}（可选 short/medium/large）" . PHP_EOL);
    exit(1);
}

$N = (int) (getenv('N') ?: $profiles[$PROFILE]['n']);
$bytes = $profiles[$PROFILE]['bytes'];

echo "配置寻优 | PHP " . PHP_VERSION . ' | ZTS=' . (PHP_ZTS ? 'yes' : 'no')
    . ' | ext-parallel=' . (extension_loaded('parallel') ? 'yes' : 'no')
    . " | cpus={$cpus}" . PHP_EOL;
echo "负载={$PROFILE} 单条载荷={$bytes}B N={$N}" . PHP_EOL . PHP_EOL;

$worker = static function (array $msg): string {
    $body = strtr($msg['tpl'], ['{name}' => $msg['name'], '{uid}' => (string) $msg['uid']]);

    return substr(md5($body), 0, 12);
};

$filler = str_repeat('内容', max(1, intdiv($bytes, 6)));
$tpl = "尊敬的{name}(#{uid})：您有一条新消息。" . $filler;
$items = [];
for ($i = 1; $i <= $N; $i++) {
    $items[] = ['uid' => $i, 'name' => 'user' . $i, 'tpl' => $tpl];
}

// 串行基线：跑 3 轮取最快，避免基线噪声虚高并行提速倍率
$serial = PHP_FLOAT_MAX;
for ($r = 0; $r < 3; $r++) {
    $t = microtime(true);
    foreach ($items as $m) {
        $worker($m);
    }
    $serial = min($serial, microtime(true) - $t);
}
printf("串行基线（3 轮最快）：%.1f ms  %s msg/s\n\n", $serial * 1000, number_format($N / $serial, 0));

// ---------- A. 线程数 × 批大小 ----------
$threadGrid = array_values(array_unique(array_filter(
    [1, 2, 4, 8, $cpus, $cpus * 2],
    static fn (int $v): bool => $v >= 1 && $v <= 32
)));
sort($threadGrid);
// 单批载荷不超过 8MB，避免大数据档撑爆 memory_limit；0 表示交给自动推导
$maxByMem = max(1, intdiv(8 * 1024 * 1024, max(1, $bytes)));
$batchGrid = array_values(array_filter(
    [1, 32, 128, 512, 2048],
    static fn (int $b): bool => $b <= $maxByMem
));
$batchGrid[] = 0;

echo "A. 线程数 × 批大小（吞吐 msg/s，括号为相对串行提速；auto=默认自动批大小）" . PHP_EOL;
printf("%8s", 'threads');
foreach ($batchGrid as $b) {
    printf("%18s", $b === 0 ? 'batch=auto' : "batch={$b}");
}
echo PHP_EOL;

$bestA = ['rate' => 0.0, 'threads' => 0, 'batch' => 0];

foreach ($threadGrid as $th) {
    printf("%8d", $th);

    foreach ($batchGrid as $b) {
        $pool = new WorkerPool($th);
        $t = microtime(true);
        $pool->mapBatch($items, $worker, $b);
        $el = microtime(true) - $t;
        $pool->close();

        $rate = $N / max($el, 1e-9);
        if ($rate > $bestA['rate']) {
            $bestA = ['rate' => $rate, 'threads' => $th, 'batch' => $b];
        }

        printf("%11s(%.1fx)", number_format($rate, 0), $serial / max($el, 1e-9));
    }

    echo PHP_EOL;
}

printf(
    "\n  最优：threads=%d batch=%s → %s msg/s（%.1fx 串行）\n\n",
    $bestA['threads'],
    $bestA['batch'] === 0 ? 'auto' : (string) $bestA['batch'],
    number_format($bestA['rate'], 0),
    $bestA['rate'] / ($N / $serial)
);

// ---------- B. 进程数 × 线程数 ----------
if (!function_exists('pcntl_fork')) {
    echo "B. 进程数 × 线程数：skipped（缺少 pcntl）" . PHP_EOL;
    exit(0);
}

$procGrid = [1, 2, 4, 8];
$innerThreads = [1, 2, 4, 8];
$batch = $bestA['batch'];
$batchLabel = $batch === 0 ? 'auto' : (string) $batch;

echo "B. 进程数 × 线程数（batch={$batchLabel}，吞吐 msg/s，括号为相对串行提速）" . PHP_EOL;
printf("%8s", 'procs');
foreach ($innerThreads as $th) {
    printf("%18s", "threads={$th}");
}
echo PHP_EOL;

$bestB = ['rate' => 0.0, 'procs' => 0, 'threads' => 0];

foreach ($procGrid as $procs) {
    printf("%8d", $procs);

    foreach ($innerThreads as $th) {
        $el = runProcs($items, $worker, $procs, $th, $batch);
        $rate = $N / max($el, 1e-9);

        if ($rate > $bestB['rate']) {
            $bestB = ['rate' => $rate, 'procs' => $procs, 'threads' => $th];
        }

        printf("%11s(%.1fx)", number_format($rate, 0), $serial / max($el, 1e-9));
    }

    echo PHP_EOL;
}

printf(
    "\n  最优：procs=%d × threads=%d（总并行 %d）→ %s msg/s（%.1fx 串行）\n",
    $bestB['procs'],
    $bestB['threads'],
    $bestB['procs'] * $bestB['threads'],
    number_format($bestB['rate'], 0),
    $bestB['rate'] / ($N / $serial)
);
echo "\n经验法则：进程数 × 线程数 ≈ CPU 核数（本机 {$cpus}）时最优；超配会因上下文切换与内存带宽掉速。" . PHP_EOL;

/**
 * @param array<int, array<string, mixed>> $items
 */
function runProcs(array $items, callable $worker, int $procs, int $threads, int $batch): float
{
    $chunks = array_chunk($items, (int) ceil(count($items) / $procs));
    $t = microtime(true);
    $pids = [];

    foreach ($chunks as $chunk) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('fork 失败');
        }

        if ($pid === 0) {
            $pool = new WorkerPool($threads);
            $pool->mapBatch($chunk, $worker, $batch);
            $pool->close();
            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    return microtime(true) - $t;
}
