<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Thread\ThreadPool;
use Kode\Parallel\Thread\ThreadMap;
use Kode\Parallel\Thread\ThreadQueue;

echo "===========================================\n";
echo "    Kode/Parallel 性能压测\n";
echo "    PHP: " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') ? 'YES' : 'NO') . ")\n";
echo "    ext-parallel: " . (extension_loaded('parallel') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "===========================================\n\n";

$results = [];

echo "1. 线程创建开销测试 (100 线程)...\n";
$runtime = new Runtime();
$iterations = 100;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $runtime->run(fn() => 1 + 1);
}
$endTime = microtime(true);
$duration = ($endTime - $startTime) * 1000;
$results['thread_creation'] = round($duration, 2);
echo "   完成: {$duration}ms (平均 " . round($duration / $iterations, 4) . "ms/线程)\n";
$runtime->close();

echo "2. ThreadPool 测试 (20 任务, 4 线程)...\n";
$pool = new ThreadPool(4, 8, 50);
$pool->start();
$iterations = 20;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $pool->submit(fn($args) => array_sum(range(1, 1000)), []);
}
$pool->waitAll();
$endTime = microtime(true);
$duration = ($endTime - $startTime) * 1000;
$results['threadpool'] = round($duration, 2);
echo "   完成: {$duration}ms\n";
$pool->shutdown();

echo "3. ThreadMap 测试 (5000 次 读/写)...\n";
$map = new ThreadMap(64);
$iterations = 5000;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $map->set("k{$i}", "v{$i}");
    $map->get("k{$i}");
}
$endTime = microtime(true);
$duration = ($endTime - $startTime) * 1000;
$throughput = ($iterations * 2) / ($duration / 1000);
$results['threadmap'] = ['time' => round($duration, 2), 'tps' => round($throughput, 0)];
echo "   完成: {$duration}ms (吞吐量: " . round($throughput, 0) . " /s)\n";

echo "4. ThreadQueue 测试 (5000 次 入/出)...\n";
$queue = new ThreadQueue(10000);
$iterations = 5000;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) { $queue->push("i{$i}"); }
for ($i = 0; $i < $iterations; $i++) { $queue->shift(); }
$endTime = microtime(true);
$duration = ($endTime - $startTime) * 1000;
$throughput = ($iterations * 2) / ($duration / 1000);
$results['threadqueue'] = ['time' => round($duration, 2), 'tps' => round($throughput, 0)];
echo "   完成: {$duration}ms (吞吐量: " . round($throughput, 0) . " /s)\n";

echo "5. CPU 密集型 (10 任务并行)...\n";
$runtime = new Runtime();
$startTime = microtime(true);
$futures = [];
for ($i = 0; $i < 10; $i++) {
    $futures[] = $runtime->run(fn($a) => array_sum(array_map(fn($j) => sqrt($j) * sin($j), range(1, $a['n']))), ['n' => 50000]);
}
foreach ($futures as $f) { $f->get(); }
$endTime = microtime(true);
$duration = ($endTime - $startTime) * 1000;
$results['cpu_bound'] = round($duration, 2);
echo "   完成: {$duration}ms\n";
$runtime->close();

echo "6. I/O 模拟 (sleep 5ms x 10 并行)...\n";
$runtime = new Runtime();
$startTime = microtime(true);
$futures = [];
for ($i = 0; $i < 10; $i++) {
    $futures[] = $runtime->run(fn($a) => usleep($a['us']), ['us' => 5000]);
}
foreach ($futures as $f) { $f->get(); }
$endTime = microtime(true);
$duration = ($endTime - $startTime) * 1000;
$serialTime = 50;
$speedup = $serialTime / $duration;
$results['io_bound'] = ['time' => round($duration, 2), 'speedup' => round($speedup, 2)];
echo "   完成: {$duration}ms (串行50ms, 加速比: {$speedup}x)\n";
$runtime->close();

echo "7. 内存使用 (50 线程)...\n";
$runtime = new Runtime();
$memBefore = memory_get_usage(true);
$futures = [];
for ($i = 0; $i < 50; $i++) {
    $futures[] = $runtime->run(fn() => str_repeat('x', 1024), []);
}
foreach ($futures as $f) { $f->get(); }
$memAfter = memory_get_usage(true);
$memUsed = ($memAfter - $memBefore) / 1024;
$results['memory'] = ['used_kb' => round($memUsed, 1), 'per_thread_kb' => round($memUsed / 50, 2)];
echo "   完成: 内存使用 " . round($memUsed, 1) . " KB (平均 " . round($memUsed / 50, 2) . " KB/线程)\n";
$runtime->close();

echo "\n===========================================\n";
echo "              压测结果汇总\n";
echo "===========================================\n";
echo "| 测试项目              | 数值              |\n";
echo "|-----------------------|-------------------|\n";
echo "| 线程创建 (100次)      | {$results['thread_creation']} ms           |\n";
echo "| ThreadPool (20任务)   | {$results['threadpool']} ms           |\n";
echo "| ThreadMap (读+写)     | {$results['threadmap']['tps']} /s          |\n";
echo "| ThreadQueue (入+出)    | {$results['threadqueue']['tps']} /s          |\n";
echo "| CPU 密集型            | {$results['cpu_bound']} ms           |\n";
echo "| I/O 模拟 (加速比)     | {$results['io_bound']['speedup']}x            |\n";
echo "| 内存/线程             | {$results['memory']['per_thread_kb']} KB          |\n";
echo "===========================================\n";

$peak = memory_get_peak_usage(true) / 1024 / 1024;
echo "峰值内存: " . round($peak, 2) . " MB\n";
echo "===========================================\n";
