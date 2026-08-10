# Kode/Parallel

高性能 PHP 并行并发库，为 PHP 8.3+ 提供简洁、健壮的并行编程接口。**多引擎自动降级**：有 `ext-parallel` 用真线程，没有扩展也能靠多进程跑并行。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache--2.0-green)](LICENSE)
[![Package Version](https://img.shields.io/badge/Version-1.11.0-orange)](composer.json)
[![Engines](https://img.shields.io/badge/Engines-parallel%20%7C%20process%20%7C%20sync-purple)](docs/ENGINE.md)

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
- [性能压测](#性能压测)
- [最佳实践](#最佳实践)
- [常见问题](#常见问题)
- [文档索引](#文档索引)

---

## 简介

`kode/parallel` 是适用于 PHP 8.3+ 的高性能并行并发库，提供面向对象的高层 API、完整中文文档，以及 PHP 8.5 新特性的前向兼容实现。

自 **v1.6.0** 起引入引擎抽象层；**v1.7.0** 进一步提供引擎无关的同步原语（`Lock`/`Atomic`/`Barrier`/`Channel`，无需 ZTS/ext-parallel）与 `Future` 组合子。同一套代码在装了 `ext-parallel` 的机器上跑真线程，在只有 `pcntl` 的机器上自动降级为多进程，在 Windows 等受限环境下退化为同步执行——**扩展从硬依赖变为可选加速项**。

### 架构模型：多引擎并行

```
┌─────────────────────────────────────────────────────────────────┐
│                      本地并行 (多引擎自动降级)                     │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Runtime (并行执行上下文)                                  │   │
│  │    ├─ parallel 引擎：ext-parallel 真线程（ZTS PHP）       │   │
│  │    ├─ process  引擎：pcntl fork 多进程 + socket 回传      │   │
│  │    └─ sync     引擎：当前进程内顺序执行（回退/调试）       │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### 核心能力

| 层级 | 能力 | 组件 |
|------|------|------|
| **执行引擎** | 多引擎自动降级 | EngineFactory, ParallelEngine, ProcessEngine, SyncEngine |
| **本地并行** | 多线程 / 多进程执行 | Runtime, Task, Future, Futures, WorkerPool, Channel |
| **引擎无关同步原语** | 互斥/原子计数/屏障/通道（**无需 ext-parallel / ZTS**，对标 Swoole Thread） | Concurrency\Lock, Atomic, AtomicLong, Barrier, Channel, Semaphore |
| **协程支持** | Fiber 协程 | Fiber, FiberManager |
| **HTTP 并行** | 并行请求 | CurlMulti |

---

## 功能特性

| 组件 | 说明 |
|------|------|
| **Engine** | 引擎抽象：`parallel`（真线程）/ `process`（pcntl 多进程）/ `sync`（同步回退），自动探测 |
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
| 可选扩展 | ext-parallel（真线程）、ext-pcntl + ext-posix（多进程）、ext-curl（CurlMulti） |

> 一个扩展都不装也能运行：库会自动选择可用引擎，只是并行度不同。

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
| `parallel` | ext-parallel（ZTS PHP） | 真线程，共享进程 | 生产环境最佳性能 |
| `process` | ext-pcntl（类 UNIX） | `fork` 多进程 + socket 回传 | 无扩展时的默认并行方案 |
| `sync` | 无 | 当前进程内顺序执行 | Windows / 受限环境 / 调试 |

```php
use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Runtime\Runtime;

EngineFactory::available();            // ['parallel' => false, 'process' => true, 'sync' => true]
EngineFactory::detect();               // 'process'

$runtime = new Runtime();              // 自动选择
$runtime = new Runtime(null, 'sync');  // 显式指定
EngineFactory::setDefault('process');  // 全局强制
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

> **process 引擎注意事项**：任务返回值必须可序列化；子进程内的内存修改不会回传父进程；
> 需要共享状态时请使用 Channel 或外部存储。

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
| 并行模型 | 真线程 / 多进程 / 同步回退 | 真线程（ZTS） | 真线程（ZTS） | 真线程（ZTS，已废弃） |
| **无需 ZTS / 扩展** | ✅ process/sync 引擎开箱即用 | ❌ 必须 ZTS + `--enable-swoole-thread` | ❌ 必须 ZTS | ❌ 必须 ZTS |
| 统一 Future 契约 | ✅ `FutureInterface` | ❌ 线程对象 `join()` | 部分（`parallel\Future`） | ❌ |
| 组合器 / select | ✅ all/settle/any/race/select | ❌ | ⚠️ 仅 `Events` | ❌ |
| 同步原语 | ✅ Lock/Atomic/Barrier/Channel（引擎无关，**跨进程**） | ✅ Lock/Atomic/Map/Queue（**同进程共享内存**） | ✅ Mutex/Semaphore/Cond/Barrier | ⚠️ 同步方法 |
| 工作池 | ✅ 引擎无关 `WorkerPool` | ⚠️ `Thread\Pool` | ❌（需自管 Runtime） | ❌ |
| 协程 | ✅ Fiber 集成 | ✅ 协程 | ❌ | ❌ |
| 最低 PHP | 8.3 | 8.1–8.5（线程需 ZTS） | 7.2（ZTS） | 7.2（ZTS） |

> 对标版本：Swoole **6.2.2**（2026-07，6.x 最新稳定版）。其原生线程必须 ZTS + `--enable-swoole-thread` 编译，
> 且需禁用 pthreads；kode/parallel 的 `Concurrency\*` 原语在**普通非 ZTS PHP** 上即可运行，并天然**跨进程**共享状态——
> 这是与 Swoole 线程（进程内共享内存）的根本差异。详见 [docs/SWOOLE_COMPARISON.md](docs/SWOOLE_COMPARISON.md)。

**结论**：Swoole 多线程与 ext-parallel 都受限于「必须 ZTS 构建」，而 kode/parallel 的
engine 抽象让同一份代码在普通 PHP CLI 上也能获得真多进程并行，并补齐了 Future 组合子、
select 非阻塞等待与引擎无关同步原语——这是「最优最高」的调整方向。

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

## 性能压测（v1.11.0，ZTS + ext-parallel 真线程 主线，普通 PHP 多进程回退，实测可复现）

```
========================================
    Kode/Parallel 性能压测报告 (v1.11.0)
    PHP 8.3.33 | ZTS: YES | engine: parallel
========================================

parallel 引擎 submit+get ×200   ≈ 18万–30万 ops/s  (真线程，免 fork/IPC 重建)
Concurrency\Channel send+recv    ≈ 13–18M ops/s (进程内，纯内存)
Concurrency\Lock withLock 自增   ≈ 96k ops/s   (跨进程文件锁)
Concurrency\Atomic 进程内 inc     ≈ 13–17M ops/s (v1.9.0 内存快路径)
Concurrency\Atomic 跨进程 inc     ≈ 17k ops/s   (6 进程 ×5k，零丢失)
Concurrency\Semaphore 进程内      ≈ 7.3M ops/s  (v1.10.0 新增，acquire+release)
Concurrency\Barrier 跨进程会合    ≈ 350 回合/s  (4 方 ×50 回合)

--- 回退：普通 PHP（ZTS: NO），engine: process ---
process 引擎 submit+get ×200     ≈ 2.2k ops/s  (fork 模型固有开销，约为真线程的 1/100)
```

> 真线程（parallel 引擎）任务派发比多进程（process 引擎）快约 **2 个数量级**；引擎无关原语在多引擎下表现一致。
> 四角同类对比基线（同口径，可并排比较）：
> `php benchmarks/bench_concurrency.php`（kode，自动探测引擎）｜`bench_pcntl.php`（裸 pcntl 地板）｜
> `bench_swoole.php`（Swoole 6.2 线程，需 ZTS）｜`bench_ext_parallel.php`（ext-parallel 真线程，需 ZTS）。

调优方法见 [docs/PERFORMANCE.md](docs/PERFORMANCE.md)；完整数据与 Swoole 6.2 对标见
[BENCHMARK.md](docs/BENCHMARK.md) 与 [SWOOLE_COMPARISON.md](docs/SWOOLE_COMPARISON.md)。

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
