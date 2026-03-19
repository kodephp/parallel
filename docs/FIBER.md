# Kode/Parallel Fiber 协程详解

## 目录

- [简介](#简介)
- [核心概念](#核心概念)
- [Fiber 基础用法](#fiber-基础用法)
- [FiberManager 管理器](#fibernanager-管理器)
- [与 kode/fibers 集成](#与-kodefibers-集成)
- [PHP 8.5 管道操作符](#php-85-管道操作符)
- [实战案例](#实战案例)
- [最佳实践](#最佳实践)

---

## 简介

`Kode\Parallel\Fiber` 是对 PHP 原生 `Fiber` 的高级封装，提供了更友好的 API 和错误处理。

### 什么是 Fiber？

Fiber（纤程）是 PHP 8.1 引入的原生协程支持，允许在主线程中创建多个执行上下文，并可以在这些上下文之间切换。

```
主线程 (Main)
    │
    ├── Fiber 1 ──▶ 执行中 ──▶ 挂起 ──▶ 恢复 ──▶ 完成
    │
    ├── Fiber 2 ──▶ 执行中 ──▶ 挂起 ──▶ 完成
    │
    └── Fiber 3 ──▶ 执行中 ──▶ 完成
```

---

## 核心概念

### Fiber 三种状态

| 状态 | 说明 |
|------|------|
| **Suspended** | 暂停状态，可以恢复执行 |
| **Running** | 运行中状态 |
| **Terminated** | 已完成状态 |

### 生命周期

```
创建 ──▶ 启动(start) ──▶ 运行(running)
                            │
                            ├── 挂起(suspend) ──▶ 恢复(resume)
                            │
                            └── 终止(terminated)
```

---

## Fiber 基础用法

### 1. 基本创建和执行

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\Fiber;

// 创建 Fiber
$fiber = new Fiber(function() {
    echo "Fiber 开始执行\n";
    $value = Fiber::suspend('第一次挂起');
    echo "Fiber 恢复，收到: {$value}\n";
    return 'Fiber 完成';
});

// 启动 Fiber
$fiber->start();
echo "主线程继续执行\n";

// 恢复 Fiber
$result = $fiber->resume('你好 Fiber');
echo "Fiber 返回值: {$result}\n";
```

### 2. 使用静态方法创建

```php
<?php
use Kode\Parallel\Fiber\Fiber;

// 方式一：使用静态工厂方法
$fiber = Fiber::create(function($name) {
    return "Hello, {$name}!";
});

// 方式二：直接创建
$fiber = new Fiber(fn($name) => "Hello, {$name}!");

// 执行并获取结果
$result = $fiber->run('World');
echo $result; // 输出: Hello, World!
```

### 3. 挂起和恢复

```php
<?php
use Kode\Parallel\Fiber\Fiber;

$fiber = new Fiber(function() {
    // 第一次挂起
    $data1 = Fiber::suspend('准备接收数据');
    echo "收到数据: {$data1}\n";

    // 第二次挂起
    $data2 = Fiber::suspend('准备更多数据');
    echo "收到更多: {$data2}\n";

    return '处理完成';
});

// 启动
$fiber->start();

// 恢复并传递数据
$fiber->resume('第一个数据包');
$fiber->resume('第二个数据包');

// 等待完成
while (!$fiber->isTerminated()) {
    if ($fiber->isSuspended()) {
        $fiber->resume(null);
    }
}
```

### 4. 错误处理

```php
<?php
use Kode\Parallel\Fiber\Fiber;

$fiber = new Fiber(function() {
    throw new Exception('Fiber 中发生错误');
});

try {
    $fiber->start();

    while (!$fiber->isTerminated() && $fiber->isSuspended()) {
        $fiber->resume(null);
    }
} catch (Exception $e) {
    echo "捕获错误: " . $e->getMessage() . "\n";
}

// 检查错误状态
if ($fiber->hasError()) {
    $error = $fiber->getError();
    echo "Fiber 错误: " . $error->getMessage() . "\n";
}
```

---

## FiberManager 管理器

FiberManager 提供多 Fiber 协调管理能力。

### 1. 基本使用

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\FiberManager;

$manager = new FiberManager();

// 创建多个 Fiber
$manager->spawn('task1', function() {
    return array_sum(range(1, 1000));
});

$manager->spawn('task2', function() {
    return array_product(range(1, 10));
});

$manager->spawn('task3', function() {
    return str_repeat('x', 100);
});

// 启动所有
$manager->startAll();

// 等待并收集结果
$results = $manager->collect();

print_r($results);
/*
输出：
Array
(
    [task1] => 500500
    [task2] => 3628800
    [task3] => xxxxxxxxxx... (100个x)
)
*/
```

### 2. 带参数启动

```php
<?php
use Kode\Parallel\Fiber\FiberManager;

$manager = new FiberManager();

// 创建带参数的 Fiber
$manager->spawn('compute', function($n) {
    return array_sum(range(1, $n));
}, [10000]);

$manager->spawn('format', function($data) {
    return number_format($data);
}, ['1234567']);

// 启动所有（带参数）
$manager->startAll([
    'compute' => [5000],
    'format' => ['999999']
]);

// 获取结果
$results = $manager->collect();
print_r($results);
```

### 3. 状态监控

```php
<?php
use Kode\Parallel\Fiber\FiberManager;

$manager = new FiberManager();

// 创建多个 Fiber
$manager->spawnAndStart('f1', function() {
    usleep(100000);
    return 'f1 done';
});

$manager->spawnAndStart('f2', function() {
    usleep(50000);
    return 'f2 done';
});

// 获取状态
$status = $manager->getStatus();
print_r($status);
/*
输出：
Array
(
    [f1] => suspended
    [f2] => terminated
)
*/

// 等待所有完成
$manager->waitAll();
$results = $manager->collect();
```

### 4. 管道操作（pipe）

```php
<?php
use Kode\Parallel\Fiber\FiberManager;

$manager = new FiberManager();

// 使用管道操作处理数据
$result = $manager->pipe(
    '  Hello World  ',           // 初始值
    'trim',                       // 第一步：去空格
    'strtoupper',                 // 第二步：大写
    'str_replace'(...['World', 'PHP']) // 第三步：替换
);

echo $result; // 输出: HELLO PHP
```

### 5. 完整的协程任务调度

```php
<?php
use Kode\Parallel\Fiber\FiberManager;

class TaskScheduler {
    private FiberManager $manager;
    private array $tasks = [];

    public function __construct() {
        $this->manager = new FiberManager();
    }

    public function add(string $name, callable $task, array $args = []): self {
        $this->tasks[$name] = [
            'task' => $task,
            'args' => $args,
        ];
        return $this;
    }

    public function run(): array {
        // 创建所有 Fiber
        foreach ($this->tasks as $name => $config) {
            $this->manager->spawn($name, function() use ($config) {
                $task = $config['task'];
                $args = $config['args'];
                return $task(...$args);
            });
        }

        // 启动所有
        $this->manager->startAll();

        // 等待完成
        $this->manager->waitAll();

        // 收集结果
        return $this->manager->collect();
    }
}

// 使用
$scheduler = new TaskScheduler();

$results = $scheduler
    ->add('sum', fn($n) => array_sum(range(1, $n)), [100])
    ->add('product', fn($n) => array_product(range(1, $n)), [10])
    ->add('string', fn($s) => strtoupper($s), ['hello'])
    ->run();

print_r($results);
```

---

## 与 kode/fibers 集成

kode/parallel 的 Fiber 可以与 `kode/fibers` 包配合使用，获得更强大的功能。

### 1. 安装 kode/fibers

```bash
composer require kode/fibers
```

### 2. 结合 Fiber 池

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\Fiber;
use Kode\Parallel\Fiber\FiberManager;

// 检查是否安装了 kode/fibers
if (class_exists(\Kode\Fibers\Facades\Fiber::class)) {
    echo "kode/fibers 已安装，可以使用增强功能\n";

    // 使用 kode/fibers 的高级特性
    // - Fiber 池化
    // - 超时控制
    // - 错误重试
    // - 上下文传递
} else {
    echo "使用 kode/parallel 基础 Fiber 功能\n";

    // 使用基础功能
    $manager = new FiberManager();
    $manager->spawnAndStart('test', fn() => 'Hello from Fiber!');
}
```

### 3. 上下文传递（需要 kode/context）

```php
<?php
use Kode\Parallel\Fiber\Fiber;
use Kode\Parallel\Fiber\FiberManager;

// 创建带上下文的 Fiber
$fiber = Fiber::create(function() {
    // 假设使用 kode/context
    // $traceId = \Kode\Context\Context::get('trace_id');
    // echo "Trace ID: {$traceId}\n";

    return 'Context demo';
});

$fiber->start();
$result = $fiber->run();
```

---

## PHP 8.5 管道操作符

PHP 8.5 引入了 `|>` 管道操作符，kode/parallel 提供了前向兼容支持。

### 1. 基本管道

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\Util\pipe;

// PHP 8.5 原生语法（PHP 8.5+）
// $result = '  Hello World  ' |> trim(...) |> strtoupper(...);

// 前向兼容语法
$result = pipe(
    '  Hello World  ',
    'trim',                    // 字符串函数
    'strtoupper',              // 字符串函数
);

echo $result; // 输出: HELLO WORLD
```

### 2. 带参数的管道

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\Util\pipe_with;

$title = '  Hello World  ';

// 使用数组形式传递额外参数
$result = pipe_with(
    $title,
    [fn($str) => trim($str), []],                    // trim()
    [fn($str) => str_replace('World', 'PHP', $str), []], // str_replace()
    [fn($str) => strtoupper($str), []]               // strtoupper()
);

echo $result; // 输出: HELLO PHP
```

### 3. Clone With

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\Util\clone_with;

class Color {
    public function __construct(
        public int $red,
        public int $green,
        public int $blue,
        public int $alpha = 255
    ) {}
}

// PHP 8.5 原生语法（PHP 8.5+）
// $blue = new Color(79, 91, 147);
// $transparentBlue = clone($blue, ['alpha' => 128]);

// 前向兼容语法
$blue = new Color(79, 91, 147);
$transparentBlue = clone_with($blue, ['alpha' => 128]);

echo "原值: RGB({$blue->red}, {$blue->green}, {$blue->blue}, {$blue->alpha})\n";
echo "新值: RGB({$transparentBlue->red}, {$transparentBlue->green}, {$transparentBlue->blue}, {$transparentBlue->alpha})\n";
```

---

## 实战案例

### 案例 1：并行文件处理

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\FiberManager;

function processFiles(array $files): array
{
    $manager = new FiberManager();

    foreach ($files as $index => $file) {
        $manager->spawn("file_{$index}", function() use ($file) {
            $content = file_get_contents($file);
            return [
                'file' => $file,
                'lines' => count(file($file)),
                'size' => strlen($content),
                'hash' => md5($content),
            ];
        });
    }

    $manager->startAll();
    $manager->waitAll();

    return $manager->collect();
}

// 使用
$files = glob('/path/to/*.txt');
$results = processFiles($files);
print_r($results);
```

### 案例 2：协程化的 HTTP 请求

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\Fiber;
use Kode\Parallel\Fiber\FiberManager;

function fetchUrls(array $urls): array
{
    $manager = new FiberManager();

    foreach ($urls as $index => $url) {
        $manager->spawn("url_{$index}", function() use ($url) {
            $content = file_get_contents($url);
            return [
                'url' => $url,
                'length' => strlen($content),
                'hash' => md5($content),
            ];
        });
    }

    $manager->startAll();
    $manager->waitAll();

    return $manager->collect();
}

// 使用
$urls = [
    'https://httpbin.org/get',
    'https://httpbin.org/ip',
    'https://httpbin.org/headers',
];

$results = fetchUrls($urls);
print_r($results);
```

### 案例 3：数据处理流水线

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\FiberManager;

function processPipeline(array $data): array
{
    $manager = new FiberManager();

    // 阶段1：验证
    $manager->spawnAndStart('validate', function() use ($data) {
        return array_filter($data, fn($item) => isset($item['value']));
    });

    // 等待并获取验证结果
    $validated = $manager->get('validate')->getReturnValue();

    // 阶段2：转换
    $manager->spawnAndStart('transform', function() use ($validated) {
        return array_map(fn($item) => [
            'id' => $item['id'] ?? 0,
            'value' => ($item['value'] ?? 0) * 2,
            'processed' => true,
        ], array_values($validated));
    });

    // 等待完成
    $manager->wait('transform');

    return $manager->collect();
}

// 使用
$data = [
    ['id' => 1, 'value' => 10],
    ['id' => 2, 'value' => 20],
    ['id' => 3], // 缺少 value，将被过滤
];

$results = processPipeline($data);
print_r($results);
```

### 案例 4：并发数据库操作

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Fiber\FiberManager;

function queryDatabase(array $queries, PDO $pdo): array
{
    $manager = new FiberManager();

    foreach ($queries as $index => $sql) {
        $manager->spawn("query_{$index}", function() use ($sql, $pdo) {
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        });
    }

    $manager->startAll();
    $manager->waitAll();

    return $manager->collect();
}

// 使用
$pdo = new PDO('mysql:host=localhost', 'user', 'pass');

$queries = [
    'SELECT * FROM users LIMIT 10',
    'SELECT COUNT(*) as cnt FROM orders',
    'SELECT * FROM products WHERE price > 100',
];

$results = queryDatabase($queries, $pdo);
print_r($results);
```

---

## 最佳实践

### 1. 避免过度创建 Fiber

```php
// ❌ 错误：创建过多 Fiber
for ($i = 0; $i < 10000; $i++) {
    $fiber = new Fiber(fn() => $i * 2);
    $fiber->start();
}

// ✅ 正确：使用 Worker Pool
$pool = new WorkerPool(10); // 复用 10 个 worker
for ($i = 0; $i < 10000; $i++) {
    $pool->submit(fn() => $i * 2);
}
```

### 2. 及时清理资源

```php
// ✅ 使用完清理
$manager = new FiberManager();
try {
    // ... 使用 manager
} finally {
    $manager->clear();
}
```

### 3. 合理的超时设置

```php
<?php
use Kode\Parallel\Fiber\FiberManager;

$manager = new FiberManager();

// 为耗时操作设置超时
$manager->spawnAndStart('long_task', function() {
    // 可能很长的操作
    return heavyComputation();
});

// 等待最多 5 秒
if (!$manager->wait('long_task', 5000)) {
    echo "任务超时\n";
}
```

### 4. 结合 Runtime 使用

```php
<?php
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Fiber\FiberManager;
use Kode\Parallel\Channel\Channel;

$runtime = new Runtime();
$manager = new FiberManager();

// 在 Runtime 中执行 Fiber
$future = $runtime->run(function() use ($manager) {
    $manager->spawnAndStart('f1', fn() => compute1());
    $manager->spawnAndStart('f2', fn() => compute2());

    return $manager->collect();
});

$result = $future->get();
print_r($result);

$runtime->close();
```

---

## 常见问题

### Q1: Fiber 和 Thread 有什么区别？

| 特性 | Fiber | Thread |
|------|-------|--------|
| 调度 | 用户态（程序控制） | 内核态（操作系统控制） |
| 切换开销 | 极低（微秒级） | 较高（毫秒级） |
| 共享内存 | 不共享（独立空间） | 共享（需同步） |
| 复杂度 | 简单 | 复杂（需处理竞态） |
| 适用场景 | I/O 密集型 | CPU 密集型 |

### Q2: Fiber 可以在 Web 请求中使用吗？

可以，但不推荐在 FPM 模式下使用（请求结束后 Fiber 会中断）。推荐在 CLI 或常驻内存环境（Swoole、RoadRunner）中使用。

### Q3: 如何调试 Fiber？

```php
<?php
use Kode\Parallel\Fiber\Fiber;

// 使用 __toString 获取状态
$fiber = new Fiber(function() {
    Fiber::suspend('debug point');
});

$fiber->start();
echo $fiber; // 输出: Fiber(id=xxx, status=suspended)
```
