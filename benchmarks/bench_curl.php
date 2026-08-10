<?php
/**
 * 多用户场景 HTTP 扇出压测：顺序 vs curl_multi（单进程事件循环） vs 线程并行 curl
 *
 * 准确口径：本机起一个真正并发的本地 HTTP 服务（每连接 fork），用 ?delay= 模拟外部 API 延迟，
 * 因此客户端并发度能真实转化为吞吐提升。对比维度：
 *   - sequential curl：单进程顺序，客户端无并发
 *   - CurlMulti（curl_multi）：单进程事件循环，连接复用，I/O 密集最优
 *   - parallel 线程 + curl（mapBatch）：每个线程独立建连 + 序列化开销，纯 I/O 反而更慢
 *
 * 用法：BASE=http://127.0.0.1:8899 DELAY=20 N=200 php benchmarks/bench_curl.php
 */

declare(strict_types=1);

use Kode\Parallel\Curl\CurlMulti;
use Kode\Parallel\Pool\WorkerPool;

require_once __DIR__ . '/../vendor/autoload.php';

$base = getenv('BASE') ?: 'http://127.0.0.1:8899';
$delay = (int) (getenv('DELAY') ?: 20);
$N = (int) (getenv('N') ?: 200);
$threads = (int) (getenv('THREADS') ?: 8);

echo "========================================\n";
echo "    多用户 HTTP 扇出压测 (v1.13.0)\n";
echo "    base={$base} delay={$delay}ms N={$N} threads={$threads}\n";
echo "========================================\n\n";

if (!extension_loaded('curl')) {
    echo "ext-curl 未安装，无法压测\n";
    exit(1);
}

$urls = [];
for ($i = 0; $i < $N; $i++) {
    $urls["u{$i}"] = "{$base}/?delay={$delay}";
}

function measureHttp(string $label, int $N, callable $fn): float
{
    $start = hrtime(true);
    $fn();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $rps = $elapsedMs > 0 ? ($N / ($elapsedMs / 1000)) : 0;
    printf("  %-26s %9.2f ms   %12s req/s\n", $label, $elapsedMs, number_format($rps));
    return $rps;
}

echo "【1】顺序 curl（单进程，无并发）\n";
measureHttp('  sequential curl', $N, static function () use ($urls) {
    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
});

echo "\n【2】CurlMulti（curl_multi 事件循环，单进程，连接复用）——并发档位扫描\n";
$best = ['rps' => 0.0, 'c' => 0];
$rpsMultiUnlim = 0.0;

foreach ([0, 4, 8, 16, 32, 64] as $c) {
    $label = $c === 0 ? '  不限并发（洪泛）' : "  并发x{$c}";
    $rps = measureHttp($label, $N, static function () use ($urls, $N, $delay, $c) {
        $res = CurlMulti::fetch($urls, $c, (int) ceil($N * $delay / 1000) + 30);
        if (count($res) !== count($urls)) {
            throw new RuntimeException('请求数不一致: ' . count($res));
        }
    });

    if ($c === 0) {
        $rpsMultiUnlim = $rps;
    } elseif ($rps > $best['rps']) {
        $best = ['rps' => $rps, 'c' => $c];
    }
}

$rpsMulti = $best['rps'];
printf("  → 最优并发档: x%d（%s req/s）\n", $best['c'], number_format($rpsMulti));

echo "\n【3】parallel 线程 + curl（mapBatch，每线程独立建连）\n";
$rpsThread = measureHttp("  thread curl x{$threads}", $N, static function () use ($urls, $threads) {
    $pool = new WorkerPool($threads);
    $pool->mapBatch($urls, static function (string $url): void {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
    });
    $pool->close();
});

$seqMs = $N * $delay;
printf("\n>>> 顺序理论下限: %d ms\n", $seqMs);
printf(">>> curl_multi 不限并发:        %.1fx（一次发 %d 连接，易压垮对端/限流，不推荐）\n", $seqMs > 0 ? ($seqMs / 1000) * $rpsMultiUnlim / $N : 0, $N);
printf(">>> curl_multi 最优并发x%d（推荐）: %.1fx\n", $best['c'], $seqMs > 0 ? ($seqMs / 1000) * $rpsMulti / $N : 0);
printf(">>> 线程模式并发x%d:           %.1fx\n", $threads, $seqMs > 0 ? ($seqMs / 1000) * $rpsThread / $N : 0);
echo "    结论: 纯 I/O 扇出用 curl_multi（单进程事件循环 + 限并发）最快最省；\n";
echo "          线程模式适合「请求 + CPU 计算」整体并行，纯网络等待时反而因建连/序列化更慢。\n";
