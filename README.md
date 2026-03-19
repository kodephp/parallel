# Kode/Parallel

高性能 PHP 并行并发扩展库，基于 PHP `ext-parallel` 实现，为 PHP 8.1+ 提供简洁、健壮的并行编程接口。

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://php.net)
[![License](https://img.shields.io/badge/License-Apache--2.0-green)](LICENSE)
[![Package Version](https://img.shields.io/badge/Version-1.2.0-orange)](composer.json)

## 目录

- [简介](#简介)
- [功能特性](#功能特性)
- [系统要求](#系统要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [核心组件详解](#核心组件详解)
- [PHP 8.5 新特性](#php-85-新特性)
- [Fiber 协程](#fiber-协程)
- [高级用法](#高级用法)
- [性能压测](#性能压测)
- [最佳实践](#最佳实践)
- [常见问题](#常见问题)
- [文档索引](#文档索引)

---

## 简介

`kode/parallel` 是适用于 PHP 8.1+ 的高性能并行并发扩展库。该库基于 PHP 官方的 `ext-parallel` 扩展构建，提供了更高级别的面向对象 API、完整的中文文档支持，以及 PHP 8.5 新特性的前向兼容实现。

### 为什么要用并行？

```php
// 串行处理：5个任务，每个1秒 = 总计5秒
for ($i = 0; $i < 5; $i++) {
    $result = processTask($i); // 1秒
}

// 并行处理：5个任务并行 = 总计1秒
$futures = [];
for ($i = 0; $i < 5; $i++) {
    $futures[] = $runtime->run(fn() => processTask($i));
}
```

---

## 功能特性

| 组件 | 说明 |
|------|------|
| **Runtime** | PHP 解释器线程管理，是并行执行的基础单元 |
| **Task** | 并行任务闭包封装，负责验证任务合法性 |
| **Future** | 异步任务返回值访问，支持超时、取消操作 |
| **Channel** | Task 间双向通信，支持有/无界限通道 |
| **Events** | 事件循环驱动，简化异步编程模型 |
| **Fiber** | PHP Fiber 协程封装（PHP 8.1+） |
| **Sync** | 同步原语：Mutex、Semaphore、Cond、Barrier |
| **Pipe** | 进程间通信管道 |
| **CurlMulti** | 并行 HTTP 请求封装 |
| **Util** | PHP 8.5 兼容工具：管道操作符、Clone With 等 |

---

## 系统要求

| 要求 | 说明 |
|------|------|
| PHP 版本 | >= 8.1 |
| 扩展 | ext-parallel |
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

### 1. 安装 ext-parallel 扩展

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

### 2. 安装 Composer 包

```bash
composer require kode/parallel
```

### 3. 可选：安装 kode/fibers（增强功能）

```bash
composer require kode/fibers
```

---

## 快速开始

### 1. 最简用法 - 使用快捷函数

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\run;

// 一行代码实现并行执行
$future = run(
    fn($args) => array_sum(range(1, $args['n'])),
    ['n' => 1000000]
);

echo "计算结果: " . $future->get() . "\n"; // 输出: 500000500000
```

### 2. 基本 Runtime 用法

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;

$runtime = new Runtime();

// 直接执行闭包
$future1 = $runtime->run(fn() => 100 + 200);

// 带参数
$future2 = $runtime->run(
    fn($args) => $args['a'] + $args['b'],
    ['a' => 10, 'b' => 20]
);

echo "结果: " . $future1->get() . " / " . $future2->get() . "\n";

$runtime->close();
```

### 3. 使用 Channel 进行通信

```php
<?php
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$channel = Channel::make('work');

// 生产者
$runtime->run(function() use ($channel) {
    for ($i = 0; $i < 100; $i++) {
        $channel->send(['item' => $i]);
    }
    $channel->close();
});

// 消费者
$future = $runtime->run(function() use ($channel) {
    $sum = 0;
    while (!$channel->isEmpty()) {
        $sum += $channel->recv()['item'];
    }
    return $sum;
});

echo "总和: " . $future->get() . "\n";
$runtime->close();
```

### 4. 使用 Fiber 协程

```php
<?php
use Kode\Parallel\Fiber\Fiber;

$fiber = new Fiber(function() {
    echo "Fiber 开始\n";
    $value = Fiber::suspend('暂停一下');
    echo "收到: {$value}\n";
    return "完成";
});

$fiber->start();
echo "主线程继续\n";
$result = $fiber->resume("恢复数据");
echo "返回值: {$result}\n";
```

---

## 核心组件详解

### Runtime - 运行时

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
| [FIBER.md](docs/FIBER.md) | Fiber 协程详解 |
| [PIPE.md](docs/PIPE.md) | Pipe 管道详解 |
| [CURL.md](docs/CURL.md) | CurlMulti 并行请求 |
| [ADVANCED_USAGE.md](docs/ADVANCED_USAGE.md) | 高级用法和案例 |
| [PTHREADS_COMPARISON.md](docs/PTHREADS_COMPARISON.md) | 与 pthreads 对比 |
| [BENCHMARK.md](docs/BENCHMARK.md) | 完整性能压测数据 |

---

## 更新日志

### v1.2.0 (2026-03-19)

- ✨ 添加 PHP 8.5 特性兼容（管道操作符、Clone With）
- ✨ 添加 Fiber 协程完整支持
- ✨ 添加 Sync 同步原语（Mutex、Semaphore、Cond、Barrier）
- ✨ 添加 Pipe 管道
- ✨ 添加 CurlMulti 并行请求
- ✨ 添加详细文档（FIBER、PIPE、CURL）
- ✅ 完整单元测试

### v1.1.0

- 添加 Sync 原语
- 添加 pthreads 对比文档
- 添加高级用法文档

### v1.0.0

- 初始发布

---

## 许可证

Apache-2.0

## 相关链接

- [PHP parallel 扩展官方文档](https://www.php.net/manual/zh/book.parallel.php)
- [KodePHP 官方仓库](https://github.com/kodephp)
- [kode/fibers 包](https://github.com/kodephp/fibers)
- [PHP 8.5 新特性](https://www.php.net/releases/8.5/zh.php)
