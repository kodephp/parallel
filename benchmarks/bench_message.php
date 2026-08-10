<?php

declare(strict_types=1);

/**
 * 群发消息场景压测：短数据 / 中数据 / 大数据
 *
 * 场景对应真实业务：群发邮件、站内消息、微博/APP 推送通知。
 * 每条消息的工作 = 模板渲染 + 字段校验 + 摘要签名（纯 CPU + 内存拷贝），
 * 这是「高频短任务」的典型形态，用来衡量派发开销与批量合并的收益。
 *
 * 对比维度：
 *   1. serial            单进程串行（业务基线）
 *   2. threads(map)      单进程 + N 线程，逐条派发
 *   3. threads(mapBatch) 单进程 + N 线程，批量合并派发   <- 推荐
 *   4. procs x threads   P 进程 × N 线程（模拟 kode/process + 本包组合）
 *
 * 用法：
 *   php benchmarks/bench_message.php
 *   N=50000 THREADS=10 PROCS=4 BATCH=256 php benchmarks/bench_message.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Util\Sys;

$THREADS = (int) (getenv('THREADS') ?: 10);
$PROCS = (int) (getenv('PROCS') ?: 4);
$BATCH = (int) (getenv('BATCH') ?: 0); // 0 = 交给 mapBatch 自动推导
$N_OVERRIDE = (int) (getenv('N') ?: 0);

/** 三档数据规模：短（通知）/ 中（站内信）/ 大（富文本邮件） */
$profiles = [
    'short'  => ['bytes' => 120,   'n' => 50000, 'desc' => '短数据 · 推送通知 120B'],
    'medium' => ['bytes' => 4096,  'n' => 20000, 'desc' => '中数据 · 站内信 4KB'],
    'large'  => ['bytes' => 65536, 'n' => 2000,  'desc' => '大数据 · 富文本邮件 64KB'],
];

echo "群发消息压测 | PHP " . PHP_VERSION . ' | ZTS=' . (PHP_ZTS ? 'yes' : 'no')
    . ' | ext-parallel=' . (extension_loaded('parallel') ? 'yes' : 'no')
    . ' | cpus=' . Sys::cpuCount() . PHP_EOL;
echo "线程数={$THREADS} 进程数={$PROCS} 批大小=" . ($BATCH > 0 ? (string) $BATCH : 'auto') . PHP_EOL . PHP_EOL;

/**
 * 单条消息处理：渲染模板 + 校验 + 摘要
 */
$worker = static function (array $msg): string {
    if ($msg['uid'] <= 0 || $msg['name'] === '') {
        throw new InvalidArgumentException('invalid recipient');
    }

    $body = strtr($msg['tpl'], [
        '{name}' => $msg['name'],
        '{uid}' => (string) $msg['uid'],
        '{amount}' => number_format($msg['amount'], 2),
    ]);

    return substr(md5($body), 0, 12);
};

/**
 * 构造收件人数据集
 *
 * @return array<int, array{uid:int,name:string,amount:float,tpl:string}>
 */
$makeItems = static function (int $n, int $bytes): array {
    $filler = str_repeat('内容', max(1, intdiv($bytes, 6)));
    $tpl = "尊敬的{name}(#{uid})：您的奖励 {amount} 元已到账。" . $filler;

    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = [
            'uid' => $i,
            'name' => 'user' . $i,
            'amount' => $i / 100,
            'tpl' => $tpl,
        ];
    }

    return $items;
};

$ms = static fn (float $t): float => round($t * 1000, 1);

foreach ($profiles as $name => $p) {
    $n = $N_OVERRIDE > 0 ? $N_OVERRIDE : $p['n'];
    $items = $makeItems($n, $p['bytes']);

    echo "== {$p['desc']} | N={$n} ==" . PHP_EOL;

    // 1. 串行基线（3 轮取最快，避免噪声虚高并行倍率）
    $serial = PHP_FLOAT_MAX;
    for ($r = 0; $r < 3; $r++) {
        $t = microtime(true);
        foreach ($items as $msg) {
            $worker($msg);
        }
        $serial = min($serial, microtime(true) - $t);
    }
    printf("  serial              %8.1f ms  %10s msg/s\n", $ms($serial), number_format($n / max($serial, 1e-9), 0));

    // 2. 逐条派发
    $pool = new WorkerPool($THREADS);
    $t = microtime(true);
    $pool->map($items, $worker);
    $mapT = microtime(true) - $t;
    $pool->close();
    printf(
        "  threads(map)        %8.1f ms  %10s msg/s  %5.2fx\n",
        $ms($mapT),
        number_format($n / max($mapT, 1e-9), 0),
        $serial / max($mapT, 1e-9)
    );

    // 3. 批量合并派发
    $pool = new WorkerPool($THREADS);
    $t = microtime(true);
    $pool->mapBatch($items, $worker, $BATCH);
    $batchT = microtime(true) - $t;
    $pool->close();
    printf(
        "  threads(mapBatch)   %8.1f ms  %10s msg/s  %5.2fx\n",
        $ms($batchT),
        number_format($n / max($batchT, 1e-9), 0),
        $serial / max($batchT, 1e-9)
    );

    // 4. P 进程 × N 线程
    if (function_exists('pcntl_fork')) {
        $procT = runMultiProcess($items, $worker, $PROCS, $THREADS, $BATCH);
        printf(
            "  %dproc x %dthread    %8.1f ms  %10s msg/s  %5.2fx\n",
            $PROCS,
            $THREADS,
            $ms($procT),
            number_format($n / max($procT, 1e-9), 0),
            $serial / max($procT, 1e-9)
        );
    } else {
        echo "  procs x threads     skipped (缺少 pcntl)" . PHP_EOL;
    }

    echo PHP_EOL;
    unset($items);
}

echo "说明：threads(map) 每条都要序列化闭包+参数，短任务下派发开销占主导；" . PHP_EOL;
echo "      mapBatch 每批只序列化一次闭包，是高频短任务的推荐用法。" . PHP_EOL;

/**
 * P 个进程平分数据，每进程内再开 N 线程批量处理
 *
 * @param array<int, array<string, mixed>> $items
 */
function runMultiProcess(array $items, callable $worker, int $procs, int $threads, int $batch): float
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
            // 子进程：fork 之后才创建线程池，避免继承父进程线程状态
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
