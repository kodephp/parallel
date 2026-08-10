# Kode/Parallel 高级用法与结合案例

本文档展示如何结合使用 kode/parallel 的现有组件，实现复杂的并行编程模式。所有示例均使用当前
公开 API（`Concurrency\*` 引擎无关原语、`Futures` 组合器、`WorkerPool` 工作池、`ThreadPool` 多线程池（非阻塞派发）、`Channel` 单运行时通道）。

## 目录

- [1. Runtime + Futures 组合](#1-runtime--futures-组合)
- [2. Concurrency 同步原语组合](#2-concurrency-同步原语组合)
- [3. Runtime + Channel 组合（同运行时内）](#3-runtime--channel-组合同运行时内)
- [4. Futures 组合器协调多任务](#4-futures-组合器协调多任务)
- [5. Fiber + Runtime 组合](#5-fiber--runtime-组合)
- [6. 完整的数据处理流水线](#6-完整的数据处理流水线)
- [7. 并行任务调度器（WorkerPool）](#7-并行任务调度器workerpool)
- [8. 生产者消费者模式](#8-生产者消费者模式)
- [9. 工作池模式](#9-工作池模式)

---

## 1. Runtime + Futures 组合

### 1.1 并行计算 + 结果收集

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Future\Futures;

/**
 * 并行计算矩阵乘法（按行分块），用 Futures::all 聚合结果。
 * 注意：并行闭包内不要捕获 $this；所需数据一律通过 $args 传入。
 */
function parallelMatrixMultiply(array $matrixA, array $matrixB, int $workers = 4): array
{
    $runtime = new Runtime();
    $n = count($matrixA);
    $chunkSize = (int) ceil($n / $workers);
    $futures = [];

    for ($w = 0; $w < $workers; $w++) {
        $startRow = $w * $chunkSize;
        $endRow = min($startRow + $chunkSize, $n);
        if ($startRow >= $n) {
            break;
        }
        $futures[] = $runtime->run(static function (array $args): array {
            [$a, $b, $s, $e] = [$args['a'], $args['b'], $args['s'], $args['e']];
            $rows = [];
            for ($i = $s; $i < $e; $i++) {
                for ($j = 0; $j < count($b[0]); $j++) {
                    $sum = 0;
                    for ($k = 0; $k < count($a[0]); $k++) {
                        $sum += $a[$i][$k] * $b[$k][$j];
                    }
                    $rows[$i][$j] = $sum;
                }
            }
            return $rows;
        }, ['a' => $matrixA, 'b' => $matrixB, 's' => $startRow, 'e' => $endRow]);
    }

    $result = Futures::all($futures); // 保持提交顺序
    $runtime->close();
    return array_replace([], ...$result);
}
```

---

## 2. Concurrency 同步原语组合

### 2.1 带锁保护的共享计数（跨进程安全）

> `Concurrency\Lock`/`Atomic` 传 `$name` 时通过文件锁在多进程间协作；匿名（不传 `$name`）走内存快路径，
> 仅作用于同一运行时内部。下方示例用 `Atomic::named` 做跨进程计数。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\Semaphore;
use Kode\Parallel\Future\Futures;

$runtime = new Runtime();

$counter = Atomic::named(0, 'safe_counter');   // 跨进程共享计数器
$db = new Semaphore(3);                          // 最多 3 个并发

$futures = [];
for ($i = 0; $i < 100; $i++) {
    $futures[] = $runtime->run(static function (): int {
        $c = Atomic::named(0, 'safe_counter');
        $c->inc();
        return $c->get();
    });
}

Futures::all($futures);
echo "Final count: " . $counter->get() . "\n"; // 100
$runtime->close();
```

### 2.2 信号量控制的并发连接池

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Concurrency\Semaphore;

class ConnectionPool {
    private Semaphore $semaphore;
    public function __construct(int $maxConnections = 5) {
        $this->semaphore = new Semaphore($maxConnections);
    }
    public function execute(callable $task): mixed {
        return $this->semaphore->withPermits(1, $task); // 至多 $maxConnections 个并发
    }
}

$pool = new ConnectionPool(3);
$results = [];
for ($i = 0; $i < 10; $i++) {
    $results[] = $pool->execute(static function () use ($i) {
        usleep(100_000); // 模拟数据库查询
        return "Task {$i} completed";
    });
}
print_r($results);
```

> 更推荐直接用 `WorkerPool`（见第 9 节）做并发限流，无需手写信号量。

---

## 3. Runtime + Channel 组合（同运行时内）

`Concurrency\Channel` 是**单运行时**的消息通道（同一进程内的线程/协程之间）。`make()` 创建无界通道，
`bounded($cap)` 创建有界通道。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Concurrency\Channel;

$runtime = new Runtime();
$ch = Channel::make();

$runtime->run(static function () use ($ch) {
    $ch->send(range(1, 100));
});
$runtime->run(static function () use ($ch) {
    $ch->send(range(101, 200));
});

$sum = $runtime->run(static function () use ($ch) {
    return array_sum($ch->recv()) + array_sum($ch->recv());
});

echo "总和: " . $sum->get() . "\n"; // 20100
$runtime->close();
```

---

## 4. Futures 组合器协调多任务

> 原 `Events` 事件循环已移除；多任务协调统一用 `Futures` 组合子：`all`（全部成功）/ `settle`（全部结束）/
> `any`（首个成功）/ `race`（首个结束）/ `select`（非阻塞取首个就绪）。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Future\Futures;

$runtime = new Runtime();

$f1 = $runtime->run(static fn() => array_sum(range(1, 1_000_000)));
$f2 = $runtime->run(static fn() => 42 * 2);
$f3 = $runtime->run(static fn() => strtoupper('hello world'));

$results = Futures::all([$f1, $f2, $f3]);
print_r($results);
// ['compute_heavy' => 500000500000, 'compute_light' => 84, 'string_process' => 'HELLO WORLD']

// 非阻塞取第一个就绪的 future
$ready = Futures::select([$f1, $f2, $f3], timeoutMs: 1000);
echo "首个就绪: " . $ready->get() . "\n";

$runtime->close();
```

---

## 5. Fiber + Runtime 组合

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\Fiber;
use Kode\Parallel\Fiber\FiberManager;

$manager = new FiberManager();
$manager->spawn('task1', new Fiber(function () {
    $v = Fiber::suspend('step1');
    return "result: $v";
}));
$manager->startAll();
$results = $manager->collect();
print_r($results);
```

---

## 6. 完整的数据处理流水线

用 `WorkerPool` + `Futures` 表达「校验 → 转换 → 聚合」阶段，避免手写通道与屏障。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pool\WorkerPool;

$data = [];
for ($i = 1; $i <= 1000; $i++) {
    $data[] = ['id' => $i, 'value' => $i, 'category' => 'A'];
}

$pool = new WorkerPool(concurrency: 8);

$validated = $pool->mapSettled($data, static function (array $item): ?array {
    return isset($item['value'], $item['category']) && is_numeric($item['value'])
        ? $item : null;
});

$transformed = $pool->map(array_filter($validated), static function (?array $item): array {
    $item['transformed'] = $item['value'] * 2;
    $item['processed_at'] = microtime(true);
    return $item;
});

echo "处理了 " . count($transformed) . " 条数据\n";
$pool->close();
```

---

## 7. 并行任务调度器（WorkerPool）

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pool\WorkerPool;
use Kode\Parallel\Future\Futures;

$scheduler = new WorkerPool(concurrency: 3); // 最多 3 个并发

$futures = [
    $scheduler->submit(fn() => range(1, 1000)),
    $scheduler->submit(fn() => array_sum(range(1, 1000))),
    $scheduler->submit(fn() => str_repeat('x', 100)),
    $scheduler->submit(fn() => json_encode(['a' => 1, 'b' => 2])),
];

$results = Futures::all($futures);
print_r($results);
$scheduler->close();
```

---

## 8. 生产者消费者模式

> 跨进程的「生产者/消费者」建议用 `WorkerPool` 的 `map`/`mapSettled`（见第 9 节）；下面展示
> **单运行时内**用 `Channel` 做流式的写法（parallel 引擎下线程间通信）。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Concurrency\Channel;

$runtime = new Runtime();
$work = Channel::bounded(50);

// 生产者
$runtime->run(static function () use ($work) {
    for ($i = 0; $i < 100; $i++) {
        $work->send(['item' => $i, 'data' => str_repeat('x', 100)]);
    }
    $work->close();
});

// 消费者
$consumer = $runtime->run(static function () use ($work): array {
    $out = [];
    while (true) {
        $item = $work->recv();
        if ($item === null) {
            break;
        }
        $out[] = ['processed_by' => getmypid(), 'computed' => $item['item'] * 2];
    }
    return $out;
});

$results = $consumer->get();
echo "处理了 " . count($results) . " 条数据\n";
$runtime->close();
```

---

## 9. 工作池模式

`WorkerPool` 是引擎无关的预建工作池，自带并发上限、`map`/`mapSettled` 与运行统计，是绝大多数
「批量并行」场景的首选。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pool\WorkerPool;

$pool = new WorkerPool(concurrency: 4);

for ($i = 0; $i < 20; $i++) {
    $pool->submit(fn($data) => $data * $data, $i);
}

$results = $pool->wait();           // 阻塞收集全部结果（保持提交顺序）
print_r($results);

echo "stats: ";
print_r($pool->stats());            // engine / submitted / completed / failed / pending
$pool->close();
```

---

### 9.1 非阻塞多线程池 `ThreadPool`

若 `WorkerPool::submit()` 在槽位满时阻塞调用方不适合你的场景（如生产者远快于消费者、需预排海量任务、
或在协程/事件循环中派发），用 `ThreadPool`：其 `submit()` **永不阻塞**，任务进入进程内队列立即返回 Future，
由 N 个常驻 worker 线程在空闲时异步消费。

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pool\ThreadPool;

$pool = new ThreadPool(8);   // 8 个常驻工作线程

// 一口气预排，不阻塞调用方（实测 2 万任务 submit 合计 ≈18ms）
foreach ($jobs as $i => $job) {
    $futures[$i] = $pool->submit($job, ['x' => $i]);
}

$results = $pool->wait();    // 需要时再阻塞收集
$pool->close();
```

> `ThreadPool` 逐任务序列化、无 `WorkerPool` 的自动批量合并，原始吞吐低于 `WorkerPool`；其价值在非阻塞与常驻线程复用。
> 选型与实测见 [USE_CASES.md §十](USE_CASES.md) 与 [BENCHMARK.md §v1.18.0](BENCHMARK.md)。

---

这些高级用法展示了 kode/parallel 组件之间的灵活组合能力，可以根据具体业务需求选择合适的模式。
