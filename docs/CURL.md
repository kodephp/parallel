# Kode/Parallel CurlMulti 并行请求详解

## 目录

- [简介](#简介)
- [CurlMulti 类](#curlmulti-类)
- [基本用法](#基本用法)
- [高级用法](#高级用法)
- [PHP 8.5 持久化 cURL](#php-85-持久化-curl)
- [实战案例](#实战案例)
- [注意事项](#注意事项)

---

## 简介

`Kode\Parallel\Curl\CurlMulti` 提供便捷的多线程 HTTP 请求能力，支持并发执行多个 HTTP 请求并统一处理结果。

### 与 Runtime 的关系

```
┌─────────────────────────────────────────────┐
│              Runtime (并行执行)                │
│                                             │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐    │
│  │ Task 1  │  │ Task 2  │  │ Task 3  │    │
│  │ (HTTP)  │  │ (HTTP)  │  │ (HTTP)  │    │
│  └────┬────┘  └────┬────┘  └────┬────┘    │
│       │            │            │           │
│       ▼            ▼            ▼           │
│  ┌─────────────────────────────────────┐    │
│  │         CurlMulti (并发请求)          │    │
│  │   支持持久化连接、超时控制、重试机制    │    │
│  └─────────────────────────────────────┘    │
└─────────────────────────────────────────────┘
```

---

## CurlMulti 类

### 类结构

```php
namespace Kode\Parallel\Curl;

final class CurlMulti
{
    public function __construct();

    // 请求管理
    public function add(string $url, array $options = [], ?string $key = null): string;
    public function get(string $url, array $headers = [], ?string $key = null): string;
    public function post(string $url, array|string $data = [], array $headers = [], ?string $key = null): string;

    // 执行
    public function execute(int $timeout = 30): array;
    public function clear(): void;

    // 状态
    public function count(): int;
    public function error(): ?string;
}
```

---

## 基本用法

### 1. 创建和添加请求

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Curl\CurlMulti;

// 创建实例
$curl = new CurlMulti();

// 添加 GET 请求
$curl->get('https://api.example.com/users', [], 'users');

// 添加 POST 请求
$curl->post('https://api.example.com/posts', [
    'title' => 'Hello World',
    'content' => 'This is a test post.',
], [], 'new_post');

// 执行请求
$results = $curl->execute();

print_r($results);
```

### 2. 处理响应

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();
$curl->get('https://httpbin.org/get', [], 'test');
$curl->get('https://httpbin.org/ip', [], 'ip');

$results = $curl->execute();

foreach ($results as $key => $result) {
    echo "=== {$key} ===\n";

    if ($result['error']) {
        echo "错误: " . $result['error'] . "\n";
    } else {
        echo "响应: " . $result['response'] . "\n";
        echo "HTTP 状态码: " . $result['info']['http_code'] . "\n";
    }
}
```

### 3. 并行请求

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$urls = [
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/2',
    'https://httpbin.org/delay/3',
];

$curl = new CurlMulti();

foreach ($urls as $index => $url) {
    $curl->get($url, [], "request_{$index}");
}

// 批量执行 - 总耗时约为最慢请求的时间
$startTime = microtime(true);
$results = $curl->execute(30);
$totalTime = microtime(true) - $startTime;

echo "总耗时: " . round($totalTime, 2) . " 秒\n";
echo "请求数量: " . count($results) . "\n";
```

---

## 高级用法

### 1. 自定义 Curl 选项

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 添加带自定义选项的请求
$curl->add('https://api.example.com/data', [
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_USERAGENT => 'MyApp/1.0',
], 'secure_request');

// 执行
$results = $curl->execute();
```

### 2. 设置请求头

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 添加带请求头的请求
$curl->get('https://api.example.com/users', [
    'Authorization: Bearer token123',
    'Accept: application/json',
    'X-Custom-Header: custom-value',
], 'authenticated_request');

// 添加带 JSON 请求头的 POST
$curl->post('https://api.example.com/api', [
    'name' => 'John',
    'email' => 'john@example.com',
], [
    'Content-Type: application/json',
    'Authorization: Bearer token123',
], 'json_post');

$results = $curl->execute();
```

### 3. 处理 JSON 响应

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();
$curl->get('https://api.github.com/users/kodephp', [], 'github_user');

$results = $curl->execute();

$githubUser = json_decode(
    $results['github_user']['response'],
    true
);

echo "用户名: " . $githubUser['login'] . "\n";
echo "粉丝数: " . $githubUser['followers'] . "\n";
echo "仓库数: " . $githubUser['public_repos'] . "\n";
```

### 4. 文件上传

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 上传文件
$curl->add('https://api.example.com/upload', [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => new CURLFile('/path/to/file.txt'),
        'description' => 'Test file upload',
    ],
], 'file_upload');

$results = $curl->execute();

if ($results['file_upload']['error']) {
    echo "上传失败: " . $results['file_upload']['error'] . "\n";
} else {
    echo "上传成功: " . $results['file_upload']['response'] . "\n";
}
```

### 5. 请求超时控制

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 添加多个请求
$curl->get('https://slow-api.example.com/1', [], 'slow_1');
$curl->get('https://slow-api.example.com/2', [], 'slow_2');

// 设置总超时为 10 秒
$results = $curl->execute(10);

foreach ($results as $key => $result) {
    if ($result['info']['http_code'] === 0) {
        echo "{$key} 请求超时或失败\n";
    } else {
        echo "{$key} 成功: " . $result['info']['http_code'] . "\n";
    }
}
```

---

## PHP 8.5 持久化 cURL

PHP 8.5 引入了持久化 cURL Share 句柄，可以在多个请求之间保持连接。

### 1. 持久化连接优势

```
传统模式：
  请求 1 ──▶ 连接 ──▶ 关闭
  请求 2 ──▶ 连接 ──▶ 关闭
  请求 3 ──▶ 连接 ──▶ 关闭

持久化模式：
  请求 1 ──▶ 连接 ──▶ 保持
  请求 2 ──▶ 连接(复用) ──▶ 保持
  请求 3 ──▶ 连接(复用) ──▶ 关闭
```

### 2. 性能对比

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

// 传统模式
$curl = new CurlMulti();
for ($i = 0; $i < 10; $i++) {
    $curl->get("https://api.example.com/item/{$i}", [], "item_{$i}");
}

$start = hrtime(true);
$results = $curl->execute();
$traditionalTime = (hrtime(true) - $start) / 1_000_000;

echo "传统模式耗时: " . round($traditionalTime, 2) . " ms\n";
```

### 3. 连接复用

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 多个请求到同一主机
$curl->get('https://api.github.com/users/kodephp', [], 'user1');
$curl->get('https://api.github.com/users/php', [], 'user2');
$curl->get('https://api.github.com/users/facebook', [], 'user3');

// CurlMulti 会自动复用连接
$results = $curl->execute();

// 检查连接信息
foreach ($results as $key => $result) {
    $info = $result['info'];
    echo "{$key}: {$info['primary_ip']}:{$info['primary_port']}\n";
}
```

---

## 实战案例

### 案例 1：并行获取多个 API 数据

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Curl\CurlMulti;

function fetchMultipleApis(array $endpoints): array
{
    $curl = new CurlMulti();

    // 添加所有请求
    foreach ($endpoints as $name => $url) {
        $curl->get($url, [
            'Accept: application/json',
        ], $name);
    }

    // 执行请求
    $results = $curl->execute();

    // 处理结果
    $data = [];
    foreach ($results as $name => $result) {
        if ($result['error']) {
            $data[$name] = ['error' => $result['error']];
        } else {
            $data[$name] = json_decode($result['response'], true);
        }
    }

    return $data;
}

// 使用
$endpoints = [
    'user' => 'https://api.example.com/users/current',
    'posts' => 'https://api.example.com/posts',
    'settings' => 'https://api.example.com/settings',
    'notifications' => 'https://api.example.com/notifications',
];

$data = fetchMultipleApis($endpoints);
print_r($data);
```

### 案例 2：批量提交数据

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Curl\CurlMulti;

function batchSubmit(array $items): array
{
    $curl = new CurlMulti();

    // 为每个项目创建请求
    foreach ($items as $index => $item) {
        $curl->post('https://api.example.com/items', [
            'name' => $item['name'],
            'price' => $item['price'],
            'quantity' => $item['quantity'],
        ], [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($item['token'] ?? ''),
        ], "item_{$index}");
    }

    // 执行
    $results = $curl->execute();

    // 收集成功的结果
    $submitted = [];
    foreach ($results as $key => $result) {
        if (!$result['error'] && $result['info']['http_code'] === 201) {
            $submitted[$key] = json_decode($result['response'], true);
        }
    }

    return $submitted;
}

// 使用
$items = [
    ['name' => 'Apple', 'price' => 1.99, 'quantity' => 100],
    ['name' => 'Banana', 'price' => 0.99, 'quantity' => 200],
    ['name' => 'Orange', 'price' => 2.49, 'quantity' => 150],
];

$results = batchSubmit($items);
print_r($results);
```

### 案例 3：与 Runtime 集成

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Curl\CurlMulti;
use Kode\Parallel\Runtime\Runtime;
use Kode\Parallel\Channel\Channel;

function parallelApiCalls(array $urls): array
{
    $runtime = new Runtime();
    $resultChannel = Channel::make('api_results');

    // 在 Runtime 中执行 HTTP 请求
    $task = function() use ($urls, $resultChannel) {
        $curl = new CurlMulti();

        foreach ($urls as $index => $url) {
            $curl->get($url, [], "url_{$index}");
        }

        $results = $curl->execute();

        foreach ($results as $index => $result) {
            $resultChannel->send([
                'url' => $url,
                'status' => $result['info']['http_code'] ?? 0,
                'data' => $result['error'] ?: json_decode($result['response'], true),
            ]);
        }

        $resultChannel->close();
        return count($results);
    };

    $future = $runtime->run($task);

    // 收集结果
    $results = [];
    while (!$resultChannel->isEmpty()) {
        $results[] = $resultChannel->recv();
    }

    $future->wait();
    $runtime->close();

    return $results;
}

// 使用
$urls = [
    'https://httpbin.org/get',
    'https://httpbin.org/ip',
    'https://httpbin.org/headers',
];

$results = parallelApiCalls($urls);
print_r($results);
```

### 案例 4：带重试的请求

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Curl\CurlMulti;

function fetchWithRetry(string $url, int $maxRetries = 3, int $timeout = 30): ?array
{
    $attempt = 0;

    while ($attempt < $maxRetries) {
        $curl = new CurlMulti();
        $curl->get($url, [], 'retry_request');

        $results = $curl->execute($timeout);
        $result = $results['retry_request'] ?? null;

        if ($result && !$result['error'] && $result['info']['http_code'] < 500) {
            return $result;
        }

        $attempt++;
        echo "重试 {$attempt}/{$maxRetries}\n";
        usleep(100000 * $attempt); // 递增延迟
    }

    return null;
}

// 使用
$result = fetchWithRetry('https://unstable-api.example.com/data');

if ($result) {
    echo "成功: " . $result['response'] . "\n";
} else {
    echo "多次重试后仍然失败\n";
}
```

---

## 注意事项

### 1. 错误处理

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();
$curl->get('https://invalid-domain-12345.com', [], 'invalid');

$results = $curl->execute();

foreach ($results as $key => $result) {
    if ($result['error']) {
        echo "[{$key}] cURL 错误: " . $result['error'] . "\n";
    }

    if ($result['info']['http_code'] >= 400) {
        echo "[{$key}] HTTP 错误: " . $result['info']['http_code'] . "\n";
    }
}
```

### 2. 内存管理

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

// 大批量请求时使用后清理
function batchProcess(array $batches)
{
    $allResults = [];

    foreach ($batches as $batch) {
        $curl = new CurlMulti();

        foreach ($batch as $item) {
            $curl->get($item['url'], [], $item['id']);
        }

        $results = $curl->execute();
        $allResults = array_merge($allResults, $results);

        // 清理当前批次的资源
        $curl->clear();
        unset($curl);

        // 触发垃圾回收
        gc_collect_cycles();
    }

    return $allResults;
}
```

### 3. SSL 证书问题

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 禁用 SSL 验证（仅用于测试）
$curl->add('https://self-signed-cert.example.com', [
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
], 'insecure');

// 或者指定证书文件
$curl->add('https://secure.example.com', [
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => '/path/to/ca-bundle.crt',
], 'secure');
```

### 4. 并发限制

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 避免一次性添加过多请求
$batchSize = 10;
$totalUrls = 100;

for ($i = 0; $i < $totalUrls; $i += $batchSize) {
    $batch = array_slice($urls, $i, $batchSize);

    foreach ($batch as $url) {
        $curl->get($url, [], "url_{$url}");
    }

    $results = $curl->execute();
    processResults($results);

    // 清理并开始下一批
    $curl->clear();
}
```

### 5. 超时设置建议

```php
<?php
use Kode\Parallel\Curl\CurlMulti;

$curl = new CurlMulti();

// 连接超时：建立连接的时间
// 读取超时：数据传输的时间

$curl->add('https://api.example.com/data', [
    CURLOPT_CONNECTTIMEOUT => 5,  // 5 秒连接超时
    CURLOPT_TIMEOUT => 30,         // 30 秒总超时
], 'timed_request');

$results = $curl->execute();
```

---

## 与其他库对比

| 特性 | CurlMulti | Guzzle | HTTP Client |
|------|----------|--------|-------------|
| 并发请求 | ✅ 原生支持 | ✅ 需 Promise | ✅ 需 async |
| 内存占用 | 低 | 中 | 中 |
| 依赖 | 无 | 需要 Guzzle | 需要 ext-http |
| PHP 8.1+ | ✅ | ✅ | ✅ |
| 持久化连接 | ✅ (PHP 8.5) | ✅ | ✅ |
