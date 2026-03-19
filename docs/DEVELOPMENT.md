# Kode/Parallel 开发文档

## 目录

- [简介](#简介)
- [系统要求](#系统要求)
- [安装](#安装)
- [核心概念](#核心概念)
- [快速开始](#快速开始)
- [API 参考](#api-参考)
- [最佳实践](#最佳实践)
- [性能优化](#性能优化)
- [故障排除](#故障排除)
- [版本历史](#版本历史)

## 简介

`kode/parallel` 是基于 PHP `ext-parallel` 扩展的高性能并行并发库，为 PHP 8.1+ 提供简洁、健壮的并行编程接口。

### 核心特性

- **Runtime** - PHP 解释器线程管理
- **Task** - 并行任务闭包封装
- **Future** - 异步任务返回值访问
- **Channel** - Task 间双向通信
- **Events** - 事件循环驱动
- **Fiber** - PHP Fiber 协程封装 (PHP 8.1+)

## 系统要求

- PHP >= 8.1
- ext-parallel 扩展
- ext-json (内置)
- ext-ctype (内置)

### PHP 版本适配

| PHP 版本 | 支持状态 | 特性 |
|----------|---------|------|
| 8.1 | ✅ 完全支持 | 基础 Fiber, readonly 属性 |
| 8.2 | ✅ 完全支持 | 随机字节改进 |
| 8.3 | ✅ 完全支持 | 改进的类型系统 |
| 8.4 | ✅ 完全支持 | 改进的性能 |
| 8.5 | ✅ 最佳支持 | 增强的 Fiber 调度 |

## 安装

### 通过 Composer 安装

```bash
composer require kode/parallel
```

### 验证安装

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;

$runtime = new Runtime();
echo "kode/parallel 安装成功！\n";
```

### 安装 ext-parallel 扩展

```bash
# Linux/macOS
pecl install parallel

# 或者从源码编译
git clone https://github.com/krakjoe/parallel.git
cd parallel
phpize && ./configure && make && make install
```

在 `php.ini` 中添加:
```ini
extension=parallel.so
```

## 核心概念

### Runtime

Runtime 表示 PHP 解释器线程，是并行执行的基础单元。每个 Runtime 维护自己的 PHP 解释器实例，可以独立执行任务。

```php
$runtime = new Runtime();  // 创建新的 Runtime
$runtime->run($task);      // 执行任务
$runtime->close();         // 关闭 Runtime
```

### Task

Task 是用于并行执行的闭包封装。注意 Task 中禁止：
- `yield` 指令
- 引用传递 `use &$var`
- 类声明
- 命名函数声明

```php
$task = new Task(function($args) {
    return $args['value'] * 2;
});
$future = $runtime->run($task, ['value' => 21]);
```

### Future

Future 代表异步任务的未来结果，支持：
- `done()` - 检查任务是否完成
- `get()` - 获取返回值（阻塞等待）
- `wait()` - 等待任务完成
- `cancel()` - 取消任务

```php
$future = $runtime->run(fn() => expensiveOperation());

if ($future->done()) {
    $result = $future->get();
} else {
    // 等待最多 1 秒
    if ($future->wait(1000)) {
        $result = $future->get();
    }
}
```

### Channel

Channel 提供 Task 间的双向通信能力。

```php
// 无界限通道
$ch = Channel::make('unbounded');

// 有界限通道
$ch = Channel::bounded(10, 'bounded');

// 发送/接收
$ch->send($data);
$data = $ch->recv();
```

### Events

Events 提供事件循环驱动能力，简化异步编程。

```php
$events = new Events();
$events->attachChannel('ch1', $channel1);
$events->attachFuture('f1', $future1);

foreach ($events as $event) {
    // 处理事件
}
```

## 快速开始

### 基本示例

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\run;

// 使用快捷函数
$future = run(fn($args) => $args['a'] + $args['b'], ['a' => 10, 'b' => 20]);
echo "结果: " . $future->get() . "\n"; // 输出: 30
```

### 完整示例

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Channel\Channel;

// 1. 创建 Runtime
$runtime = new Runtime();

// 2. 创建通道用于通信
$channel = Channel::make('work');

// 3. 定义任务
$producer = new Task(function($args) {
    $ch = $args['channel'];
    for ($i = 0; $i < 5; $i++) {
        $ch->send(['item' => $i, 'data' => str_repeat('x', 100)]);
    }
    $ch->close();
});

$consumer = new Task(function($args) {
    $ch = $args['channel'];
    $sum = 0;
    while (!$ch->isEmpty()) {
        $item = $ch->recv();
        $sum += $item['item'];
    }
    return $sum;
});

// 4. 并行执行任务
$prodFuture = $runtime->run($producer, ['channel' => $channel]);
$consFuture = $runtime->run($consumer, ['channel' => $channel]);

// 5. 获取结果
$total = $consFuture->get();
echo "处理了 {$total} 个项目\n";

// 6. 清理
$runtime->close();
```

### Fiber 集成示例 (PHP 8.1+)

```php
<?php

use Kode\Parallel\Fiber\FiberManager;
use Kode\Parallel\Fiber\Fiber;

$manager = new FiberManager();

// 创建 Fiber
$fiber = $manager->spawn('compute', function($n) {
    return array_sum(range(1, $n));
}, [1000]);

// 启动
$manager->startAll();

// 恢复执行
$result = $manager->resume('compute', null);
echo "结果: {$result}\n";
```

## API 参考

### Runtime

| 方法 | 说明 |
|------|------|
| `__construct(?string $bootstrap)` | 创建 Runtime，可选引导文件 |
| `run(Task\|callable $task, array $args)` | 执行任务 |
| `isRunning(): bool` | 检查是否运行中 |
| `getBootstrap(): ?string` | 获取引导文件路径 |
| `close(): void` | 关闭 Runtime |

### Task

| 方法 | 说明 |
|------|------|
| `__construct(\Closure $closure)` | 创建 Task |
| `static from(\Closure)` | 静态工厂方法 |
| `static fromFile(string, ?int, int)` | 从文件创建 |
| `getClosure(): \Closure` | 获取闭包 |
| `execute(array $args)` | 本地执行（非并行） |
| `getFile(): ?string` | 获取文件路径 |
| `getLine(): ?int` | 获取行号 |

### Future

| 方法 | 说明 |
|------|------|
| `done(): bool` | 检查是否完成 |
| `get(): mixed` | 获取返回值 |
| `getOrNull(): mixed` | 非阻塞获取或 null |
| `wait(int $timeoutMs): bool` | 等待完成 |
| `cancel(): bool` | 取消任务 |
| `isCancelled(): bool` | 检查是否取消 |
| `getId(): string` | 获取唯一 ID |

### Channel

| 方法 | 说明 |
|------|------|
| `static make(string $name)` | 创建无界限通道 |
| `static bounded(int $capacity)` | 创建有界限通道 |
| `send(mixed $value)` | 发送数据（阻塞） |
| `sendNonBlocking(mixed)` | 非阻塞发送 |
| `recv(): mixed` | 接收数据（阻塞） |
| `recvNonBlocking(): mixed` | 非阻塞接收 |
| `isEmpty(): bool` | 检查是否为空 |
| `isFull(): bool` | 检查是否已满 |
| `close(): void` | 关闭通道 |

### Events

| 方法 | 说明 |
|------|------|
| `attachFuture(string $key, Future)` | 添加 Future |
| `attachChannel(string $key, Channel)` | 添加 Channel |
| `setInput(array $input)` | 设置输入 |
| `poll(): ?Event` | 轮询事件 |
| `getKeys(): array` | 获取所有键 |
| `cancel(string $key)` | 取消事件 |
| `clear(): self` | 清除所有 |

### Event

| 方法 | 说明 |
|------|------|
| `getKey(): string` | 获取事件键 |
| `getType(): string` | 获取类型 |
| `getValue(): mixed` | 获取值 |
| `getSource(): int` | 获取来源 |
| `isReady(): bool` | 是否就绪 |
| `isClosed(): bool` | 是否关闭 |
| `isFuture(): bool` | 是否 Future |
| `isChannel(): bool` | 是否 Channel |

### Fiber (PHP 8.1+)

| 类/方法 | 说明 |
|--------|------|
| `FiberManager` | Fiber 生命周期管理器 |
| `FiberManager::spawn()` | 创建 Fiber |
| `FiberManager::startAll()` | 启动所有 |
| `FiberManager::resume()` | 恢复执行 |
| `Fiber::start()` | 启动 |
| `Fiber::resume()` | 恢复 |
| `Fiber::suspend()` | 挂起 |

## 最佳实践

### 1. 任务设计

```php
// ✅ 推荐：简单的单一职责任务
$task = new Task(fn($args) => processData($args['data']));

// ❌ 避免：复杂的嵌套逻辑
$task = new Task(function($args) {
    $result = [];
    foreach ($args['items'] as $item) {
        // 复杂处理...
    }
    return $result;
});
```

### 2. 数据传递

```php
// ✅ 使用 Channel 传递大数据
$channel = Channel::make();
$runtime->run(fn($args) => $args['ch']->send(largeDataset()), ['ch' => $channel]);
$data = $channel->recv();

// ❌ 避免：闭包捕获大对象
$largeData = loadLargeData();
$runtime->run(fn() => process($largeData)); // 每次都复制数据
```

### 3. 错误处理

```php
try {
    $runtime = new Runtime('/invalid/path.php');
} catch (ParallelException $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "上下文: " . json_encode($e->getContext()) . "\n";
}
```

### 4. 资源管理

```php
// ✅ 使用完立即关闭
$runtime = new Runtime();
try {
    $future = $runtime->run($task);
    $result = $future->get();
} finally {
    $runtime->close();
}
```

### 5. 超时处理

```php
$future = $runtime->run(fn() => longRunningTask());

if (!$future->wait(5000)) { // 5 秒超时
    $future->cancel();
    echo "任务超时\n";
}
```

## 性能优化

### 1. 任务粒度

任务过小会导致调度开销大于实际执行时间：

```php
// ❌ 任务太简单，调度开销大
for ($i = 0; $i < 10000; $i++) {
    $runtime->run(fn() => 1 + 1);
}

// ✅ 合并小任务
$runtime->run(fn() => array_sum(array_fill(0, 10000, 2)));
```

### 2. 预热 Runtime

首次创建 Runtime 有初始化开销：

```php
// ✅ 预热
$runtime = new Runtime('/path/to/bootstrap.php');
// 执行一个热身任务
$runtime->run(fn() => null)->wait();
```

### 3. 使用有界限通道

控制内存使用：

```php
// ✅ 有界限通道防止内存溢出
$channel = Channel::bounded(100);

$producer = function() use ($channel) {
    foreach (generateItems() as $item) {
        $channel->send($item);
    }
    $channel->close();
};
```

### 4. 避免频繁创建对象

```php
// ❌ 循环中创建
foreach ($items as $item) {
    $task = new Task(fn() => process($item));
    // ...
}

// ✅ 复用
$task = new Task(fn($args) => process($args['item']));
foreach ($items as $item) {
    $future = $runtime->run($task, ['item' => $item]);
}
```

## 故障排除

### 常见错误

#### 1. Task 中使用 yield

```
ParallelException: Task 中禁止使用 yield 指令
```

**解决**：将生成器改为返回数组，或在主线程中迭代。

#### 2. Task 中使用引用

```
ParallelException: Task 中禁止使用引用传递
```

**解决**：使用 Channel 传递需要修改的数据。

#### 3. Runtime 未初始化

```
ParallelException: Runtime 未正确初始化
```

**解决**：检查引导文件路径是否正确，确保 ext-parallel 已安装。

#### 4. 通道已满

```
ParallelException: 通道已满，无法非阻塞发送
```

**解决**：使用阻塞发送 `send()`，或增大通道容量。

### 调试技巧

```php
// 1. 启用错误报告
error_reporting(E_ALL);

// 2. 使用 try-catch
try {
    $future = $runtime->run($task);
    $result = $future->get();
} catch (ParallelException $e) {
    var_dump($e->getContext());
}

// 3. 检查 Runtime 状态
echo "Runtime 运行中: " . ($runtime->isRunning() ? '是' : '否');
```

## 许可证

Apache-2.0

## 联系方式

- GitHub: https://github.com/kodephp/parallel
- 官网: https://kodephp.com
