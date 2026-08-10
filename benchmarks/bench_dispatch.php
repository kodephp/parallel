<?php
/**
 * kode/parallel 任务派发开销微基准（用于调优自对比）
 *
 * 衡量「提交 + 取回」一个最小任务（空闭包）的吞吐 ops/s，对每次调用的固定开销
 * （对象构造、ID 生成、异常包裹等）最敏感，适合用来验证调优是否带来真实收益。
 *
 * 用法: php benchmarks/bench_dispatch.php [次数]   (默认跑 3 轮，每轮 4000 任务)
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Runtime\Runtime;

if (!EngineFactory::isSupported('parallel')) {
    echo "跳过: 需要 ext-parallel (ZTS)。\n";
    exit(0);
}

$rounds = (int) ($argv[1] ?? 3);
$perRound = 4000;
$threads = (int) (getenv('T') ?: 8);

echo "============================================\n";
echo "  任务派发微基准 (parallel x{$threads})\n";
echo "  PHP " . PHP_VERSION . " (ZTS: " . (defined('ZEND_THREAD_SAFE') && ZEND_THREAD_SAFE ? 'YES' : 'NO') . ")\n";
echo "  每轮 {$perRound} 个空任务 submit+get，共 {$rounds} 轮\n";
echo "============================================\n";

$best = 0;
$sum = 0;
for ($r = 1; $r <= $rounds; $r++) {
    $rt = new Runtime(null, 'parallel', $threads);
    $start = hrtime(true);
    $pending = [];
    $window = $threads * 4;
    for ($i = 0; $i < $perRound; $i++) {
        $pending[] = $rt->run(static fn() => 1);
        if (count($pending) >= $window) {
            array_shift($pending)->get();
        }
    }
    foreach ($pending as $f) {
        $f->get();
    }
    $rt->close();
    $elapsedMs = (hrtime(true) - $start) / 1_000_000;
    $ops = $elapsedMs > 0 ? $perRound / ($elapsedMs / 1000) : 0;
    $best = max($best, $ops);
    $sum += $ops;
    printf("  第 %d 轮: %9.2f ms  %14s ops/s\n", $r, $elapsedMs, number_format($ops, 0));
}
printf("  均值 %14s ops/s   最佳 %14s ops/s\n", number_format($sum / $rounds, 0), number_format($best, 0));
