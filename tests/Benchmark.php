<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Thread\ThreadPool;
use Kode\Parallel\Thread\ThreadMap;
use Kode\Parallel\Thread\ThreadQueue;
use Kode\Parallel\Thread\ThreadBarrier;

echo "===========================================\n";
echo "    Kode/Parallel 完整性能压测\n";
echo "    PHP: " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') ? 'YES' : 'NO') . ")\n";
echo "    ext-parallel: " . (extension_loaded('parallel') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "===========================================\n\n";

$results = [];

echo "【1】线程创建开销测试 (100 线程)\n";
$startTime = microtime(true);
$runtimes = [];
for ($i = 0; $i < 100; $i++) {
    $runtime = new Runtime();
    $runtime->run(fn() => 1 + 1);
    $runtimes[] = $runtime;
}
$creationTime = (microtime(true) - $startTime) * 1000;
foreach ($runtimes as $r) { $r->close(); }
$results['thread_creation'] = [
    'name' => '线程创建 (100次)',
    'time_ms' => round($creationTime, 2),
    'per_thread_ms' => round($creationTime / 100, 4),
];
echo "   完成: {$creationTime}ms (平均 " . round($creationTime / 100, 4) . "ms/线程)\n";

echo "\n【2】ThreadPool 测试 (100 任务, 8 线程)\n";
$pool = new ThreadPool(8, 16, 200);
$pool->start();
$iterations = 100;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $pool->submit(fn($a) => array_sum(range(1, 500)), []);
}
$pool->waitAll();
$poolTime = (microtime(true) - $startTime) * 1000;
$results['threadpool'] = [
    'name' => 'ThreadPool (100任务)',
    'time_ms' => round($poolTime, 2),
    'per_task_ms' => round($poolTime / $iterations, 3),
];
echo "   完成: {$poolTime}ms (平均 " . round($poolTime / $iterations, 3) . "ms/任务)\n";
$pool->shutdown();

echo "\n【3】ThreadMap 测试 (10000 次 读/写)\n";
$map = new ThreadMap(128);
$iterations = 10000;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $map->set("k{$i}", str_repeat('v', 100));
    $map->get("k{$i}");
}
$mapTime = (microtime(true) - $startTime) * 1000;
$mapTps = round(($iterations * 2) / ($mapTime / 1000));
$results['threadmap'] = [
    'name' => 'ThreadMap (1万次)',
    'time_ms' => round($mapTime, 2),
    'tps' => $mapTps,
];
echo "   完成: {$mapTime}ms (吞吐量: {$mapTps} /s)\n";

echo "\n【4】ThreadQueue 测试 (10000 次 入/出)\n";
$queue = new ThreadQueue(20000);
$iterations = 10000;
$startTime = microtime(true);
for ($i = 0; $i < $iterations; $i++) { $queue->push("item_{$i}"); }
for ($i = 0; $i < $iterations; $i++) { $queue->shift(); }
$queueTime = (microtime(true) - $startTime) * 1000;
$queueTps = round(($iterations * 2) / ($queueTime / 1000));
$results['threadqueue'] = [
    'name' => 'ThreadQueue (1万次)',
    'time_ms' => round($queueTime, 2),
    'tps' => $queueTps,
];
echo "   完成: {$queueTime}ms (吞吐量: {$queueTps} /s)\n";

echo "\n【5】CPU 密集型任务 (20 任务并行)\n";
$runtime = new Runtime();
$startTime = microtime(true);
$futures = [];
for ($i = 0; $i < 20; $i++) {
    $futures[] = $runtime->run(
        fn($a) => array_sum(array_map(fn($j) => sqrt($j) * sin($j), range(1, $a['n']))),
        ['n' => 100000]
    );
}
foreach ($futures as $f) { $f->get(); }
$cpuTime = (microtime(true) - $startTime) * 1000;
$results['cpu_bound'] = [
    'name' => 'CPU 密集型 (20任务)',
    'time_ms' => round($cpuTime, 2),
    'per_task_ms' => round($cpuTime / 20, 2),
];
echo "   完成: {$cpuTime}ms (平均 " . round($cpuTime / 20, 2) . "ms/任务)\n";
$runtime->close();

echo "\n【6】ThreadBarrier 同步测试 (10 线程同时开始)\n";
$barrier = new ThreadBarrier(10);
$startTime = microtime(true);
$runtimes = [];
for ($i = 0; $i < 10; $i++) {
    $r = new Runtime();
    $r->run(function() use ($barrier) {
        $sum = 0;
        for ($j = 0; $j < 10000; $j++) { $sum += $j; }
        $barrier->wait();
        return $sum;
    });
    $runtimes[] = $r;
}
foreach ($runtimes as $r) { $r->close(); }
$barrierTime = (microtime(true) - $startTime) * 1000;
$results['barrier'] = [
    'name' => 'ThreadBarrier (10线程)',
    'time_ms' => round($barrierTime, 2),
];
echo "   完成: {$barrierTime}ms\n";

echo "\n【7】多任务并行测试 (50 任务同时执行)\n";
$runtime = new Runtime();
$startTime = microtime(true);
$futures = [];
for ($i = 0; $i < 50; $i++) {
    $futures[] = $runtime->run(
        fn($a) => array_sum(range(1, $a['n'])),
        ['n' => 10000]
    );
}
foreach ($futures as $f) { $f->get(); }
$parallelTime = (microtime(true) - $startTime) * 1000;
$results['parallel_tasks'] = [
    'name' => '并行 (50任务)',
    'time_ms' => round($parallelTime, 2),
    'per_task_ms' => round($parallelTime / 50, 2),
];
echo "   完成: {$parallelTime}ms (平均 " . round($parallelTime / 50, 2) . "ms/任务)\n";
$runtime->close();

echo "\n【8】内存使用测试 (100 线程)\n";
$memBefore = memory_get_usage(true);
$runtimes = [];
$futures = [];
for ($i = 0; $i < 100; $i++) {
    $r = new Runtime();
    $f = $r->run(fn() => str_repeat('x', 4096), []);
    $runtimes[] = $r;
    $futures[] = $f;
}
foreach ($futures as $f) { $f->get(); }
$memAfter = memory_get_usage(true);
$memUsed = ($memAfter - $memBefore) / 1024;
foreach ($runtimes as $r) { $r->close(); }
$results['memory'] = [
    'name' => '内存 (100线程)',
    'used_kb' => round($memUsed, 1),
    'per_thread_kb' => round($memUsed / 100, 2),
];
echo "   完成: 内存使用 " . round($memUsed, 1) . " KB (平均 " . round($memUsed / 100, 2) . " KB/线程)\n";

echo "\n===========================================\n";
echo "              压测结果汇总\n";
echo "===========================================\n";
echo "| 测试项目              | 数值              |\n";
echo "|-----------------------|-------------------|\n";
echo "| 线程创建 (100次)      | {$results['thread_creation']['time_ms']} ms           |\n";
echo "| ThreadPool (100任务)  | {$results['threadpool']['time_ms']} ms           |\n";
echo "| ThreadMap 吞吐量       | {$results['threadmap']['tps']} /s        |\n";
echo "| ThreadQueue 吞吐量     | {$results['threadqueue']['tps']} /s        |\n";
echo "| CPU 密集型 (20任务)  | {$results['cpu_bound']['time_ms']} ms           |\n";
echo "| 并行 (50任务)         | {$results['parallel_tasks']['time_ms']} ms           |\n";
echo "| ThreadBarrier         | {$results['barrier']['time_ms']} ms           |\n";
echo "| 内存/线程             | {$results['memory']['per_thread_kb']} KB          |\n";
echo "===========================================\n";

$peak = memory_get_peak_usage(true) / 1024 / 1024;
echo "峰值内存: " . round($peak, 2) . " MB\n";
echo "===========================================\n";

echo "\n【性能评价】\n";
echo "- 线程创建: " . ($results['thread_creation']['per_thread_ms'] < 0.5 ? "✅ 优秀" : "⚠️ 可优化") . " (" . $results['thread_creation']['per_thread_ms'] . "ms/线程)\n";
echo "- ThreadPool: " . ($results['threadpool']['per_task_ms'] < 0.1 ? "✅ 优秀" : "⚠️ 可优化") . " (" . $results['threadpool']['per_task_ms'] . "ms/任务)\n";
echo "- ThreadMap: " . ($results['threadmap']['tps'] > 1000000 ? "✅ 优秀" : "⚠️ 可优化") . " (" . number_format($results['threadmap']['tps']) . " /s)\n";
echo "- ThreadQueue: " . ($results['threadqueue']['tps'] > 500000 ? "✅ 优秀" : "⚠️ 可优化") . " (" . number_format($results['threadqueue']['tps']) . " /s)\n";
echo "- 并行加速: " . ($results['parallel_tasks']['per_task_ms'] < $results['cpu_bound']['per_task_ms'] ? "✅ 有效" : "⚠️ 需优化") . "\n";
echo "- 内存效率: " . ($results['memory']['per_thread_kb'] < 50 ? "✅ 优秀" : "⚠️ 可优化") . " (" . $results['memory']['per_thread_kb'] . " KB/线程)\n";
echo "\n";
