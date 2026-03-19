# Kode/Parallel

高性能 PHP 并行并发扩展库，基于 PHP `ext-parallel` 实现。

## 简介

`kode/parallel` 是适用于 PHP 8.1+ 的并行并发扩展库，提供了 Runtime、Task、Future、Channel、Events 等核心功能。该库基于 PHP 官方的 `ext-parallel` 扩展构建，提供了更高级别的面向对象 API 和中文文档支持。

## 功能特性

- **Runtime** - PHP 解释器线程管理
- **Task** - 并行任务闭包封装
- **Future** - 异步任务返回值访问
- **Channel** - Task 间双向通信
- **Events** - 事件循环驱动

## 系统要求

- PHP >= 8.1
- ext-parallel 扩展
- kode/context ^1.0
- kode/facade ^1.0

## 安装

```bash
composer require kode/parallel
```

## 快速开始

### 基本用法

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\run;

// 使用快捷函数执行并行任务
$future = run(function ($args) {
    $sum = 0;
    for ($i = 0; $i < $args['count']; $i++) {
        $sum += $i;
    }
    return $sum;
}, ['count' => 1000000]);

echo "结果: " . $future->get() . PHP_EOL;
```

### 使用 Runtime

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Task\Task;

// 创建 Runtime 实例（可选引导文件）
$runtime = new Runtime('/path/to/bootstrap.php');

// 创建并执行任务
$task = new Task(function ($data) {
    return array_map(fn($x) => $x * 2, $data);
});

$future = $runtime->run($task, [[1, 2, 3, 4, 5]]);
$result = $future->get();

print_r($result);
```

### 使用 Channel 进行进程间通信

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;

// 创建有界限通道
$channel = Channel::bounded(2);

// 生产者任务
$producer = function () use ($channel) {
    for ($i = 0; $i < 5; $i++) {
        $channel->send(['item' => $i, 'timestamp' => time()]);
    }
    $channel->close();
};

// 消费者任务
$consumer = function () use ($channel) {
    while (!$channel->isEmpty()) {
        $data = $channel->recv();
        echo "收到数据: " . json_encode($data) . PHP_EOL;
    }
};

// 并行执行
$runtime = new Runtime();
$runtime->run($producer);
$runtime->run($consumer);
```

### 使用 Events 事件循环

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Events\Events;
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$channel = Channel::make('events_channel');

// 设置输入
$input = ['events_channel' => 'Hello World'];

// 添加事件
$events = new Events();
$events->addChannel('events_channel', $channel)
       ->setInput($input);

// 事件循环
foreach ($events as $event) {
    echo "事件类型: " . $event->getType() . PHP_EOL;
    echo "事件键名: " . $event->getKey() . PHP_EOL;
}
```

## API 文档

### 核心类

#### Runtime

表示 PHP 解释器线程。

```php
public function __construct(?string $bootstrap = null)
public function run(Task|callable $task, array $args = []): Future
public function isRunning(): bool
public function getBootstrap(): ?string
public function close(): void
```

#### Task

用于并行执行的闭包封装。

```php
public function __construct(\Closure $closure)
public function getClosure(): \Closure
public static function fromFile(string $file, ?int $line = null, int $endLine = 0): static
public function getFile(): ?string
public function getLine(): ?int
```

**Task 限制**:
- 禁止使用 `yield`
- 禁止使用引用传递
- 禁止声明类
- 禁止声明命名函数

#### Future

访问异步任务返回值。

```php
public function done(): bool
public function get(): mixed
public function getOrNull(): mixed
public function wait(int $timeout = 0): bool
public function cancel(): bool
public function isCancelled(): bool
public function getId(): string
```

#### Channel

Task 间双向通信通道。

```php
public static function make(string $name = ''): static
public static function bounded(int $capacity, string $name = ''): static
public function send(mixed $value): void
public function sendNonBlocking(mixed $value): void
public function recv(): mixed
public function recvNonBlocking(): mixed
public function isEmpty(): bool
public function isFull(): bool
public function getCapacity(): int
public function getName(): string
public function close(): void
```

#### Events

事件循环驱动。

```php
public function __construct(int $polling = self::POLLING_ENABLED, int $loop = self::LOOP_BLOCKING)
public function addFuture(string $key, \parallel\Future|\Kode\Parallel\Future\Future $future): static
public function addChannel(string $key, \parallel\Channel|\Kode\Parallel\Channel\Channel $channel): static
public function setInput(array $input): static
public function poll(): ?Event
public function getKeys(): array
public function clear(): static
public function cancel(string $key): static
```

#### Event

事件对象。

```php
public function getKey(): string
public function getType(): string
public function getValue(): mixed
public function getSource(): int
public function isCancelled(): bool
public function isReady(): bool
public function isClosed(): bool
public function isFuture(): bool
public function isChannel(): bool
```

### 快捷函数

```php
// 执行并行任务
run(callable|\Closure $task, array $args = [], ?string $bootstrap = null): Future

// 创建 Runtime
runtime(?string $bootstrap = null): Runtime

// 创建 Task
task(\Closure $closure): Task

// 创建通道
Kode\Parallel\Channel\make(string $name = ''): Channel
Kode\Parallel\Channel\bounded(int $capacity, string $name = ''): Channel
```

## 异常处理

所有异常都使用 `Kode\Parallel\Exception\ParallelException` 类封装。

```php
try {
    $runtime = new Runtime('/invalid/path.php');
} catch (ParallelException $e) {
    echo "错误信息: " . $e->getMessage() . PHP_EOL;
    echo "错误上下文: " . json_encode($e->getContext()) . PHP_EOL;
}
```

## 性能考虑

1. **任务粒度**: 避免创建过多小任务，任务调度有额外开销
2. **数据传递**: 大数据量通过 Channel 传递比闭包捕获更高效
3. **通道容量**: 有界限通道可控制内存使用，防止数据堆积
4. **引导文件**: 复杂预加载逻辑放入引导文件，避免重复加载

## 集成 kode 框架

`kode/parallel` 可与 [kodephp](https://github.com/kodephp) 其他组件无缝集成：

- **kode/context**: 多线程环境下的请求上下文传递
- **kode/facade**: 统一外观模式访问
- **kode/runtime**: 运行时抽象层

## 测试

```bash
composer test
```

## 许可证

本项目采用 Apache-2.0 许可证，详情请参阅 [LICENSE](LICENSE) 文件。

## 贡献

欢迎提交 Issue 和 Pull Request！

## 相关链接

- [PHP parallel 扩展官方文档](https://www.php.net/manual/zh/book.parallel.php)
- [KodePHP 官方仓库](https://github.com/kodephp)
- [KodePHP 官方网站](https://kodephp.com)
