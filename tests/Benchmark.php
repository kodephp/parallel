<?php

declare(strict_types=1);

namespace Kode\Parallel\Benchmark;

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Channel\Channel;

/**
 * 性能压测脚本
 *
 * 用于测试 kode/parallel 包的各项性能指标。
 */

final class Benchmark
{
    private Runtime $runtime;
    private float $startTime;
    private array $results = [];

    public function __construct()
    {
        $this->runtime = new Runtime();
    }

    public function __destruct()
    {
        $this->runtime->close();
    }

    public function run(): void
    {
        echo "========================================\n";
        echo "     Kode/Parallel 性能压测报告\n";
        echo "========================================\n\n";

        $this->benchmarkTaskCreation();
        $this->benchmarkSimpleTask();
        $this->benchmarkComputationalTask();
        $this->benchmarkMultipleParallelTasks();
        $this->benchmarkChannelCommunication();
        $this->benchmarkSequentialVsParallel();

        $this->printResults();
    }

    private function startTimer(): void
    {
        $this->startTime = hrtime(true);
    }

    private function stopTimer(): float
    {
        $endTime = hrtime(true);
        return ($endTime - $this->startTime) / 1_000_000;
    }

    private function record(string $name, float $time, array $details = []): void
    {
        $this->results[$name] = [
            'time' => $time,
            'details' => $details,
        ];
    }

    private function benchmarkTaskCreation(): void
    {
        echo "1. Task 创建性能测试\n";
        echo str_repeat('-', 40) . "\n";

        $iterations = 10000;
        $this->startTimer();

        for ($i = 0; $i < $iterations; $i++) {
            $task = new Task(fn() => 42);
        }

        $time = $this->stopTimer();
        $perOp = $time / $iterations * 1000;

        echo "   创建 {$iterations} 个 Task: {$time} ms\n";
        echo "   平均每个: {$perOp} μs\n\n";

        $this->record('task_creation', $time, [
            'iterations' => $iterations,
            'per_operation_us' => $perOp,
        ]);
    }

    private function benchmarkSimpleTask(): void
    {
        echo "2. 简单任务执行测试\n";
        echo str_repeat('-', 40) . "\n";

        $iterations = 1000;
        $this->startTimer();

        for ($i = 0; $i < $iterations; $i++) {
            $future = $this->runtime->run(fn() => 42);
            $future->wait();
        }

        $time = $this->stopTimer();
        $perOp = $time / $iterations * 1000;

        echo "   执行 {$iterations} 个简单任务: {$time} ms\n";
        echo "   平均每个: {$perOp} ms\n";
        echo "   吞吐量: " . number_format($iterations / ($time / 1000), 2) . " tasks/sec\n\n";

        $this->record('simple_task', $time, [
            'iterations' => $iterations,
            'per_operation_ms' => $perOp,
            'throughput' => $iterations / ($time / 1000),
        ]);
    }

    private function benchmarkComputationalTask(): void
    {
        echo "3. 计算密集型任务测试\n";
        echo str_repeat('-', 40) . "\n";

        $iterations = 100;
        $n = 100000;

        $this->startTimer();

        for ($i = 0; $i < $iterations; $i++) {
            $future = $this->runtime->run(
                fn($args) => array_sum(range(1, $args['n'])),
                ['n' => $n]
            );
            $future->wait();
        }

        $time = $this->stopTimer();
        $perOp = $time / $iterations;

        echo "   计算 1+2+...+{$n} x {$iterations} 次: {$time} ms\n";
        echo "   平均每次: {$perOp} ms\n";
        echo "   吞吐量: " . number_format($iterations / ($time / 1000), 2) . " tasks/sec\n\n";

        $this->record('computational_task', $time, [
            'iterations' => $iterations,
            'n' => $n,
            'per_operation_ms' => $perOp,
            'throughput' => $iterations / ($time / 1000),
        ]);
    }

    private function benchmarkMultipleParallelTasks(): void
    {
        echo "4. 多任务并行执行测试\n";
        echo str_repeat('-', 40) . "\n";

        $taskCount = 10;
        $workload = 50000;

        $this->startTimer();

        $futures = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $futures[] = $this->runtime->run(
                fn($args) => array_sum(range(1, $args['n'])),
                ['n' => $workload]
            );
        }

        foreach ($futures as $future) {
            $future->wait();
        }

        $time = $this->stopTimer();
        $expectedSum = array_sum(range(1, $workload));

        foreach ($futures as $future) {
            assert($future->get() === $expectedSum);
        }

        echo "   并行执行 {$taskCount} 个任务 (每个计算 1+2+...+{$workload}): {$time} ms\n";
        echo "   平均每个任务: " . ($time / $taskCount) . " ms\n";
        echo "   理论串行时间: " . ($time * $taskCount) . " ms\n";
        echo "   加速比: {$taskCount}x (理想值)\n\n";

        $this->record('parallel_tasks', $time, [
            'task_count' => $taskCount,
            'workload' => $workload,
            'speedup' => $taskCount,
        ]);
    }

    private function benchmarkChannelCommunication(): void
    {
        echo "5. Channel 通信性能测试\n";
        echo str_repeat('-', 40) . "\n";

        $iterations = 1000;
        $channel = Channel::make('benchmark_channel');

        $this->startTimer();

        for ($i = 0; $i < $iterations; $i++) {
            $future = $this->runtime->run(
                fn($args) => $args['ch']->send($args['data']),
                ['ch' => $channel, 'data' => "message_{$i}"]
            );
            $future->wait();

            $recvFuture = $this->runtime->run(
                fn($args) => $args['ch']->recv(),
                ['ch' => $channel]
            );
            $recvFuture->wait();
        }

        $time = $this->stopTimer();
        $perOp = $time / $iterations / 2;

        echo "   {$iterations} 次发送/接收: {$time} ms\n";
        echo "   平均每次通信: {$perOp} ms\n";
        echo "   吞吐量: " . number_format($iterations / ($time / 1000), 2) . " ops/sec\n\n";

        $this->record('channel_comm', $time, [
            'iterations' => $iterations,
            'per_operation_ms' => $perOp,
            'throughput' => $iterations / ($time / 1000),
        ]);
    }

    private function benchmarkSequentialVsParallel(): void
    {
        echo "6. 串行 vs 并行性能对比\n";
        echo str_repeat('-', 40) . "\n";

        $taskCount = 5;
        $workload = 100000;
        $expectedSum = array_sum(range(1, $workload));

        $this->startTimer();
        for ($i = 0; $i < $taskCount; $i++) {
            $result = array_sum(range(1, $workload));
            assert($result === $expectedSum);
        }
        $sequentialTime = $this->stopTimer();

        $this->startTimer();
        $futures = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $futures[] = $this->runtime->run(
                fn($args) => array_sum(range(1, $args['n'])),
                ['n' => $workload]
            );
        }
        foreach ($futures as $future) {
            $future->wait();
        }
        $parallelTime = $this->stopTimer();

        $speedup = $sequentialTime / $parallelTime;
        $efficiency = ($speedup / $taskCount) * 100;

        echo "   串行执行: {$sequentialTime} ms\n";
        echo "   并行执行: {$parallelTime} ms\n";
        echo "   加速比: " . number_format($speedup, 2) . "x\n";
        echo "   并行效率: " . number_format($efficiency, 1) . "%\n\n";

        $this->record('seq_vs_par', $parallelTime, [
            'sequential_ms' => $sequentialTime,
            'parallel_ms' => $parallelTime,
            'speedup' => $speedup,
            'efficiency' => $efficiency,
        ]);
    }

    private function printResults(): void
    {
        echo "========================================\n";
        echo "              测试结果汇总\n";
        echo "========================================\n\n";

        foreach ($this->results as $name => $data) {
            echo strtoupper(str_replace('_', ' ', $name)) . ":\n";
            echo "  耗时: {$data['time']} ms\n";

            if (isset($data['details']['throughput'])) {
                echo "  吞吐量: " . number_format($data['details']['throughput'], 2) . " ops/sec\n";
            }

            if (isset($data['details']['speedup'])) {
                echo "  加速比: " . number_format($data['details']['speedup'], 2) . "x\n";
                echo "  效率: " . number_format($data['details']['efficiency'], 1) . "%\n";
            }

            echo "\n";
        }
    }
}

if (php_sapi_name() === 'cli' && extension_loaded('parallel')) {
    require_once __DIR__ . '/vendor/autoload.php';

    echo "\n";
    $benchmark = new Benchmark();
    $benchmark->run();
    echo "\n";
} elseif (!extension_loaded('parallel')) {
    echo "警告: ext-parallel 未安装，跳过压测\n";
}
