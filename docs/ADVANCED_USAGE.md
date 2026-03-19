# Kode/Parallel 高级用法与结合案例

本文档展示如何结合使用 kode/parallel 的各个组件，实现复杂的并行编程模式。

## 目录

- [1. Runtime + Channel 组合](#1-runtime--channel-组合)
- [2. Runtime + Sync 组合](#2-runtime--sync-组合)
- [3. Channel + Sync 组合](#3-channel--sync-组合)
- [4. Events + Channel + Future 组合](#4-events--channel--future-组合)
- [5. Fiber + Runtime 组合](#5-fiber--runtime-组合)
- [6. 完整的数据处理流水线](#6-完整的数据处理流水线)
- [7. 并行任务调度器](#7-并行任务调度器)
- [8. 生产者消费者模式](#8-生产者消费者模式)
- [9. 工作池模式](#9-工作池模式)

---

## 1. Runtime + Channel 组合

### 1.1 并行计算 + 结果收集

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Task\Task;

/**
 * 并行计算矩阵乘法
 * 将大矩阵分成多个小块并行计算
 */
function parallelMatrixMultiply(array $matrixA, array $matrixB, int $workers = 4): array
{
    $runtime = new Runtime();
    $resultChannel = Channel::make('results');
    $n = count($matrixA);

    // 计算每个分块
    $chunkSize = (int)ceil($n / $workers);
    $tasks = [];

    for ($w = 0; $w < $workers; $w++) {
        $startRow = $w * $chunkSize;
        $endRow = min($startRow + $chunkSize, $n);

        if ($startRow >= $n) {
            break;
        }

        $task = new Task(function($args) {
            $startRow = $args['startRow'];
            $endRow = $args['endRow'];
            $matrixA = $args['matrixA'];
            $matrixB = $args['matrixB'];
            $results = [];

            for ($i = $startRow; $i < $endRow; $i++) {
                for ($j = 0; $j < count($matrixB[0]); $j++) {
                    $sum = 0;
                    for ($k = 0; $k < count($matrixA[0]); $k++) {
                        $sum += $matrixA[$i][$k] * $matrixB[$k][$j];
                    }
                    $results[$i][$j] = $sum;
                }
            }

            $args['resultChannel']->send($results);
            return count($results);
        });

        $runtime->run($task, [
            'startRow' => $startRow,
            'endRow' => $endRow,
            'matrixA' => $matrixA,
            'matrixB' => $matrixB,
            'resultChannel' => $resultChannel,
        ]);
    }

    // 收集结果
    $result = [];
    for ($w = 0; $w < $workers; $w++) {
        $chunkResult = $resultChannel->recv();
        $result = array_merge_recursive($result, $chunkResult);
    }

    $runtime->close();
    return $result;
}
```

---

## 2. Runtime + Sync 组合

### 2.1 带锁保护的共享计数器

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Sync\Mutex;
use Kode\Parallel\Sync\Semaphore;

/**
 * 多线程安全计数器
 */
class SafeCounter {
    private Mutex $mutex;
    private int $count = 0;

    public function __construct() {
        $this->mutex = new Mutex();
    }

    public function increment(): int {
        return $this->mutex->withLock(function() {
            return ++$this->count;
        });
    }

    public function get(): int {
        return $this->mutex->withLock(function() {
            return $this->count;
        });
    }
}

// 使用
$runtime = new Runtime();
$counter = new SafeCounter();
$tasks = [];

for ($i = 0; $i < 100; $i++) {
    $tasks[] = $runtime->run(function($args) {
        $counter = $args['counter'];
        for ($j = 0; $j < 100; $j++) {
            $counter->increment();
        }
        return $counter->get();
    }, ['counter' => $counter]);
}

foreach ($tasks as $future) {
    $future->wait();
}

echo "Final count: " . $counter->get() . "\n"; // 输出: 10000
$runtime->close();
```

### 2.2 信号量控制的并发连接池

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Sync\Semaphore;

/**
 * 并发连接池（限制最大并发数）
 */
class ConnectionPool {
    private Semaphore $semaphore;
    private int $maxConnections;
    private array $connections = [];

    public function __construct(int $maxConnections = 5) {
        $this->maxConnections = $maxConnections;
        $this->semaphore = new Semaphore($maxConnections);
    }

    public function execute(callable $task): mixed {
        return $this->semaphore->withResource(function() use ($task) {
            return $task();
        });
    }

    public function getAvailableSlots(): int {
        return $this->semaphore->getCount();
    }
}

// 使用
$runtime = new Runtime();
$pool = new ConnectionPool(3); // 最多3个并发连接

$tasks = [];
for ($i = 0; $i < 10; $i++) {
    $tasks[] = $runtime->run(function($args) {
        $pool = $args['pool'];
        $taskId = $args['taskId'];

        return $pool->execute(function() use ($taskId) {
            // 模拟数据库查询
            usleep(100000); // 100ms
            return "Task {$taskId} completed";
        });
    }, ['pool' => $pool, 'taskId' => $i]);
}

$results = array_map(fn($f) => $f->get(), $tasks);
print_r($results);

$runtime->close();
```

---

## 3. Channel + Sync 组合

### 3.1 带超时的 Channel 通信

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Sync\Cond;
use Kode\Parallel\Sync\Mutex;

/**
 * 带超时的 Channel 包装
 */
class TimeoutChannel {
    private Channel $channel;
    private Cond $cond;
    private Mutex $mutex;
    private bool $hasData = false;
    private mixed $data = null;

    public function __construct() {
        $this->channel = Channel::make();
        $this->cond = new Cond();
        $this->mutex = new Mutex();
    }

    public function send(mixed $data, int $timeoutMs = 0): bool {
        $this->channel->send($data);
        $this->mutex->lock();
        $this->hasData = true;
        $this->data = $data;
        $this->cond->signal();
        $this->mutex->unlock();
        return true;
    }

    public function recv(int $timeoutMs = 0): mixed {
        $this->mutex->lock();

        if (!$this->hasData) {
            $this->cond->wait($this->mutex, $timeoutMs);
        }

        $data = $this->data;
        $this->hasData = false;
        $this->mutex->unlock();

        return $this->channel->recv();
    }
}
```

---

## 4. Events + Channel + Future 组合

### 4.1 多任务协调器

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Events\Events;
use Kode\Parallel\Future\Future;

/**
 * 多任务协调器
 * 使用 Events 统一管理多个 Future 和 Channel
 */
class TaskCoordinator {
    private Runtime $runtime;
    private Events $events;
    private array $futures = [];
    private array $channels = [];
    private array $results = [];

    public function __construct() {
        $this->runtime = new Runtime();
        $this->events = new Events();
    }

    public function submit(string $name, callable $task, array $args = []): self {
        $channel = Channel::make("channel_{$name}");
        $this->channels[$name] = $channel;

        $fullTask = function($args) use ($task, $channel, $name) {
            $result = $task($args);
            $channel->send(['name' => $name, 'result' => $result]);
            return $result;
        };

        $future = $this->runtime->run($fullTask, $args);
        $this->futures[$name] = $future;

        $this->events->attachFuture("future_{$name}", $future);
        $this->events->attachChannel("channel_{$name}", $channel);

        return $this;
    }

    public function waitAll(int $timeoutMs = 0): array {
        $input = [];
        foreach ($this->channels as $name => $channel) {
            $input["channel_{$name}"] = null;
        }
        $this->events->setInput($input);

        $completed = [];
        foreach ($this->events as $event) {
            if ($event->isChannel()) {
                $data = $event->getValue();
                $this->results[$data['name']] = $data['result'];
                $completed[] = $data['name'];
            }

            if (count($completed) >= count($this->futures)) {
                break;
            }
        }

        return $this->results;
    }

    public function close(): void {
        $this->events->clear();
        $this->runtime->close();
    }
}

// 使用
$coordinator = new TaskCoordinator();

$coordinator
    ->submit('compute_heavy', fn() => array_sum(range(1, 1000000)))
    ->submit('compute_light', fn() => 42 * 2)
    ->submit('string_process', fn() => strtoupper('hello world'));

$results = $coordinator->waitAll();
print_r($results);
// 输出: ['compute_heavy' => 500000500000, 'compute_light' => 84, 'string_process' => 'HELLO WORLD']

$coordinator->close();
```

---

## 5. Fiber + Runtime 组合

### 5.1 协程化的任务执行

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Fiber\FiberManager;
use Kode\Parallel\Fiber\Fiber;

/**
 * 协程化的并行任务执行器
 */
class CoroutineTaskExecutor {
    private Runtime $runtime;
    private FiberManager $fiberManager;
    private array $fiberTasks = [];

    public function __construct() {
        $this->runtime = new Runtime();
        $this->fiberManager = new FiberManager();
    }

    public function schedule(string $name, callable $task): self {
        $this->fiberTasks[$name] = $task;
        return $this;
    }

    public function executeAll(): array {
        // 创建 Fibers
        foreach ($this->fiberTasks as $name => $task) {
            $fiber = new Fiber(function($input) use ($task, $name) {
                $result = $task();
                Fiber::suspend(['name' => $name, 'result' => $result]);
                return $result;
            });

            $this->fiberManager->spawn($name, $fiber, []);
        }

        // 启动所有 Fiber
        $this->fiberManager->startAll();

        // 收集结果
        $results = [];
        while (true) {
            $status = $this->fiberManager->getStatus();

            // 检查是否所有 Fiber 都完成
            if (empty(array_filter($status, fn($s) => $s !== 'terminated'))) {
                break;
            }

            usleep(1000);
        }

        return $this->fiberManager->collect();
    }

    public function close(): void {
        $this->fiberManager->clear();
        $this->runtime->close();
    }
}

// 使用
$executor = new CoroutineTaskExecutor();

$results = $executor
    ->schedule('task1', fn() => range(1, 1000))
    ->schedule('task2', fn() => array_sum(range(1, 1000)))
    ->schedule('task3', fn() => str_repeat('x', 1000))
    ->executeAll();

print_r($results);

$executor->close();
```

---

## 6. 完整的数据处理流水线

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Sync\Barrier;
use Kode\Parallel\Task\Task;

/**
 * 并行数据处理流水线
 *
 * 架构：
 * [Input] -> [Validate] -> [Transform] -> [Aggregate] -> [Output]
 */
class DataPipeline {
    private Runtime $runtime;
    private array $inputChannel;
    private array $validatorChannel;
    private array $transformChannel;
    private array $aggregatorChannel;
    private Barrier $barrier;
    private bool $running = false;

    public function __construct(int $workers = 3) {
        $this->runtime = new Runtime();
        $this->barrier = new Barrier($workers + 1); // +1 for main thread

        $this->inputChannel = Channel::bounded(100, 'input');
        $this->validatorChannel = Channel::bounded(100, 'validator');
        $this->transformChannel = Channel::bounded(100, 'transform');
        $this->aggregatorChannel = Channel::bounded(10, 'aggregator');
    }

    public function process(array $data): array {
        $this->running = true;

        // 启动工作线程
        $this->runtime->run(new Task($this->validatorWorker()));
        $this->runtime->run(new Task($this->transformWorker()));
        $this->runtime->run(new Task($this->aggregatorWorker()));

        // 发送数据
        foreach ($data as $item) {
            $this->inputChannel->send($item);
        }
        $this->inputChannel->close();

        // 等待所有工作线程完成
        $this->barrier->wait();

        // 收集结果
        $results = [];
        while (!$this->aggregatorChannel->isEmpty()) {
            $results[] = $this->aggregatorChannel->recv();
        }

        $this->running = false;
        return $results;
    }

    private function validatorWorker(): callable {
        return function() {
            while (!$this->inputChannel->isEmpty()) {
                $item = $this->inputChannel->recv();

                // 验证数据
                if (isset($item['value']) && is_numeric($item['value'])) {
                    $this->validatorChannel->send($item);
                }
            }
            $this->validatorChannel->close();
        };
    }

    private function transformWorker(): callable {
        return function() {
            while (!$this->validatorChannel->isEmpty()) {
                $item = $this->validatorChannel->recv();

                // 转换数据
                $item['transformed'] = $item['value'] * 2;
                $item['processed_at'] = microtime(true);
                $this->transformChannel->send($item);
            }
            $this->transformChannel->close();
        };
    }

    private function aggregatorWorker(): callable {
        return function() {
            $processed = 0;

            while (!$this->transformChannel->isEmpty()) {
                $item = $this->transformChannel->recv();
                $this->aggregatorChannel->send($item);
                $processed++;
            }

            $this->aggregatorChannel->send(['_meta' => true, 'processed' => $processed]);
            $this->aggregatorChannel->close();
            $this->barrier->wait();
        };
    }

    public function close(): void {
        $this->runtime->close();
    }
}

// 使用
$pipeline = new DataPipeline(workers: 3);

$data = [];
for ($i = 1; $i <= 1000; $i++) {
    $data[] = ['id' => $i, 'value' => $i, 'category' => 'A'];
}

$results = $pipeline->process($data);
$pipeline->close();

echo "处理了 " . count($results) . " 条数据\n";
```

---

## 7. 并行任务调度器

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Sync\Semaphore;
use Kode\Parallel\Future\Future;

/**
 * 并行任务调度器
 * 支持优先级、限流、依赖管理
 */
class TaskScheduler {
    private Runtime $runtime;
    private Channel $taskChannel;
    private Channel $resultChannel;
    private Semaphore $semaphore;
    private array $futures = [];
    private array $completed = [];

    public function __construct(int $maxConcurrent = 5) {
        $this->runtime = new Runtime();
        $this->taskChannel = Channel::make('tasks');
        $this->resultChannel = Channel::make('results');
        $this->semaphore = new Semaphore($maxConcurrent);
    }

    public function submit(callable $task, array $args = [], int $priority = 0): self {
        $this->taskChannel->send([
            'task' => $task,
            'args' => $args,
            'priority' => $priority,
        ]);
        return $this;
    }

    public function execute(): array {
        $this->runtime->run(new Task(function() {
            while (!$this->taskChannel->isEmpty()) {
                $this->semaphore->acquire();

                $taskData = $this->taskChannel->recv();
                $task = $taskData['task'];
                $args = $taskData['args'];

                $future = $this->runtime->run(function($args) use ($task, $semaphore) {
                    $result = $task($args);
                    $semaphore->release();
                    return $result;
                }, array_merge($args, ['semaphore' => $this->semaphore]));

                $this->futures[] = $future;
            }
        }));

        // 等待所有任务完成
        $results = [];
        foreach ($this->futures as $future) {
            $results[] = $future->get();
        }

        return $results;
    }

    public function close(): void {
        $this->runtime->close();
    }
}

// 使用
$scheduler = new TaskScheduler(maxConcurrent: 3);

$scheduler
    ->submit(fn() => range(1, 1000), [], 1)
    ->submit(fn() => array_sum(range(1, 1000)), [], 2)
    ->submit(fn() => str_repeat('x', 100), [], 1)
    ->submit(fn() => json_encode(['a' => 1, 'b' => 2]), [], 3);

$results = $scheduler->execute();
print_r($results);

$scheduler->close();
```

---

## 8. 生产者消费者模式

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Sync\Semaphore;

/**
 * 生产者消费者模式
 * 支持多个生产者和多个消费者
 */
class ProducerConsumer {
    private Runtime $runtime;
    private Channel $workChannel;
    private Channel $resultChannel;
    private Semaphore $consumers;
    private int $producerCount;
    private int $consumerCount;

    public function __construct(int $producers = 2, int $consumers = 4) {
        $this->runtime = new Runtime();
        $this->producerCount = $producers;
        $this->consumerCount = $consumers;
        $this->workChannel = Channel::bounded(50, 'work');
        $this->resultChannel = Channel::make('results');
        $this->consumers = new Semaphore($consumers);
    }

    public function run(callable $producer, callable $consumer): array {
        // 启动消费者
        for ($i = 0; $i < $this->consumerCount; $i++) {
            $this->runtime->run(function() use ($consumer) {
                while (true) {
                    if ($this->workChannel->isEmpty() && !$this->workChannel->isEmpty()) {
                        break;
                    }

                    $item = $this->workChannel->recv();
                    if ($item === null) {
                        break;
                    }

                    $result = $consumer($item);
                    $this->resultChannel->send($result);
                }
            });
        }

        // 启动生产者
        for ($i = 0; $i < $this->producerCount; $i++) {
            $this->runtime->run(function() use ($producer, $i) {
                $items = $producer($i);
                foreach ($items as $item) {
                    $this->workChannel->send($item);
                }
            });
        }

        // 关闭工作通道
        $this->workChannel->close();

        // 收集结果
        $results = [];
        while (!$this->resultChannel->isEmpty()) {
            $results[] = $this->resultChannel->recv();
        }

        return $results;
    }

    public function close(): void {
        $this->runtime->close();
    }
}

// 使用
$pc = new ProducerConsumer(producers: 2, consumers: 4);

$results = $pc->run(
    // 生产者：生成数据
    function($producerId) {
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = [
                'producer' => $producerId,
                'item' => $i,
                'data' => str_repeat('x', 100),
            ];
        }
        return $items;
    },
    // 消费者：处理数据
    function($item) {
        return [
            'processed_by' => getmypid(),
            'original' => $item,
            'computed' => $item['item'] * 2,
        ];
    }
);

echo "处理了 " . count($results) . " 条数据\n";
$pc->close();
```

---

## 9. 工作池模式

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;
use Kode\Parallel\Sync\Barrier;

/**
 * 工作池模式
 * 预创建固定数量的工作线程
 */
class WorkerPool {
    private Runtime $runtime;
    private Channel $taskChannel;
    private Channel $resultChannel;
    private Barrier $barrier;
    private int $poolSize;
    private bool $shutdown = false;

    public function __construct(int $poolSize = 4) {
        $this->poolSize = $poolSize;
        $this->runtime = new Runtime();
        $this->taskChannel = Channel::bounded($poolSize * 2, 'tasks');
        $this->resultChannel = Channel::make('results');
        $this->barrier = new Barrier($poolSize + 1);

        // 创建工作线程
        for ($i = 0; $i < $poolSize; $i++) {
            $this->runtime->run($this->createWorker($i));
        }
    }

    private function createWorker(int $workerId): callable {
        return function() use ($workerId) {
            $processed = 0;

            while (!$this->shutdown) {
                try {
                    $task = $this->taskChannel->recvNonBlocking();

                    if ($task === null) {
                        if ($this->shutdown) {
                            break;
                        }
                        usleep(1000);
                        continue;
                    }

                    if ($task === 'SHUTDOWN') {
                        break;
                    }

                    $result = $task['handler']($task['data']);
                    $this->resultChannel->send([
                        'worker_id' => $workerId,
                        'result' => $result,
                        'task_id' => $task['id'] ?? null,
                    ]);
                    $processed++;
                } catch (\Throwable $e) {
                    error_log("Worker {$workerId} error: " . $e->getMessage());
                }
            }

            return "Worker {$workerId} processed {$processed} tasks";
        };
    }

    public function submit(callable $handler, mixed $data, ?string $taskId = null): self {
        $this->taskChannel->send([
            'handler' => $handler,
            'data' => $data,
            'id' => $taskId ?? uniqid('task_'),
        ]);
        return $this;
    }

    public function submitBatch(array $tasks): self {
        foreach ($tasks as $task) {
            if (is_callable($task)) {
                $this->submit($task, null);
            } elseif (is_array($task) && isset($task['handler'])) {
                $this->submit($task['handler'], $task['data'] ?? null, $task['id'] ?? null);
            }
        }
        return $this;
    }

    public function getResult(): ?array {
        return $this->resultChannel->recvNonBlocking();
    }

    public function getResults(int $count): array {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $result = $this->resultChannel->recv();
            if ($result !== null) {
                $results[] = $result;
            }
        }
        return $results;
    }

    public function shutdown(): array {
        $this->shutdown = true;

        // 发送关闭信号
        for ($i = 0; $i < $this->poolSize; $i++) {
            $this->taskChannel->send('SHUTDOWN');
        }

        // 收集所有结果
        $results = [];
        while (!$this->resultChannel->isEmpty()) {
            $results[] = $this->resultChannel->recv();
        }

        $this->runtime->close();
        return $results;
    }
}

// 使用
$pool = new WorkerPool(poolSize: 4);

// 提交任务
for ($i = 0; $i < 20; $i++) {
    $pool->submit(
        fn($data) => $data * $data,
        $i,
        "task_{$i}"
    );
}

// 收集结果
$results = $pool->getResults(20);
print_r($results);

// 关闭
$pool->shutdown();
```

---

这些高级用法展示了 kode/parallel 组件之间的灵活组合能力，可以根据具体业务需求选择合适的模式。
