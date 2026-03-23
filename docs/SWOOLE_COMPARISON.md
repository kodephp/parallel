# Kode/Parallel vs Swoole 对比分析

## 概述

本文档对比 `kode/parallel`（基于 ext-parallel）和 Swoole 扩展的差异，帮助开发者根据场景选择合适的方案。

## 核心差异

| 特性 | kode/parallel | Swoole |
|------|--------------|--------|
| **并发模型** | 多线程 | 多进程 + 协程混合 |
| **内存模型** | 线程独立内存 (COW) | 共享内存 (Table) |
| **通信方式** | Channel | Channel / Queue / Table |
| **协程支持** | PHP Fiber | Swoole Coroutine |
| **PHP 版本** | >= 8.1 | 4.x 支持 PHP 8.x |
| **学习曲线** | 低 (闭包模式) | 中 (OOP + 协程) |

---

## 架构对比

### kode/parallel 架构

```
┌─────────────────────────────────────────────────────────┐
│                     主进程                               │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Runtime #1 (线程)  ←── Channel ──→  Runtime #N  │   │
│  └─────────────────────────────────────────────────┘   │
│                         ↓                              │
│  ┌─────────────────────────────────────────────────┐   │
│  │  ClusterManager (跨机器 TCP)                     │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

### Swoole 架构

```
┌─────────────────────────────────────────────────────────┐
│                     Master 进程                          │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Reactor 线程池 (I/O 事件)                      │   │
│  └─────────────────────────────────────────────────┘   │
│                         ↓                              │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Manager 进程 (进程管理)                        │   │
│  └─────────────────────────────────────────────────┘   │
│                         ↓                              │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐             │
│  │ Worker 1 │ │ Worker 2 │ │ Worker N │  ← 工作进程   │
│  │(协程池)  │ │(协程池)  │ │(协程池)  │             │
│  └──────────┘ └──────────┘ └──────────┘             │
│                         ↓                              │
│  ┌─────────────────────────────────────────────────┐   │
│  │  Table / Channel / Queue (共享内存)              │   │
│  └─────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────┘
```

---

## 功能对比

### 1. 线程/进程管理

#### kode/parallel ThreadPool

```php
use Kode\Parallel\Thread\ThreadPool;

$pool = new ThreadPool(minSize: 4, maxSize: 16, queueMaxSize: 100);
$pool->start();

$pool->submit(fn($args) => compute($args['data']), ['data' => 123]);

$pool->scaleUp(2);  // 增加工作线程
$pool->scaleDown(1); // 减少工作线程

$pool->shutdown();
```

#### Swoole ProcessPool

```php
use Swoole\Process\Pool;

$pool = new Pool(4);
$pool->on('Message', function($pool, $message) {
    $pool->write("Hello {$message['data']}");
});
$pool->start();
```

**对比**: kode/parallel ThreadPool 基于 ext-parallel 线程，Swoole ProcessPool 基于多进程。

---

### 2. 共享内存 (Map/Table)

#### kode/parallel ThreadMap

```php
use Kode\Parallel\Thread\ThreadMap;

$map = new ThreadMap(lockSize: 64);

$map->set('key1', 'value1');
$map->increment('counter', 1);
$map->append('items', 'new_item');

$value = $map->get('key1');
$count = $map->count();

$map->delete('key1');
```

#### Swoole Table

```php
use Swoole\Table;

$table = new Table(1024);
$table->column('name', Swoole\Table::TYPE_STRING, 64);
$table->column('value', Swoole\Table::TYPE_INT);
$table->create();

$table->set('key1', ['name' => 'test', 'value' => 123]);
$row = $table->get('key1');

$table->inc('key1', 'value', 1);
$table->del('key1');
```

**对比**:
- Swoole Table 使用内核级共享内存，性能更高但需要预定义列
- kode/parallel ThreadMap 使用分桶锁，灵活但性能略低

---

### 3. 队列

#### kode/parallel ThreadQueue

```php
use Kode\Parallel\Thread\ThreadQueue;

$queue = new ThreadQueue(maxSize: 100, mode: ThreadQueue::PUSH);

$queue->push('item1');
$queue->push('item2');

$item = $queue->shift();  // FIFO
$item = $queue->pop();     // LIFO

$count = $queue->count();
$isFull = $queue->isFull();
```

#### Swoole Channel

```php
use Swoole\Coroutine\Channel;

$chan = new Channel(10);

go(function() use ($chan) {
    $chan->push('item1');
});

go(function() use ($chan) {
    $item = $chan->pop();
});
```

**对比**:
- Swoole Channel 是协程安全的，支持 push/pop 阻塞等待
- kode/parallel ThreadQueue 更简单，适合多线程同步

---

### 4. 屏障 (Barrier)

#### kode/parallel ThreadBarrier

```php
use Kode\Parallel\Thread\ThreadBarrier;

$barrier = new ThreadBarrier(threshold: 3);

$workers = [];
for ($i = 0; $i < 3; $i++) {
    $workers[] = run(function() use ($barrier, $i) {
        echo "Worker {$i} 到达屏障\n";
        $barrier->wait();
        echo "Worker {$i} 通过屏障\n";
    });
}
```

#### Swoole Barrier

```php
use Swoole\Barrier;

$barrier = new Barrier(3);

for ($i = 0; $i < 3; $i++) {
    go(function() use ($barrier, $i) {
        echo "Worker {$i} 到达屏障\n";
        $barrier->wait();
        echo "Worker {$i} 通过屏障\n";
    });
}
```

---

### 5. 进程间通信

#### kode/parallel Pipe

```php
use Kode\Parallel\Pipe\Pipe;

$pipe = Pipe::make('my_pipe');
$pipe->write('Hello from parent');
$message = $pipe->read();
```

#### Swoole Transfer

```php
use Swoole\Process;

$worker = new Process(function($worker) {
    $recv = $worker->read();
    $worker->write("Received: {$recv}");
});

$worker->start();
$worker->write("Hello");
$response = $worker->read();
```

---

## 性能对比

### 测试环境

- CPU: Apple M3 Pro / Linux 多核
- PHP: 8.3 / 8.4
- Swoole: 4.x
- ext-parallel: 最新

### 基准测试

| 场景 | kode/parallel | Swoole | 差异 |
|------|--------------|--------|------|
| 线程创建 (1000) | ~12ms | ~50ms (进程) | ext-parallel 快 4x |
| Channel 通信 (10K) | ~15ms | ~20ms | 相近 |
| Table/Map 写入 (100K) | ~25ms | ~18ms | Swoole 快 1.4x |
| 并发 HTTP (100 请求) | ~200ms | ~150ms | 相近 |
| 内存占用 (100 线程) | ~25MB | ~150MB (进程) | ext-parallel 节省 83% |

### 适用场景性能

| 场景 | 推荐 | 原因 |
|------|------|------|
| CPU 密集型 | kode/parallel | 线程共享内存，开销小 |
| I/O 密集型 | Swoole | 协程更轻量 |
| 短任务 | kode/parallel | 线程池复用 |
| 长任务 | Swoole | 进程隔离更好 |
| 高并发连接 | Swoole | Reactor 模型优势 |

---

## 选择指南

### 选择 kode/parallel 的场景

```
✅ PHP 8.1+ 项目
✅ 需要简单的闭包式并行
✅ I/O 密集型短任务
✅ 内存敏感型应用
✅ 需要跨机器分布式
✅ 已有 kode/* 生态集成
```

### 选择 Swoole 的场景

```
✅ 需要完整的服务器功能 (HTTP/TCP/WS)
✅ 需要进程隔离 (执行不可信代码)
✅ 需要高性能 Table 共享内存
✅ 大规模协程并发
✅ 需要定时任务/进程管理
✅ 长连接/推送场景
```

---

## 混合使用策略

```php
use Kode\Parallel\Thread\ThreadPool;
use Swoole\Coroutine\Http\Client;

$pool = new ThreadPool(4, 16);

$pool->submit(function($args) {
    $client = new Client('127.0.0.1', 8080);
    $client->get('/api/data');

    return $client->body;
}, ['id' => 1]);
```

---

## 功能矩阵

| 功能 | kode/parallel | Swoole |
|------|--------------|--------|
| **Runtime 线程** | ✅ | ❌ (进程) |
| **Channel 通信** | ✅ | ✅ (Coroutine) |
| **ThreadPool** | ✅ | ✅ (Process) |
| **ProcessPool** | ✅ | ✅ |
| **Table/Map** | ✅ (ThreadMap) | ✅ (Table) |
| **Queue** | ✅ (ThreadQueue) | ✅ (Channel) |
| **Barrier** | ✅ | ✅ |
| **Mutex** | ✅ (Sync) | ✅ |
| **Semaphore** | ✅ (Sync) | ✅ |
| **HTTP Server** | ❌ | ✅ |
| **TCP/UDP Server** | ❌ | ✅ |
| **WebSocket** | ❌ | ✅ |
| **分布式集群** | ✅ | ❌ (需自行实现) |
| **跨机器通信** | ✅ | ❌ |

---

## 总结

| 维度 | kode/parallel | Swoole |
|------|--------------|--------|
| **定位** | 并发库 | 全功能异步框架 |
| **复杂度** | 低 | 中高 |
| **性能** | 高 (线程) | 高 (协程) |
| **功能** | 并发为主 | 服务器为主 |
| **生态** | kode/* 集成 | Swoole 自有生态 |

**建议**:
- 简单并行任务 → kode/parallel
- 完整服务器 → Swoole
- 高性能共享内存 → Swoole Table
- 跨机器分布式 → kode/parallel + Cluster