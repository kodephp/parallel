<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Runtime\Runtime;

$engine = EngineFactory::detect();
echo "===========================================\n";
echo "    Kode/Parallel 完整性能压测\n";
echo "    PHP: " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') ? 'YES' : 'NO') . ")\n";
echo "    engine: {$engine}\n";
echo "    ext-parallel: " . (extension_loaded('parallel') ? 'LOADED' : 'NOT LOADED') . "\n";
echo "===========================================\n\n";

$results = [];

echo "【1】Runtime 创建开销测试 (100 次)\n";
$startTime = microtime(true);
$runtimes = [];
for ($i = 0; $i < 100; $i++) {
    $runtime = new Runtime();
    $runtime->run(fn() => 1 + 1);
    $runtimes[] = $runtime;
}
$creationTime = (microtime(true) - $startTime) * 1000;
foreach ($runtimes as $r) { $r->close(); }
$results['creation'] = [
    'name' => 'Runtime 创建 (100次)',
    'time_ms' => round($creationTime, 2),
    'per_ms' => round($creationTime / 100, 4),
];
echo "   完成: {$creationTime}ms (平均 " . round($creationTime / 100, 4) . "ms/次)\n";

echo "\n【2】WorkerPool 测试 (100 任务)\n";
$pool = new WorkerPool(8);
$iterations = 100;
$startTime = microtime(true);
$futures = [];
for ($i = 0; $i < $iterations; $i++) {
    $futures[] = $pool->submit(fn($a) => array_sum(range(1, 500)), []);
}
foreach ($futures as $f) { $f->get(); }
$poolTime = (microtime(true) - $startTime) * 1000;
$pool->close();
$results['pool'] = [
    'name' => 'WorkerPool (100任务)',
    'time_ms' => round($poolTime, 2),
    'per_ms' => round($poolTime / $iterations, 3),
];
echo "   完成: {$poolTime}ms (平均 " . round($poolTime / $iterations, 3) . "ms/任务)\n";

echo "\n【3】CPU 密集型任务 (20 任务并行)\n";
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
$results['cpu'] = [
    'name' => 'CPU 密集型 (20任务)',
    'time_ms' => round($cpuTime, 2),
    'per_ms' => round($cpuTime / 20, 2),
];
echo "   完成: {$cpuTime}ms (平均 " . round($cpuTime / 20, 2) . "ms/任务)\n";
$runtime->close();

echo "\n【4】多任务并行测试 (50 任务同时执行)\n";
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
$results['parallel'] = [
    'name' => '并行 (50任务)',
    'time_ms' => round($parallelTime, 2),
    'per_ms' => round($parallelTime / 50, 2),
];
echo "   完成: {$parallelTime}ms (平均 " . round($parallelTime / 50, 2) . "ms/任务)\n";
$runtime->close();

echo "\n【5】内存使用测试 (100 任务)\n";
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
    'name' => '内存 (100任务)',
    'used_kb' => round($memUsed, 1),
    'per_kb' => round($memUsed / 100, 2),
];
echo "   完成: 内存使用 " . round($memUsed, 1) . " KB (平均 " . round($memUsed / 100, 2) . " KB/任务)\n";

echo "\n===========================================\n";
echo "              压测结果汇总\n";
echo "===========================================\n";
echo "| 测试项目              | 数值              |\n";
echo "|-----------------------|-------------------|\n";
echo "| Runtime 创建 (100次)  | {$results['creation']['time_ms']} ms           |\n";
echo "| WorkerPool (100任务)  | {$results['pool']['time_ms']} ms           |\n";
echo "| CPU 密集型 (20任务)  | {$results['cpu']['time_ms']} ms           |\n";
echo "| 并行 (50任务)         | {$results['parallel']['time_ms']} ms           |\n";
echo "| 内存/任务             | {$results['memory']['per_kb']} KB          |\n";
echo "===========================================\n";

$peak = memory_get_peak_usage(true) / 1024 / 1024;
echo "峰值内存: " . round($peak, 2) . " MB\n";
echo "===========================================\n";
