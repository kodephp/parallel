# Kode/Parallel 分布式集群指南

## 概述

`kode/parallel` v1.3.0 引入了完整的分布式集群支持，支持跨机器任务执行、节点管理、主节点选举等功能。

## 架构模型

```
┌─────────────────────────────────────────────────────────────────┐
│                         分布式集群                               │
│                                                                 │
│  ┌─────────────┐      ┌─────────────┐      ┌─────────────┐    │
│  │   节点 #1    │      │   节点 #2    │      │   节点 #N    │    │
│  │ tcp://host1 │◄────►│ tcp://host2 │◄────►│ tcp://hostN │    │
│  │ :8001       │      │ :8002       │      │ :800N       │    │
│  └──────┬──────┘      └──────┬──────┘      └──────┬──────┘    │
│         │                    │                    │            │
│         └────────────────────┼────────────────────┘            │
│                              │                                 │
│                    ┌─────────▼─────────┐                       │
│                    │   ClusterManager   │                       │
│                    │   (Master Node)    │                       │
│                    │   - 任务调度        │                       │
│                    │   - 负载均衡        │                       │
│                    │   - 故障转移        │                       │
│                    └───────────────────┘                       │
└─────────────────────────────────────────────────────────────────┘
```

## 核心组件

| 组件 | 说明 |
|------|------|
| **Node** | 节点表示（host:port + metadata） |
| **TcpNodeTransport** | TCP 节点传输层 |
| **ClusterManager** | 集群管理器（调度、选举） |
| **ClusterServer** | 集群服务器（任务执行器） |

---

## 快速开始

### 1. 启动集群节点服务器

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Cluster\ClusterServer;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$node = new Node('0.0.0.0', 8001, 'worker-1', [
    'capacity' => 100,
    'metadata' => ['region' => 'us-east'],
]);

$runtime = new Runtime();
$server = new ClusterServer($node, $runtime);

echo "启动集群节点: {$node->getAddress()}\n";
$server->start();
```

### 2. 客户端提交任务

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Cluster\ClusterManager;
use Kode\Parallel\Network\Node;

$manager = new ClusterManager();

$manager->setLocalNode(new Node('127.0.0.1', 8001, 'client-1'));

$manager->addNode(new Node('192.168.1.101', 8001, 'worker-1', [
    'capacity' => 100,
]));

$manager->addNode(new Node('192.168.1.102', 8001, 'worker-2', [
    'capacity' => 80,
]));

$result = $manager->submitTask(
    'my-task-1',
    fn($args) => $args['a'] + $args['b'],
    ['a' => 10, 'b' => 20]
);

print_r($result);
```

---

## 节点管理

### 创建节点

```php
use Kode\Parallel\Network\Node;

$node = new Node(
    '192.168.1.100',  // host
    8001,              // port
    'worker-1',        // optional id
    [                  // metadata
        'capacity' => 100,
        'region' => 'us-west',
        'tags' => ['cpu-intensive'],
    ]
);
```

### 节点属性

```php
$node->getId();       // 节点唯一 ID
$node->getHost();     // 主机地址
$node->getPort();     // 端口
$node->getAddress();  // tcp://host:port
$node->getMetadata(); // 元数据
$node->isHealthy();   // 健康状态
```

---

## TcpNodeTransport 传输层

### 基本用法

```php
use Kode\Parallel\Network\TcpNodeTransport;
use Kode\Parallel\Network\Node;

$transport = new TcpNodeTransport();

$node1 = new Node('192.168.1.101', 8001, 'node-1');
$node2 = new Node('192.168.1.102', 8001, 'node-2');

$transport->registerNode($node1);
$transport->registerNode($node2);

$response = $transport->send('node-1', [
    'type' => 'ping',
    'data' => 'hello',
]);

print_r($response);
```

### 广播消息

```php
$results = $transport->broadcast([
    'type' => 'shutdown',
    'graceful' => true,
]);

foreach ($results as $nodeId => $result) {
    echo "{$nodeId}: " . ($result['success'] ? 'OK' : $result['error']) . "\n";
}
```

### 重试机制

```php
$transport->setMaxRetries(3);
$transport->setTimeouts(5.0, 30.0);

try {
    $response = $transport->sendWithRetry('node-1', $payload);
} catch (\Kode\Parallel\Exception\ParallelException $e) {
    echo "发送失败: " . $e->getMessage() . "\n";
}
```

---

## ClusterManager 集群管理

### 初始化

```php
use Kode\Parallel\Cluster\ClusterManager;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$runtime = new Runtime();
$manager = new ClusterManager($runtime);

$localNode = new Node('192.168.1.100', 8001, 'master', [
    'capacity' => 100,
    'load' => 0,
]);

$manager->setLocalNode($localNode);
```

### 添加工作节点

```php
$manager->addNode(new Node('192.168.1.101', 8001, 'worker-1', [
    'capacity' => 100,
    'region' => 'us-west',
]));

$manager->addNode(new Node('192.168.1.102', 8001, 'worker-2', [
    'capacity' => 80,
    'region' => 'us-east',
]));
```

### 主节点选举

```php
if ($manager->electMaster()) {
    echo "当前节点是主节点\n";
} else {
    $master = $manager->getMasterNode();
    echo "主节点是: {$master->getAddress()}\n";
}
```

### 提交任务

```php
$result = $manager->submitTask(
    'compute-task-1',
    function($args) {
        $sum = 0;
        for ($i = 0; $i < 1000000; $i++) {
            $sum += $i;
        }
        return $sum;
    },
    []
);

if ($result['success']) {
    echo "结果: " . $result['result'] . "\n";
    echo "耗时: " . round($result['duration'] * 1000, 2) . "ms\n";
    echo "执行节点: " . $result['node'] . "\n";
}
```

### 广播任务

```php
$results = $manager->broadcast(
    function($args) {
        return [
            'hostname' => gethostname(),
            'memory' => memory_get_usage(true),
            'time' => time(),
        ];
    },
    []
);

foreach ($results as $nodeId => $result) {
    echo "{$nodeId}: ";
    print_r($result['result'] ?? $result['error']);
}
```

### 健康检查

```php
$status = $manager->healthCheck();

foreach ($status as $nodeId => $info) {
    $status_str = $info['healthy'] ? '✅ 健康' : '❌ 不健康';
    echo "{$nodeId}: {$status_str}\n";
    echo "  最后心跳: " . round($info['time_since_heartbeat'], 1) . "秒前\n";
}
```

---

## ClusterServer 服务器端

### 启动服务器

```php
use Kode\Parallel\Cluster\ClusterServer;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$node = new Node('0.0.0.0', 8001, 'server-1');
$runtime = new Runtime(__DIR__ . '/bootstrap.php');

$server = new ClusterServer($node, $runtime);
$server->setHeartbeatInterval(5.0);

echo "启动服务器: {$node->getAddress()}\n";
$server->start();
```

### 服务器事件处理

```php
while ($server->isRunning()) {
    echo "活跃任务: " . $server->getActiveTaskCount() . "\n";
    echo "连接客户端: " . $server->getClientCount() . "\n";
    sleep(1);
}
```

### 优雅关闭

```php
$server->stop();
echo "服务器已停止\n";
```

---

## 完整示例

### 主节点程序 (master.php)

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Cluster\ClusterManager;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$runtime = new Runtime();
$manager = new ClusterManager($runtime);

$manager->setLocalNode(new Node('192.168.1.100', 9000, 'master'));

$workers = [
    ['host' => '192.168.1.101', 'port' => 8001, 'id' => 'worker-1'],
    ['host' => '192.168.1.102', 'port' => 8001, 'id' => 'worker-2'],
    ['host' => '192.168.1.103', 'port' => 8001, 'id' => 'worker-3'],
];

foreach ($workers as $w) {
    $manager->addNode(new Node($w['host'], $w['port'], $w['id'], [
        'capacity' => 100,
    ]));
}

echo "=== 主节点启动 ===\n";
echo "本地节点: " . $manager->getLocalNode()->getAddress() . "\n";
echo "工作节点: " . count($manager->getNodes()) . " 个\n\n";

echo "=== 提交并发任务 ===\n";
$tasks = [];
for ($i = 0; $i < 10; $i++) {
    $tasks[] = $manager->submitTask(
        "task-{$i}",
        fn($args) => [
            'id' => $args['id'],
            'compute' => array_sum(range(1, 100000)),
            'host' => gethostname(),
        ],
        ['id' => $i]
    );
}

echo "=== 收集结果 ===\n";
$success = 0;
$failed = 0;

foreach ($tasks as $task) {
    if ($task['success']) {
        $success++;
        echo "✅ {$task['task_id']}: {$task['result']['host']} (" . round($task['duration'] * 1000) . "ms)\n";
    } else {
        $failed++;
        echo "❌ {$task['task_id']}: {$task['error']}\n";
    }
}

echo "\n成功: {$success}, 失败: {$failed}\n";

echo "\n=== 集群健康状态 ===\n";
$health = $manager->healthCheck();
foreach ($health as $nodeId => $info) {
    echo "{$nodeId}: " . ($info['healthy'] ? '✅' : '❌') . "\n";
}

$runtime->close();
```

### 工作节点程序 (worker.php)

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Kode\Parallel\Cluster\ClusterServer;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

$nodeId = $argv[1] ?? 'worker-1';
$port = (int) ($argv[2] ?? 8001);

$node = new Node('0.0.0.0', $port, $nodeId, [
    'capacity' => 100,
    'start_time' => time(),
]);

$runtime = new Runtime();
$server = new ClusterServer($node, $runtime);

echo "启动工作节点: {$node->getAddress()} (ID: {$nodeId})\n";

pcntl_signal(SIGTERM, function() use ($server) {
    echo "收到 SIGTERM，停止服务器...\n";
    $server->stop();
});

pcntl_signal(SIGINT, function() use ($server) {
    echo "收到 SIGINT，停止服务器...\n";
    $server->stop();
});

$server->start();
```

---

## 运行示例

```bash
# 终端 1: 启动工作节点 1
php worker.php worker-1 8001

# 终端 2: 启动工作节点 2
php worker.php worker-2 8002

# 终端 3: 启动工作节点 3
php worker.php worker-3 8003

# 终端 4: 启动主节点
php master.php
```

---

## 负载均衡策略

### 当前策略：基于容量

```php
private function selectNode(array $nodes): Node
{
    // 选择负载最低的节点
    // load / capacity 比值最小的节点被选中
}
```

### 自定义策略

```php
class CustomClusterManager extends ClusterManager
{
    protected function selectNode(array $nodes): Node
    {
        // 按地理位置选择
        $region = $_ENV['MY_REGION'] ?? 'us-west';

        $filtered = array_filter($nodes, function($node) use ($region) {
            return $node->getMetadata()['region'] ?? '' === $region;
        });

        if (empty($filtered)) {
            return parent::selectNode($nodes);
        }

        return parent::selectNode($filtered);
    }
}
```

---

## 故障处理

### 自动重试

```php
$transport->setMaxRetries(3);
$transport->setTimeouts(5.0, 30.0);

try {
    $result = $transport->sendWithRetry($nodeId, $payload);
} catch (ParallelException $e) {
    // 所有重试都失败了
    echo "节点 {$nodeId} 不可用: " . $e->getMessage() . "\n";
}
```

### 健康检查与故障转移

```php
while (true) {
    $health = $manager->healthCheck();

    foreach ($health as $nodeId => $info) {
        if (!$info['healthy']) {
            echo "节点 {$nodeId} 已下线，尝试故障转移...\n";

            // 重新提交到其他节点
            $newResult = $manager->submitTask(
                $info['task_id'] ?? uniqid(),
                $task,
                $args
            );
        }
    }

    sleep(5);
}
```

---

## 性能调优

### 网络配置

```php
$transport->setTimeouts(
    5.0,   // 连接超时 (秒)
    30.0   // 读取超时 (秒)
);

$transport->setMaxRetries(3);
```

### 集群规模

| 节点数 | 适用场景 | 建议配置 |
|--------|---------|---------|
| 2-5 | 开发/测试 | 默认配置 |
| 5-20 | 小规模生产 | 增加心跳间隔 |
| 20+ | 大规模生产 | 优化网络、减少广播 |

---

## 与 kode/fibers 集成

```php
use Kode\Parallel\Cluster\ClusterManager;
use Kode\Parallel\Fiber\FiberManager;

$cluster = new ClusterManager();
$fiberManager = new FiberManager();

$fiberManager->spawn('cluster-submit', function() use ($cluster) {
    $result = $cluster->submitTask('data-task', fn($args) => process($args), []);

    return $result['result'];
});

$fiberManager->startAll();
$results = $fiberManager->collect();
```

---

## 总结

| 功能 | 支持状态 |
|------|---------|
| 节点注册/注销 | ✅ |
| TCP 任务传输 | ✅ |
| 主节点选举 | ✅ |
| 负载均衡 | ✅ |
| 健康检查 | ✅ |
| 自动重试 | ✅ |
| 广播任务 | ✅ |
| 分布式 Fiber | ✅ (需 kode/fibers) |
| 故障转移 | ✅ |

详见 [ADVANCED_USAGE.md](ADVANCED_USAGE.md)