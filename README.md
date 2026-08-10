# Kode/Parallel

高性能 PHP 并行并发库，为 PHP 8.3+ 提供简洁、健壮的并行编程接口。**以 `ext-parallel` 真线程为主线**：批量合并派发、逐元素容错、限并发 HTTP 扇出，多进程编排通过 `EngineFactory::register()` 接入 `kode/process` 等外部后端。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache--2.0-green)](LICENSE)
[![Package Version](https://img.shields.io/badge/Version-1.14.0-orange)](composer.json)
[![Engines](https://img.shields.io/badge/Engines-parallel%20%7C%20sync%20%7C%20pluggable-purple)](docs/ENGINE.md)

## 目录

- [简介](#简介)
- [功能特性](#功能特性)
- [执行引擎](#执行引擎)
- [系统要求](#系统要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [核心组件详解](#核心组件详解)
- [PHP 8.5 新特性](#php-85-新特性)
- [Fiber 协程](#fiber-协程)
- [批量合并与使用场景](#批量合并与使用场景)
- [性能压测](#性能压测)
- [最佳实践](#最佳实践)
- [常见问题](#常见问题)
- [文档索引](#文档索引)

---

## 简介

`kode/parallel` 是适用于 PHP 8.3+ 的高性能并行并发库，提供面向对象的高层 API、完整中文文档，以及 PHP 8.5 新特性的前向兼容实现。

自 **v1.6.0** 起引入引擎抽象层；**v1.7.0** 进一步提供引擎无关的同步原语（`Lock`/`Atomic`/`Barrier`/`Channel`，无需 ZTS/ext-parallel）与 `Future` 组合子。同一套代码在装了 `ext-parallel` 的机器上跑真线程（本库主线），在受限环境下退化为同步执行；**多进程编排不是本包职责**——需要时通过 `EngineFactory::register()` 接入 `kode/process` 等后端，与本库统一调度/自动探测无缝协作。

### 架构模型：多线程主线 + 可插拔后端

```
┌─────────────────────────────────────────────────────────────────┐
│                  本地并行 (多线程主线 + 可插拔后端)                  │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Runtime (并行执行上下文)                                  │   │
│  │    ├─ parallel 引擎：ext-parallel 真线程（ZTS PHP）【主线】│   │
│  │    ├─ sync     引擎：当前进程内顺序执行（回退/调试）        │   │
│  │    └─ 外部引擎：kode/process 等通过 register() 接入       │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### 核心能力

| 层级 | 能力 | 组件 |
|------|------|------|
| **执行引擎** | 真线程主线 + 同步回退 + 可插拔外部后端 | EngineFactory, ParallelEngine, SyncEngine |
| **本地并行** | 多线程执行 | Runtime, Task, Future, Futures, WorkerPool, Channel |
| **引擎无关同步原语** | 互斥/原子计数/屏障/通道（**无需 ext-parallel / ZTS**，对标 Swoole Thread） | Concurrency\Lock, Atomic, AtomicLong, Barrier, Channel, Semaphore |
| **协程支持** | Fiber 协程 | Fiber, FiberManager |
| **HTTP 并行** | 并行请求 | CurlMulti |

---

## 功能特性

| 组件 | 说明 |
|------|------|
| **Engine** | 引擎抽象：`parallel`（ext-parallel 真线程，主线）/ `sync`（同步回退）/ 可插拔外部后端（如 `kode/process`），自动探测 |
| **Runtime** | 并行执行上下文，屏蔽底层引擎差异 |
| **Task** | 并行任务闭包封装，含 ext-parallel 限制校验（可关闭） |
| **Future** | 异步任务返回值访问，统一 `FutureInterface` 契约，支持 `then` / `map` / `catch` 组合子 |
| **Futures** | 组合器：`all` / `settle` / `any` / `race` / `select`（非阻塞），全部支持超时 |
| **WorkerPool** | 引擎无关工作池：并发上限、`map` / `mapSettled`、运行统计 |
| **Channel** | 引擎无关单运行时消息通道，支持有/无界限（见 `Concurrency\Channel`） |
| **Fiber** | PHP Fiber 协程封装（基于 kode/fibers） |
| **Concurrency** | **引擎无关同步原语**：`Lock` / `Atomic` / `AtomicLong` / `Barrier` / `Channel` / `Semaphore`，无需 ext-parallel / ZTS，对标 Swoole 6 `Thread\Lock/Atomic/Barrier/Queue` |
| **Pipe** | 进程间通信管道 |
| **CurlMulti** | 并行 HTTP 请求封装 |
| **并发原语（引擎无关）** | `Lock` / `Atomic` / `AtomicLong` / `Barrier` / `Channel` / `Semaphore` —— 无需 ext-parallel / ZTS |
| **Util** | PHP 8.5 兼容工具、`Sys` 系统探测（CPU 核心数、推荐并发度） |
| **Installation** | 环境自检与诊断报告（引擎可用性、扩展、版本） |
| **CLI** | `vendor/bin/kode-parallel doctor \| info \| bench` |
| **集成组件** | **kode 生态集成** |
| **FiberCoordinator** | Fiber 协调器（集成 kode/fibers） |
| **ContextualRuntime** | 上下文感知运行时（集成 kode/context） |

---

## 系统要求

| 要求 | 说明 |
|------|------|
| PHP 版本 | **>= 8.3**（使用类型化类常量、`json_validate()`、`#[\Override]` 等特性） |
| 必需包 | kode/context ^3.0, kode/facade ^3.0, kode/fibers ^4.1 |
| 推荐扩展 | **ext-parallel（真线程，主线能力）**、ext-curl（CurlMulti 扇出） |

> 未装 ext-parallel 时 API 完全一致，只是退化为顺序执行；要发挥本库性能请部署 **ZTS + ext-parallel**。
> 需要多进程隔离时，通过 `EngineFactory::register()` 接入 `kode/process`。

### PHP 版本适配

| PHP 版本 | 支持状态 | 特性 |
|----------|---------|------|
| < 8.3 | ❌ 不再支持 | 请使用 v1.5.x |
| 8.3 | ✅ 完全支持 | 类型化类常量、`json_validate()`、`#[\Override]` |
| 8.4 | ✅ 完全支持 | 属性钩子、改进性能 |
| 8.5 | ✅ 最佳支持 | 管道操作符、Clone With、持久化 cURL |

---

## 安装

### 1. 安装 Composer 包

```bash
composer require kode/parallel
```

自动安装依赖：kode/fibers, kode/context, kode/facade。**无需任何 PECL 扩展即可开始使用。**

### 2. 检查运行环境

```bash
vendor/bin/kode-parallel doctor   # 输出引擎可用性、CPU 核心数、扩展状态
vendor/bin/kode-parallel bench 8  # 用当前引擎跑一次并行吞吐
```

### 3.（可选）安装 ext-parallel 获得真线程

```bash
# 需要线程安全（ZTS）构建的 PHP
pecl install parallel

# 或从源码编译
git clone https://github.com/krakjoe/parallel.git
cd parallel && phpize && ./configure && make && sudo make install
```

在 `php.ini` 中添加 `extension=parallel`，随后 `doctor` 的当前引擎会自动变为 `parallel`。

---

## 快速开始

### 0. 一行并行（推荐入口）

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\map;
use function Kode\Parallel\engine;

// 自动按 CPU 核心数并行处理，结果保持输入键序
$sizes = map(['a.jpg', 'b.jpg', 'c.jpg'], static fn(string $file): int => strlen($file));

echo '当前引擎: ' . engine() . PHP_EOL;   // parallel / process / sync
print_r($sizes);
```

### 1. 本地并行

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;

$runtime = new Runtime();

// 并行执行 5 个任务
$futures = [];
for ($i = 0; $i < 5; $i++) {
    $futures[] = $runtime->run(
        fn($args) => $args['n'] * $args['n'],
        ['n' => $i + 1]
    );
}

foreach ($futures as $f) {
    echo "结果: " . $f->get() . "\n";
}

$runtime->close();
```

### 2. Channel 通信

```php
<?php
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Concurrency\Channel;

$runtime = new Runtime();
$ch = Channel::make();

$runtime->run(fn() => $ch->send(range(1, 100)));
$runtime->run(fn() => $ch->send(range(101, 200)));

$sum = fn() => array_sum($ch->recv()) + array_sum($ch->recv());
echo "总和: " . $runtime->run($sum)->get() . "\n";

$runtime->close();
```

### 3. Fiber 协程

```php
<?php
use Kode\Parallel\Fiber\Fiber;

$fiber = new Fiber(function() {
    $value = Fiber::suspend('暂停');
    return "收到: $value";
});

echo $fiber->start() . "\n";       // 暂停
echo $fiber->resume('恢复数据') . "\n"; // 收到: 恢复数据
```

---

## 执行引擎

v1.6.0 起所有并行入口都构建在引擎抽象之上，按优先级自动探测：

| 引擎 | 前提 | 并行方式 | 适用场景 |
|------|------|---------|---------|
| `parallel` | ext-parallel（ZTS PHP） | 真线程，共享进程内存 | 生产环境最佳性能（**本库主线**） |
| `sync` | 无 | 当前进程内顺序执行 | Windows / 受限环境 / 调试 |
| 外部引擎 | 由 `register()` 接入 | 取决于后端（如 `kode/process` 多进程） | 需要多进程编排时 |

```php
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Runtime\Runtime;

EngineFactory::available();            // ['parallel' => true, 'sync' => true]  (ZTS + ext-parallel)
EngineFactory::detect();               // 'parallel'

$runtime = new Runtime();              // 自动选择（真线程）
$runtime = new Runtime(null, 'sync');  // 显式指定同步回退

// 需要多进程时，把 kode/process 接入统一调度（详见 docs/ENGINE.md）
EngineFactory::register(
    name: 'process',
    factory: static fn(?string $bootstrap, int $workers) => new MyProcessEngine($bootstrap, $workers),
    supported: static fn(): bool => extension_loaded('pcntl'),
);
```

也可用环境变量强制指定，便于 CI 分别验证：

```bash
KODE_PARALLEL_ENGINE=sync composer test
```

### Futures 组合器

```php
use Kode\Parallel\Future\Futures;

$futures = $runtime->runAll([
    fn(array $a) => file_get_contents('https://example.com/a'),
    fn(array $a) => file_get_contents('https://example.com/b'),
]);

$results  = Futures::all($futures, timeoutMs: 5000);   // 任一失败即抛出
$settled  = Futures::settle($futures);                 // 全部结束，返回状态数组
$fastest  = Futures::any($futures, 3000);              // 第一个成功的结果
```

### WorkerPool 工作池

```php
use Kode\Parallel\Pool\WorkerPool;

$pool = new WorkerPool(concurrency: 8);

$results = $pool->map(range(1, 100), static fn(int $n): int => $n * $n);
$report  = $pool->mapSettled($urls, $fetch);   // 不因单个失败中断
$stats   = $pool->stats();                     // engine / submitted / completed / failed / pending

$pool->close();
```

> **多线程（parallel 引擎）无需序列化返回值**：任务闭包与上下文共享同一进程内存，闭包捕获的
> 对象/资源即在同一进程内，取回结果不经过 IPC 序列化；这与多进程模型（kode/process）有本质区别——
> 后者需跨进程序列化。需要跨进程共享状态时仍可使用 `Concurrency\*` 原语或外部存储。

### 引擎无关同步原语（Concurrency）

v1.7.0 新增的 `Kode\Parallel\Concurrency\*` 系列，**不依赖 ext-parallel / ZTS**，在 stock PHP CLI
（process / sync 引擎）下即可使用，对标 Swoole 6 的 `Thread\*` 原语——区别在于 kode/parallel 还
能在「非 ZTS、无 ext-parallel」的普通 PHP 上运行，而 Swoole 的多线程必须 ZTS 构建。

```php
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;

// 互斥锁（可命名，跨进程共享）
$lock = Lock::named('order');
$lock->withLock(function () {
    // 临界区
});

// 原子计数器（跨进程安全的自增，CAS 支持）
$counter = new Atomic(0);
$counter->inc();
$counter->compareAndSwap(1, 10);

// 屏障：N 个参与者到齐后整体放行，并自动进入下一代
$barrier = Barrier::named(4, 'phase-1');
$barrier->wait();

// 单运行时消息通道（有界/无界）
$ch = Channel::bounded(8);
$ch->send($item);
$item = $ch->recv();
```

> 命名原语（传 `$name`）通过共享文件 + `flock` 在多进程间协作；匿名原语作用于同一运行时内部。

### Future 组合子与 select

`FutureInterface` 自 v1.7.0 起支持链式组合，惰性解析、对所有引擎通用：

```php
use Kode\Parallel\Future\Futures;

$future = runtime()->run(fn($a) => $a['x'] + 1, ['x' => 41]);

$chained = $future
    ->then(fn($v) => $v * 2)        // 成功时变换
    ->map(fn($v) => "result:$v")    // 同 then，仅做映射
    ->catch(fn($e) => "fallback");  // 失败时兜底

echo $chained->get();               // "result:84"

// 非阻塞选择：返回第一个已就绪的 Future（对标 parallel\Events::poll / Swoole Channel::select）
$ready = Futures::select([$f1, $f2, $f3], timeoutMs: 1000);
```

### 对比同类方案

| 维度 | **kode/parallel** | Swoole 6.2（Thread） | ext-parallel | pthreads |
|------|-------------------|----------------------|--------------|----------|
| 并行模型 | 真线程（ext-parallel）+ 可插拔外部进程后端 | 真线程（ZTS） | 真线程（ZTS） | 真线程（ZTS，已废弃） |
| 批量合并派发 | ✅ map/mapBatch（高频短任务自动批量合并） | ❌ 需自行分片 | ❌ | ❌ |
| 逐元素容错 | ✅ `mapBatchSettled` | ⚠️ 自行 try/catch | ⚠️ | ❌ |
| 统一 Future 契约 | ✅ `FutureInterface` | ❌ 线程对象 `join()` | 部分（`parallel\Future`） | ❌ |
| 组合器 / select | ✅ all/settle/any/race/select | ❌ | ⚠️ 仅 `Events` | ❌ |
| 同步原语 | ✅ Lock/Atomic/Barrier/Channel（引擎无关，**跨进程**） | ✅ Lock/Atomic/Map/Queue（**同进程共享内存**） | ✅ Mutex/Semaphore/Cond/Barrier | ⚠️ 同步方法 |
| 工作池 | ✅ 引擎无关 `WorkerPool` | ⚠️ `Thread\Pool` | ❌（需自管 Runtime） | ❌ |
| 协程 | ✅ Fiber 集成 | ✅ 协程 | ❌ | ❌ |
| 最低 PHP | 8.3 | 8.1–8.5（线程需 ZTS） | 7.2（ZTS） | 7.2（ZTS） |

> 对标版本：Swoole **6.2.2**（2026-07，6.x 最新稳定版）。其原生线程必须 ZTS + `--enable-swoole-thread` 编译，
> 且需禁用 pthreads；kode/parallel 的 `Concurrency\*` 原语在**普通非 ZTS PHP** 上即可运行，并天然**跨进程**共享状态——
> 这是与 Swoole 线程（进程内共享内存）的根本差异。详见 [docs/SWOOLE_COMPARISON.md](docs/SWOOLE_COMPARISON.md)。

**结论**：三者在 ZTS 下同为真线程，kode/parallel 的增量价值在于**上层工程能力**——自动批量合并派发
（`map`/`mapBatch`，v1.14.0 起 `map` 自动批量，强制逐条 → 自动批量提速约 1.6×、相对 v1.13.0 逐条约 41×）、逐元素容错（`mapBatchSettled`）、
Future 组合子与 select、引擎无关且跨进程的同步原语，以及限并发 HTTP 扇出（`CurlMulti`，并发 16~32 实测 10~15×）。

---

## 核心组件详解

### Runtime - 运行时

```php
$runtime = new Runtime();
$runtime = new Runtime('/path/to/bootstrap.php');

$future = $runtime->run($task, $args);
$future = $runtime->run(fn($a) => $a['x'] + $a['y'], ['x' => 1, 'y' => 2]);

$runtime->isRunning();
$runtime->getBootstrap();
$runtime->close();
```

### Channel - 通道

```php
use Kode\Parallel\Concurrency\Channel;

// 无界限通道
$ch = Channel::make();

// 有界限通道（容量为10）
$ch = Channel::bounded(10);

// 发送/接收
$ch->send($data);
$data = $ch->recv();
$ch->close();
$ch->isEmpty();
```

### Task - 任务

Task 中**禁止**使用：yield、引用传递、类声明、命名函数。

### Future - 异步结果

```php
$future->get();        // 阻塞获取
$future->getOrNull();   // 非阻塞获取
$future->wait(1000);    // 等待1秒
$future->cancel();      // 取消任务
```

---

## PHP 8.5 新特性

### 管道操作符（Pipe Operator）

```php
<?php
use function Kode\Parallel\Util\pipe;

// 类似 Unix 的管道操作
$result = pipe(
    '  Hello World  ',
    'trim',
    'strtoupper',
    fn($s) => str_replace('WORLD', 'PHP', $s)
);

echo $result; // 输出: HELLO PHP
```

### Clone With

```php
<?php
use function Kode\Parallel\Util\clone_with;

class Color {
    public function __construct(
        public int $red,
        public int $green,
        public int $blue,
        public int $alpha = 255
    ) {}
}

$blue = new Color(79, 91, 147);
$transparent = clone_with($blue, ['alpha' => 128]);
```

### 持久化 cURL（PHP 8.5）

```php
// CurlMulti 自动支持连接复用
$curl = new CurlMulti();
$curl->get('https://api.example.com/1');
$curl->get('https://api.example.com/2');
$curl->get('https://api.example.com/3');
$results = $curl->execute(); // 自动复用连接
```

---

## Fiber 协程

### 基本用法

```php
<?php
use Kode\Parallel\Fiber\Fiber;
use Kode\Parallel\Fiber\FiberManager;

// 单个 Fiber
$fiber = new Fiber(function($input) {
    $step1 = Fiber::suspend('第一步完成');
    return "最终结果: {$step1}";
});

$fiber->start();
$result = $fiber->resume('恢复数据');

// Fiber 管理器
$manager = new FiberManager();
$manager->spawn('task1', fn() => compute1());
$manager->spawn('task2', fn() => compute2());
$manager->startAll();
$results = $manager->collect();
```

详见 [FIBER.md](docs/FIBER.md)

---

## 高级用法

### 1. Concurrency 引擎无关同步原语

```php
<?php
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Concurrency\Semaphore;
use Kode\Parallel\Concurrency\Atomic;

// Lock - 互斥锁（可命名，跨进程共享）
$lock = Lock::named('order');
$lock->withLock(function() {
    // 临界区代码
});

// Semaphore - 信号量（限流，最多3个并发）
$sem = new Semaphore(3);
$sem->withPermits(1, function() {
    // 限流执行
});

// Atomic - 原子计数器（跨进程安全自增 + CAS）
$counter = new Atomic(0);
$counter->inc();
$counter->compareAndSwap(1, 10);
```

### 2. Pipe 管道

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('my_pipe');
$pipe->write('Hello');
$data = $pipe->read();
```

详见 [PIPE.md](docs/PIPE.md)

### 3. CurlMulti 并行请求

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();
$curl->get('https://api.example.com/users');
$curl->post('https://api.example.com/posts', ['title' => 'Hello']);
$results = $curl->execute();
```

详见 [CURL.md](docs/CURL.md)

### 4. 生产者消费者模式

```php
<?php
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Concurrency\Channel;

$runtime = new Runtime();
$channel = Channel::bounded(10);

$producer = fn() => produce($channel);
$consumer = fn() => consume($channel);

$runtime->run($producer);
$runtime->run($consumer);
```

详见 [ADVANCED_USAGE.md](docs/ADVANCED_USAGE.md)

---

## 批量合并与使用场景

### 高频短任务：`map` 自动批量，或显式 `mapBatch`

`map()` 逐条派发时每条都要序列化一次闭包与参数；从 v1.14.0 起，`map()` / `mapSettled()` 在
**元素数 > 并发度 × 4** 时**自动走批量合并快速路径**——无需改 API 即获提速（trivial 综合约 39× 于 v1.13.0 逐条）。
需要逐元素容错或显式调批大小时，用 `mapBatch()` / `mapBatchSettled()`。

`mapBatch()` 把一批元素合并为一次提交，**只序列化一次**。v1.14.0 起 `map()` 在元素较多时自动走同一路径，
无需改 API；可复现收益见 `bench_batch`：强制逐条 6.4M → 自动批量 10.3M ops/s（1.6×）。
注意：**单条工作量 <1μs 的高频短任务并行≈串行**（派发/序列化开销与之同量级，倍率受调度抖动在 0.7~1.4× 波动），
此时 `map()` 与 `mapBatch()` 表现一致、不会更慢，价值在于「不阻塞主流程」而非「更快」。

```php
use Kode\Parallel\Pool\WorkerPool;

$pool = new WorkerPool(11);                       // 线程数 ≈ CPU 核数

// 直接 map() 即可，元素较多时内部自动批量合并；批大小 auto（元素数 / 线程数 / 4，封顶 1024）
$results = $pool->map($messages, static fn (mixed $m): string => render($m));

// 群发场景用容错版：单条失败不连累同批其他元素
$settled = $pool->mapBatchSettled($recipients, static fn (array $u): string => send($u));

foreach ($settled as $key => $r) {
    if ($r['status'] === 'rejected') {
        // reason 恒为 ParallelException；原始异常类名见 getContext()['class']
        $retry[$key] = $r['reason']->getMessage();
    }
}

$pool->close();
```

函数式写法：`map_batch($items, $worker)` / `map_batch_settled($items, $worker)`。

### 多用户 HTTP 扇出：`CurlMulti` 限并发

```php
use Kode\Parallel\Curl\CurlMulti;

$urls = [];
foreach ($users as $u) {
    $urls['u' . $u['id']] = "https://api.example.com/notify?uid={$u['id']}";
}

// 滑动窗口：始终保持 16 个在途连接，完成一个补一个
$results = CurlMulti::fetch($urls, concurrency: 16, timeout: 30);
```

纯等网络用 `CurlMulti`（并发 16~32 实测 10~15×，内存最省）；**不限并发会比顺序还慢**（洪泛触发连接风暴，0.4×）。
要「边请求边算」再用线程 `mapBatch`。

### 业务约束速查

| 业务 | 做法 |
|------|------|
| 群发邮件 / 站内信 / 推送 | `mapBatchSettled` + 幂等键，只重试失败项 |
| 秒结分佣（实时到账） | **不要并行**，同事务顺序处理，链路短 |
| 定时批量结算 | 按 `account_id` 分组：**组间并行、组内串行**；等级/价格不同的记录**不可合并计算** |
| 千万级跑批 | 先按 `account_id % M` 分段，段内并行、段间顺序 |
| 线程内依赖类 | 构造池时传 bootstrap：`new WorkerPool(11, null, __DIR__.'/vendor/autoload.php')` |
| 线程内数据库 | 连接不可跨线程传递，任务内新建；每任务自包含事务 |

完整场景与代码范式见 [USE_CASES.md](docs/USE_CASES.md)。

---

## 性能压测（v1.14.0，ZTS + ext-parallel 真线程 主线，实测可复现于 PHP 8.3.33 / 11 核）

### 群发消息三档数据（`bench_message.php`，10 线程，批大小 auto）

| 数据规模 | 条数 | 串行 | 逐条 `map`（自动批量） | **批量 `mapBatch`** | 4 进程 × 10 线程 |
|---------|------|------|----------------------|--------------------|-----------------|
| 短 120B（推送通知） | 50,000 | 1,070,000 msg/s | ≈1.0× * | **≈1.0× *** | 1,550,000（1.5×） |
| 中 4KB（站内信） | 20,000 | 118,000 msg/s | 461,000（3.9×） | **473,000（4.0×）** | 500,000（4.2×） |
| 大 64KB（富文本邮件） | 2,000 | 8,070 msg/s | 49,800（6.2×） | **51,600（6.4×）** | 38,000（4.7×） |

> \* 短数据（单条 <1μs）并行开销与任务同量级，倍率受调度抖动在 0.7~1.4× 波动（多次运行 `map`/`mapBatch` 互相颠倒），
> 并非稳定负优化；可复现的批量合并收益见 `bench_batch`（强制逐条 6.4M → 自动批量 10.3M，1.6×）。

本机最优配置（`bench_tune.php` 网格扫描，串行基线取 3 轮最快）：

| 负载 | 串行基线 | 单进程最优 | 进程 × 线程最优 |
|------|---------|-----------|----------------|
| 短 120B × 50,000 | 1,526,345 msg/s | 8 线程 + 批 512 → 1,839,865（1.2×） | 8 进程 × 2 线程 → 2,126,045（1.4×） |
| 中 4KB × 20,000 | 118,492 msg/s | **11 线程 + 批 128 → 599,863（5.1×）** | 4 进程 × 4 线程 → 617,954（5.2×） |
| 大 64KB × 2,000 | 8,078 msg/s | **22 线程 + 批 32 → 48,899（6.1×）** | 8 进程 × 2 线程 → 44,303（5.5×） |

> **线程数 ≈ 核数即最优**；中/大数据下单进程多线程优于任何多进程组合（省掉 fork 与 IPC）。
> 若已用 `kode/process` 起了 4 个进程，每进程再开 2~3 线程即可，**不要每进程再开 10 线程**。

### 多用户 HTTP 扇出（`bench_curl.php` 自带本地并发服务，200 请求 × 20ms 延迟）

| 方式 | 耗时 | 吞吐 | 相对顺序理论下限（200×20ms=4000ms） |
|------|------|------|---------|
| 顺序 curl | 5434 ms | 37 req/s | 1.4× |
| `CurlMulti` 不限并发（一次 200 连接） | 10019 ms | 20 req/s | **0.4×（比顺序还慢）** |
| `CurlMulti` 并发 16 | 395 ms | 506 req/s | 10.1× |
| `CurlMulti` 并发 32 | 270 ms | 741 req/s | 14.8× |
| parallel 线程 × 8 + curl | 776 ms | 258 req/s | 5.2× |

> 最优并发档在 16~32 间波动（多次运行 x16≈10×、x32≈15× 会互相颠倒），经验值 8~32，上线前用 `bench_curl.php` 本机扫一遍取最优点。

### 单进程 vs 多线程 vs 多进程（同一份 CPU 任务，N=2000 独立单元，越高的 ops/s 越好）

| 工作负载 | 单进程(1线程) | 多线程 parallel(最优) | 多进程 fork池(最优) | 多线程加速比 |
|---------|-------------|--------------------|--------------------|------------|
| trivial（仅 return） | **19.8M/s** | 432k/s（x2） | 1.6M/s（x1） | 0.02×（反而更慢） |
| light（2k 次循环） | 51k/s | 162k/s（x8） | 194k/s（x8） | 3.2× |
| medium（20万次 sqrt/sin） | 104/s | 508/s（x8） | 641/s（x8） | 4.9× |

> **关键结论（实测）**：
> - **任务派发开销**：每单元独立 fork 的多进程 ≈ **3.4k ops/s**，而多线程 `submit+get` ≈ **233k ops/s** →
>   多线程快约 **68×（可达百倍）**。差距来自 fork + 序列化 + IPC，而真线程共享内存、零重建。
> - **稳态并发（worker 池）**：轻/中负载下多线程与多进程都随核心数**近似线性加速**（8 核 ~5–6×），二者量级相当
>   （多线程约为多进程的 0.8×）。多线程胜在共享内存、无数据拷贝、编程模型更简单。
> - **琐碎任务**：单进程顺序执行反而最快（零调度开销）——并行仅在「处理量足以摊销调度成本」时才有收益。
> - **最优配置**：多线程线程数 ≈ CPU 逻辑核心数（`Runtime(null,'parallel', cores)`）；超过核心数不再提速。

### 引擎无关同步原语（与引擎无关，多引擎下一致）

```
Concurrency\Channel send+recv    ≈ 14M ops/s   (进程内，纯内存)
Concurrency\Lock withLock 自增   ≈ 110k ops/s  (跨进程文件锁)
Concurrency\Atomic 进程内 inc     ≈ 13.6M ops/s (内存快路径)
Concurrency\Atomic 跨进程 inc     ≈ 16.7k ops/s (6 进程 ×5k，零丢失)
Concurrency\Semaphore 进程内      ≈ 7.1M ops/s  (acquire+release)
Concurrency\Barrier 跨进程会合    ≈ 431 回合/s  (4 方 ×50 回合)
```

> 同口径基线脚本（可并排比较）：
> `bench_compare.php`（单进程/多线程/多进程）｜`bench_batch.php`（批量 vs 逐条）｜`bench_message.php`（群发三档数据）｜
> `bench_tune.php`（配置寻优网格）｜`bench_curl.php`（HTTP 扇出）｜`bench_concurrency.php`（引擎无关原语）｜
> `bench_pcntl.php`（裸 pcntl 地板）｜`bench_swoole.php`（Swoole 6.2 线程，需 ZTS）｜`bench_ext_parallel.php`（ext-parallel 真线程）。

**业务怎么选**见 [USE_CASES.md](docs/USE_CASES.md)（群发通知 / 分佣结算 / HTTP 扇出 / 线程内约束）；
调优方法与版本自对比记录见 [PERFORMANCE.md](docs/PERFORMANCE.md)；完整数据见
[BENCHMARK.md](docs/BENCHMARK.md)、[PROCESS_VS_THREAD.md](docs/PROCESS_VS_THREAD.md) 与 [SWOOLE_COMPARISON.md](docs/SWOOLE_COMPARISON.md)。

---

## 最佳实践

### 任务设计

```php
// ✅ 推荐：简单、单一职责
$task = new Task(fn($args) => processData($args['data']));

// ❌ 避免：复杂业务逻辑
$task = new Task(function($args) {
    // 大量代码...
});
```

### 错误处理

```php
try {
    $runtime = new Runtime('/invalid/path.php');
} catch (ParallelException $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
```

### 资源管理

```php
$runtime = new Runtime();
try {
    $result = $runtime->run($task)->get();
} finally {
    $runtime->close();
}
```

---

## 常见问题

### Q: Task 中不能使用 yield？

```php
// ❌ 不行
$task = new Task(function() { yield 1; });

// ✅ 改用返回数组
$task = new Task(function() { return [1, 2, 3]; });
```

### Q: 如何传递大数据？

```php
// ✅ 使用 Channel
$channel = Channel::make();
$runtime->run(fn($args) => $args['ch']->send($data), ['ch' => $channel]);
```

### Q: Fiber 和 Thread 的区别？

| 特性 | Fiber | Thread |
|------|-------|--------|
| 调度 | 用户态 | 内核态 |
| 切换开销 | 微秒级 | 毫秒级 |
| 共享内存 | 不共享 | 共享 |

---

## 文档索引

| 文档 | 内容 |
|------|------|
| [README](README.md) | 项目概述和快速开始 |
| [DEVELOPMENT.md](docs/DEVELOPMENT.md) | 开发指南和 API 参考 |
| [FIBER.md](docs/FIBER.md) | Fiber 协程详解 |
| [PIPE.md](docs/PIPE.md) | Pipe 管道详解 |
| [USE_CASES.md](docs/USE_CASES.md) | **使用场景与选型**：群发通知、分佣结算、HTTP 扇出、线程内约束 |
| [CURL.md](docs/CURL.md) | CurlMulti 并行请求 |
| [ADVANCED_USAGE.md](docs/ADVANCED_USAGE.md) | 高级用法和案例 |
| [PTHREADS_COMPARISON.md](docs/PTHREADS_COMPARISON.md) | 与 pthreads 对比 |
| [PCNTL_COMPARISON.md](docs/PCNTL_COMPARISON.md) | 与 pcntl 对比 |
| [SWOOLE_COMPARISON.md](docs/SWOOLE_COMPARISON.md) | 与 Swoole 对比 |
| [BENCHMARK.md](docs/BENCHMARK.md) | 完整性能压测数据 |
| [PERFORMANCE.md](docs/PERFORMANCE.md) | 调优方法与最佳实践 |

---


## 许可证

Apache-2.0

## 相关链接

- [PHP parallel 扩展官方文档](https://www.php.net/manual/zh/book.parallel.php)
- [KodePHP 官方仓库](https://github.com/kodephp)
- [kode/fibers 包](https://github.com/kodephp/fibers)
- [PHP 8.5 新特性](https://www.php.net/releases/8.5/zh.php)
