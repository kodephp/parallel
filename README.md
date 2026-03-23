# Kode/Parallel

高性能 PHP 并行并发扩展库，基于 PHP `ext-parallel` 实现，为 PHP 8.1+ 提供简洁、健壮的并行编程接口。**支持跨机器分布式任务执行**。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache--2.0-green)](LICENSE)
[![Package Version](https://img.shields.io/badge/Version-1.5.2-orange)](composer.json)

## 目录

- [简介](#简介)
- [功能特性](#功能特性)
- [系统要求](#系统要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [核心组件详解](#核心组件详解)
- [分布式集群](#分布式集群)
- [PHP 8.5 新特性](#php-85-新特性)
- [Fiber 协程](#fiber-协程)
- [性能压测](#性能压测)
- [最佳实践](#最佳实践)
- [常见问题](#常见问题)
- [文档索引](#文档索引)

---

## 简介

`kode/parallel` 是适用于 PHP 8.1+ 的高性能并行并发扩展库。该库基于 PHP 官方的 `ext-parallel` 扩展构建，提供了更高级别的面向对象 API、完整的中文文档支持，以及 PHP 8.5 新特性的前向兼容实现。

### 架构模型：多线程 + 分布式

```
┌─────────────────────────────────────────────────────────────────┐
│                      本地并行 (多线程)                            │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Runtime (PHP 解释器线程 #1)  ──── Channel ──── 线程 #N │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      分布式集群 (跨机器)                          │
│                                                                 │
│  ┌─────────────┐      ┌─────────────┐      ┌─────────────┐    │
│  │   节点 #1    │◄────►│   节点 #2    │◄────►│   节点 #N    │    │
│  │ tcp://host1 │      │ tcp://host2 │      │ tcp://hostN │    │
│  │ :8001       │      │ :8002       │      │ :800N       │    │
│  └─────────────┘      └─────────────┘      └─────────────┘    │
│                                                                 │
│              ClusterManager (主节点调度)                          │
└─────────────────────────────────────────────────────────────────┘
```

### 核心能力

| 层级 | 能力 | 组件 |
|------|------|------|
| **本地并行** | 多线程执行 | Runtime, Task, Future, Channel, Events |
| **同步原语** | 互斥/信号量 | Mutex, Semaphore, Cond, Barrier |
| **协程支持** | Fiber 协程 | Fiber, FiberManager |
| **跨机器** | 分布式集群 | Node, TcpNodeTransport, ClusterManager, ClusterServer |
| **HTTP 并行** | 并行请求 | CurlMulti |

---

## 功能特性

| 组件 | 说明 |
|------|------|
| **Runtime** | PHP 解释器线程管理，本地并行执行的基础 |
| **Task** | 并行任务闭包封装 |
| **Future** | 异步任务返回值访问 |
| **Channel** | Task 间双向通信，支持有/无界限通道 |
| **Events** | 事件循环驱动 |
| **Fiber** | PHP Fiber 协程封装（基于 kode/fibers） |
| **Sync** | 同步原语：Mutex、Semaphore、Cond、Barrier |
| **Pipe** | 进程间通信管道 |
| **CurlMulti** | 并行 HTTP 请求封装 |
| **Node** | 集群节点表示（跨机器） |
| **TcpNodeTransport** | TCP 节点传输层（跨机器） |
| **ClusterManager** | 集群管理器，支持主节点选举、负载均衡 |
| **ClusterServer** | 集群服务器（任务执行器） |
| **ThreadPool** | 线程池（Swoole 风格） |
| **ThreadMap** | 线程安全 Map（Swoole Table 风格） |
| **ThreadQueue** | 线程安全队列 |
| **ThreadBarrier** | 线程屏障 |
| **Util** | PHP 8.5 兼容工具：管道操作符、Clone With 等 |
| **Installation** | 自动检测 ext-parallel 并提供安装提示 |
| **集成组件** | **kode 生态集成** |
| **ParallelRuntimeAdapter** | kode/runtime 运行时适配器 |
| **FiberCoordinator** | Fiber 协调器（集成 kode/fibers） |
| **ContextualRuntime** | 上下文感知运行时（集成 kode/context） |

---

## 系统要求

| 要求 | 说明 |
|------|------|
| PHP 版本 | >= 8.1 |
| 必需扩展 | ext-parallel |
| 必需包 | kode/fibers, kode/context, kode/facade |
| 可选扩展 | ext-curl (用于 CurlMulti) |

### PHP 版本适配

| PHP 版本 | 支持状态 | 特性 |
|----------|---------|------|
| 8.1 | ✅ 完全支持 | 基础 Fiber, readonly 属性 |
| 8.2 | ✅ 完全支持 | 随机字节改进 |
| 8.3 | ✅ 完全支持 | 改进的类型系统 |
| 8.4 | ✅ 完全支持 | 改进的性能 |
| 8.5 | ✅ 最佳支持 | 管道操作符、Clone With、持久化 cURL |

---

## 安装

### 1. 安装 ext-parallel 扩展（必需）

```bash
# Linux/macOS via PECL
pecl install parallel

# 或者从源码编译
git clone https://github.com/krakjoe/parallel.git
cd parallel
phpize && ./configure && make && sudo make install
```

在 `php.ini` 中添加：
```ini
extension=parallel.so
```

安装后验证：
```bash
php -r "echo extension_loaded('parallel') ? 'OK' : '请先安装 ext-parallel';"
composer run-script check
```

### 2. 安装 Composer 包

```bash
composer require kode/parallel
```

这将自动安装所有依赖：kode/fibers, kode/context, kode/facade

---

## 快速开始

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
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$ch = Channel::make('data');

$runtime->run(fn() => $ch->send(range(1, 100)));
$runtime->run(fn() => $ch->send(range(101, 200)));

$sum = fn() => array_sum($ch->recv()) + array_sum($ch->recv());
echo "总和: " . $runtime->run($sum)->get() . "\n";

$runtime->close();
```

### 3. 分布式集群（跨机器）

```php
<?php
// 主节点 (master.php)
use Kode\Parallel\Cluster\ClusterManager;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$manager = new ClusterManager(new Runtime());

$manager->setLocalNode(new Node('192.168.1.100', 9000, 'master'));
$manager->addNode(new Node('192.168.1.101', 8001, 'worker-1'));
$manager->addNode(new Node('192.168.1.102', 8001, 'worker-2'));

$result = $manager->submitTask(
    'my-task',
    fn($args) => 'Hello from ' . gethostname(),
    []
);

echo $result['result'] . "\n";
```

```php
<?php
// 工作节点 (worker.php)
use Kode\Parallel\Cluster\ClusterServer;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$node = new Node('0.0.0.0', 8001, $argv[1] ?? 'worker');
$server = new ClusterServer($node, new Runtime());

echo "启动: {$node->getAddress()}\n";
$server->start();
```

### 4. Fiber 协程

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
// 无界限通道
$ch = Channel::make('name');

// 有界限通道（容量为10）
$ch = Channel::bounded(10);

// 发送/接收
$ch->send($data);
$data = $ch->recv();
$ch->close();
$ch->isEmpty();
```

### Events - 事件循环

```php
$events = new Events();
$events->attachChannel('ch1', $channel);
$events->attachFuture('f1', $future);

foreach ($events as $event) {
    // $event['source'] = 'ch1' | 'f1'
    // $event['data'] = received data
}
```

### Sync - 同步原语

```php
// Mutex - 互斥锁
$mutex = new Mutex();
$mutex->withLock(fn() => $shared++);

// Semaphore - 信号量（限流，最多3个并发）
$sem = new Semaphore(3);
$sem->withResource(fn() => process());

// Cond - 条件变量
$cond = new Cond();
$cond->wait($mutex);
$cond->broadcast();

// Barrier - 屏障（等待 N 个任务）
$barrier = new Barrier(3);
$barrier->wait();
```

```php
$runtime = new Runtime();
$future = $runtime->run($task, $args);
$runtime->close();
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

### Channel - 通道

```php
// 无界限通道
$ch = Channel::make('name');

// 有界限通道（容量为10）
$ch = Channel::bounded(10);

// 发送/接收
$ch->send($data);
$data = $ch->recv();
```

### Events - 事件循环

```php
$events = new Events();
$events->attachChannel('ch1', $channel);
$events->attachFuture('f1', $future);

foreach ($events as $event) {
    // 处理事件
}
```

---

## 分布式集群

支持跨机器分布式任务执行，包含节点管理、主节点选举、负载均衡等功能。

### ClusterManager - 集群管理器

```php
use Kode\Parallel\Cluster\ClusterManager;
use Kode\Parallel\Network\Node;

$manager = new ClusterManager(new Runtime());
$manager->setLocalNode(new Node('192.168.1.100', 9000, 'master'));

$manager->addNode(new Node('192.168.1.101', 8001, 'worker-1'));
$manager->addNode(new Node('192.168.1.102', 8001, 'worker-2'));

$result = $manager->submitTask('task-1', fn($args) => $args['x'] * 2, ['x' => 21]);
echo "结果: " . $result['result'] . "\n";

$manager->broadcast(fn() => gethostname());
$manager->healthCheck();
```

### ClusterServer - 集群服务器

```php
use Kode\Parallel\Cluster\ClusterServer;
use Kode\Parallel\Network\Node;

$node = new Node('0.0.0.0', 8001, 'worker-1');
$server = new ClusterServer($node, new Runtime());
$server->start();
```

详见 [CLUSTER.md](docs/CLUSTER.md)

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

### 1. Sync 同步原语

```php
<?php
use Kode\Parallel\Sync\Mutex;
use Kode\Parallel\Sync\Semaphore;

// Mutex - 互斥锁
$mutex = new Mutex();
$mutex->withLock(function() {
    // 临界区代码
});

// Semaphore - 信号量（限流）
$sem = new Semaphore(3); // 最多3个并发
$sem->withResource(function() {
    // 限流执行
});
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
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$channel = Channel::bounded(10);

$producer = fn() => produce($channel);
$consumer = fn() => consume($channel);

$runtime->run($producer);
$runtime->run($consumer);
```

详见 [ADVANCED_USAGE.md](docs/ADVANCED_USAGE.md)

---

## 性能压测

```
========================================
     Kode/Parallel 性能压测报告
========================================

Task 创建: 1.52 μs/个
简单任务: 7,958 tasks/sec
Channel通信: 22,000+ ops/sec
Mutex操作: 4,200,000+ ops/sec

并行加速比:
  4任务: 3.21x (效率 80.3%)
  8任务: 5.35x (效率 66.9%)
 10任务: 5.63x (效率 56.3%)
```

详见 [BENCHMARK.md](docs/BENCHMARK.md)

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
| [CLUSTER.md](docs/CLUSTER.md) | 分布式集群详解（跨机器） |
| [FIBER.md](docs/FIBER.md) | Fiber 协程详解 |
| [PIPE.md](docs/PIPE.md) | Pipe 管道详解 |
| [CURL.md](docs/CURL.md) | CurlMulti 并行请求 |
| [ADVANCED_USAGE.md](docs/ADVANCED_USAGE.md) | 高级用法和案例 |
| [PTHREADS_COMPARISON.md](docs/PTHREADS_COMPARISON.md) | 与 pthreads 对比 |
| [PCNTL_COMPARISON.md](docs/PCNTL_COMPARISON.md) | 与 pcntl 对比 |
| [SWOOLE_COMPARISON.md](docs/SWOOLE_COMPARISON.md) | 与 Swoole 对比 |
| [BENCHMARK.md](docs/BENCHMARK.md) | 完整性能压测数据 |

---


## 许可证

Apache-2.0

## 相关链接

- [PHP parallel 扩展官方文档](https://www.php.net/manual/zh/book.parallel.php)
- [KodePHP 官方仓库](https://github.com/kodephp)
- [kode/fibers 包](https://github.com/kodephp/fibers)
- [PHP 8.5 新特性](https://www.php.net/releases/8.5/zh.php)


完善本地的项目规则文件，这个不上传仓库中。。
优化升级，对比 swoole的多线程，数据相关压测。如使用 fiber 则应该使用 kode/fibers 这个吧。 
 `https://wiki.swoole.com/zh-cn/#/thread/thread` `https://wiki.swoole.com/zh-cn/#/thread/pool` `https://wiki.swoole.com/zh-cn/#/thread/info` `https://wiki.swoole.com/zh-cn/#/thread/map` `https://wiki.swoole.com/zh-cn/#/thread/arraylist` `https://wiki.swoole.com/zh-cn/#/thread/queue` `https://wiki.swoole.com/zh-cn/#/thread/barrier` `https://wiki.swoole.com/zh-cn/#/thread/transfer` 
对比下，取其优点 完善本包。。本包让更健壮强大的架构和代码。
测试无误后，更新版本号，上传仓库和版本，备注说明简洁明了。