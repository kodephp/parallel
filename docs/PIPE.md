# Kode/Parallel Pipe 管道详解

## 目录

- [简介](#简介)
- [Pipe 类](#pipe-类)
- [基本用法](#基本用法)
- [高级用法](#高级用法)
- [PHP 8.5 管道操作符](#php-85-管道操作符)
- [实战案例](#实战案例)
- [注意事项](#注意事项)

---

## 简介

`Kode\Parallel\Pipe\Pipe` 提供进程间单向数据传输的管道，类似于 Unix 管道概念。

### 管道特点

| 特性 | 说明 |
|------|------|
| **单向通信** | 数据从一端流向另一端 |
| **面向字节** | 支持原始字节流传输 |
| **面向行** | 支持文本行读取 |
| **阻塞/非阻塞** | 支持超时控制 |

---

## Pipe 类

### 类结构

```php
namespace Kode\Parallel\Pipe;

final class Pipe
{
    public function __construct(string $name);

    // 工厂方法
    public static function make(string $name): static;
    public static function open(string $name): static;

    // 读写操作
    public function write(string $data, int $timeout = 0): bool;
    public function read(int $length = 0, int $timeout = 0): ?string;
    public function readLine(int $timeout = 0): ?string;

    // 状态查询
    public function isReadable(): bool;
    public function isWritable(): bool;
    public function isClosed(): bool;

    // 资源管理
    public function close(): void;
    public function getName(): string;
}
```

---

## 基本用法

### 1. 创建管道

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pipe\Pipe;

// 创建新管道
$pipe = Pipe::make('my_pipe');

// 打开已存在的管道
$pipe = Pipe::open('existing_pipe');
```

### 2. 基本读写

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('data_pipe');

// 写入数据
$pipe->write('Hello, Pipe!');

// 读取数据
$data = $pipe->read();
echo $data; // 输出: Hello, Pipe!

// 关闭
$pipe->close();
```

### 3. 带超时的读写

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('timeout_pipe');

// 写入，超时 3 秒
$written = $pipe->write('data', 3000);
if ($written) {
    echo "写入成功\n";
}

// 读取，超时 5 秒
$data = $pipe->read(1024, 5000);
if ($data !== null) {
    echo "读取到: {$data}\n";
} else {
    echo "读取超时\n";
}
```

### 4. 按行读取

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('line_pipe');

// 写入多行数据
$pipe->write("第一行\n");
$pipe->write("第二行\n");
$pipe->write("第三行\n");

// 按行读取
while (($line = $pipe->readLine()) !== null) {
    echo "收到: " . trim($line) . "\n";
}
```

---

## 高级用法

### 1. 进程间通信

```php
<?php
use Kode\Parallel\Pipe\Pipe;

// 父进程：创建管道
$pipe = Pipe::make('ipc_pipe');

// 子进程将通过管道发送数据
$pipe->write(json_encode(['event' => 'start', 'time' => time()]));

// 读取子进程数据
$data = $pipe->read();
$payload = json_decode($data, true);

echo "收到事件: " . $payload['event'] . "\n";
```

### 2. 消息协议

```php
<?php
use Kode\Parallel\Pipe\Pipe;

class MessageProtocol
{
    private Pipe $pipe;

    public function __construct(Pipe $pipe)
    {
        $this->pipe = $pipe;
    }

    public function send(array $message): void
    {
        $data = json_encode($message);
        $length = strlen($data);
        $this->pipe->write(pack('N', $length) . $data);
    }

    public function recv(): ?array
    {
        // 读取长度（4字节网络序）
        $lengthData = $this->pipe->read(4);
        if ($lengthData === null) {
            return null;
        }

        $unpacked = unpack('N', $lengthData);
        $length = $unpacked[1];

        // 读取数据
        $data = $this->pipe->read($length);
        if ($data === null) {
            return null;
        }

        return json_decode($data, true);
    }
}

// 使用
$pipe = Pipe::make('protocol_pipe');
$protocol = new MessageProtocol($pipe);

// 发送消息
$protocol->send(['type' => 'request', 'data' => ['id' => 123]]);

// 接收消息
$message = $protocol->recv();
```

### 3. 流式传输

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('stream_pipe');

// 发送大文件（分块传输）
$fileHandle = fopen('/path/to/large_file.zip', 'rb');
$chunkSize = 8192;

while (!feof($fileHandle)) {
    $chunk = fread($fileHandle, $chunkSize);
    $pipe->write($chunk);
}

fclose($fileHandle);

// 接收并保存
$outputHandle = fopen('/path/to/output.zip', 'wb');

while (($chunk = $pipe->read($chunkSize)) !== null) {
    fwrite($outputHandle, $chunk);
}

fclose($outputHandle);
```

### 4. 非阻塞模式

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('nonblocking_pipe');

// 检查是否可读
if ($pipe->isReadable()) {
    $data = $pipe->read(1024, 100); // 100ms 超时
    if ($data !== null) {
        echo "读取到: {$data}\n";
    }
}

// 检查是否可写
if ($pipe->isWritable()) {
    $pipe->write('quick data');
}
```

---

## PHP 8.5 管道操作符

PHP 8.5 引入了 `|>` 管道操作符，kode/parallel 提供了前向兼容实现。

### 1. 基本管道

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\Util\pipe;

// 传统写法
$result = strtoupper(trim('  hello  '));

// 管道写法（类似 Unix）
$result = pipe(
    '  hello  ',
    'trim',           // 第一步
    'strtoupper'      // 第二步
);

echo $result; // 输出: HELLO
```

### 2. 函数链式调用

```php
<?php
use function Kode\Parallel\Util\pipe;

$data = [
    ['name' => 'Alice', 'age' => 30],
    ['name' => 'Bob', 'age' => 25],
    ['name' => 'Charlie', 'age' => 35],
];

// 管道处理数组
$names = pipe(
    $data,
    fn($arr) => array_filter($arr, fn($item) => $item['age'] >= 30),
    fn($arr) => array_values($arr),
    fn($arr) => array_column($arr, 'name'),
    fn($arr) => implode(', ', $arr)
);

echo $names; // 输出: Alice, Charlie
```

### 3. 带参数的管道

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\Util\pipe_with;

$text = '  Hello World  ';

// 使用 pipe_with 传递额外参数
$result = pipe_with(
    $text,
    ['trim', []],                                          // trim()
    ['str_replace', ['World', 'PHP']],                     // str_replace('World', 'PHP')
    ['str_pad', [$, 10, '-', STR_PAD_BOTH]]               // str_pad(..., 10, '-', STR_PAD_BOTH)
);

echo $result;
```

### 4. 数据转换流水线

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use function Kode\Parallel\Util\pipe;

class DataPipeline
{
    public static function process(mixed $data, array $stages): mixed
    {
        return pipe($data, ...$stages);
    }
}

// 定义处理阶段
$stages = [
    fn($data) => is_string($data) ? json_decode($data, true) : $data,
    fn($data) => is_array($data) ? array_map(fn($item) => $item * 2, $data) : $data,
    fn($data) => is_array($data) ? array_filter($data, fn($item) => $item > 0) : $data,
    fn($data) => array_values($data),
];

// 执行流水线
$result = DataPipeline::process('[1, 2, 3, 4, 5]', $stages);
print_r($result);
```

---

## 实战案例

### 案例 1：父子进程通信

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('parent_child_pipe');

$pid = pcntl_fork();

if ($pid == 0) {
    // 子进程
    $pipe->write(json_encode([
        'pid' => getmypid(),
        'status' => 'started',
        'time' => time()
    ]));

    // 接收父进程响应
    $response = $pipe->read();
    $data = json_decode($response, true);

    echo "子进程收到: " . json_encode($data) . "\n";
    exit(0);
} else {
    // 父进程
    $childData = $pipe->read();
    $child = json_decode($childData, true);

    echo "父进程收到子进程 {$child['pid']} 的消息\n";

    // 发送响应
    $pipe->write(json_encode([
        'parent_pid' => getmypid(),
        'acknowledged' => true
    ]));

    pcntl_wait($status);
}
```

### 案例 2：并行任务结果收集

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pipe\Pipe;

function parallelCollect(array $tasks): array
{
    $pipes = [];
    $results = [];

    // 为每个任务创建管道
    foreach ($tasks as $index => $task) {
        $pipes[$index] = Pipe::make("task_result_{$index}");

        // 在子进程中执行任务
        $pid = pcntl_fork();

        if ($pid == 0) {
            // 子进程：执行任务并通过管道发送结果
            $result = $task();
            $pipes[$index]->write(serialize($result));
            exit(0);
        }
    }

    // 父进程：收集所有结果
    foreach ($pipes as $index => $pipe) {
        $data = $pipe->read();
        $results[$index] = unserialize($data);
        $pipe->close();
        pcntl_wait($status);
    }

    return $results;
}

// 使用
$tasks = [
    fn() => array_sum(range(1, 1000)),
    fn() => array_product(range(1, 10)),
    fn() => strtoupper('hello world'),
];

$results = parallelCollect($tasks);
print_r($results);
```

### 案例 3：流式数据处理

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Pipe\Pipe;

class StreamProcessor
{
    private Pipe $input;
    private Pipe $output;
    private array $filters = [];

    public function __construct(Pipe $input, Pipe $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    public function addFilter(callable $filter): self
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function process(): void
    {
        while (($chunk = $this->input->read(1024)) !== null) {
            $data = $chunk;

            // 应用所有过滤器
            foreach ($this->filters as $filter) {
                $data = $filter($data);
            }

            $this->output->write($data);
        }
    }
}

// 使用
$sourcePipe = Pipe::make('source');
$destPipe = Pipe::make('dest');

$processor = new StreamProcessor($sourcePipe, $destPipe);
$processor
    ->addFilter('strtoupper')
    ->addFilter(fn($data) => trim($data))
    ->addFilter(fn($data) => "[{$data}]");

// 模拟数据源
$sourcePipe->write("  data1  \n");
$sourcePipe->write("  data2  \n");
$sourcePipe->write("  data3  \n");
$sourcePipe->close();

// 处理
$processor->process();

// 获取结果
while (($result = $destPipe->read()) !== null) {
    echo $result;
}
```

---

## 注意事项

### 1. 管道命名

```php
<?php
use Kode\Parallel\Pipe\Pipe;

// ✅ 推荐：使用有意义的名称
$pipe = Pipe::make('app:worker:result:1');

// ❌ 避免：特殊字符
$pipe = Pipe::make('pipe|with|special|chars');
```

### 2. 超时设置

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('timeout_demo');

// 合理的超时设置
$writeTimeout = 5000;  // 5 秒（适合本地管道）
$readTimeout = 1000;   // 1 秒（适合实时数据）

$data = $pipe->read(1024, $readTimeout);
if ($data === null) {
    // 处理超时
    echo "读取超时\n";
}
```

### 3. 资源清理

```php
<?php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('cleanup_demo');

try {
    // 使用管道
    $pipe->write('data');
    $result = $pipe->read();
} finally {
    // 确保关闭
    $pipe->close();
}
```

### 4. 错误处理

```php
<?php
use Kode\Parallel\Pipe\Pipe;

try {
    $pipe = Pipe::make('error_demo');

    if (!$pipe->isWritable()) {
        throw new RuntimeException('管道不可写');
    }

    $pipe->write('data');

    if (!$pipe->isReadable()) {
        throw new RuntimeException('管道不可读');
    }

    $result = $pipe->read();
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
} finally {
    if (isset($pipe)) {
        $pipe->close();
    }
}
```

---

## 与 Channel 的对比

| 特性 | Pipe | Channel |
|------|------|---------|
| 进程间通信 | ✅ 支持 | ✅ 支持 |
| 线程间通信 | ✅ 支持 | ✅ 支持 |
| 方向 | 单向 | 双向 |
| 数据类型 | 字节流 | 任意类型（序列化） |
| 适用场景 | I/O 流 | 并行计算结果传递 |
