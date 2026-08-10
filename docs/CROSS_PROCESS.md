# 跨线程 / 跨进程的数据同步

> 适用版本：v1.18.0 ｜ 主线引擎：ZTS + ext-parallel 真线程；多进程由 `kode/process` 经 `EngineFactory::register()` 接入。

本库聚焦**多线程**（`parallel` 引擎）。无论线程还是进程，一个根本任务之间**不存在「共享内存里的 PHP 变量」**——这是使用并行最容易被忽略、也最容易导致「数据没生效 / 计数对不上」的根因。本文把这件事讲清楚，并给出正确的同步写法。

---

## 一、心智模型：任务之间只有三条通道

```
        主线程 / 主进程
            │
   ┌────────┼─────────────────────────────────────────┐
   │  ① 参数传入   （主 → 工作线程，序列化拷贝）          │
   │  ② 返回值传出   （工作线程 → 主，序列化拷贝）         │
   │  ③ 同步原语协调 （Atomic / Lock / Semaphore / Barrier）│
   └────────┼─────────────────────────────────────────┘
            │
        工作线程（独立 PHP 解释器）
```

- 通道 ①/② 传递的是**值的拷贝**（ext-parallel 序列化进出线程）。线程里改了某个对象，主线程拿到的还是旧副本。
- 通道 ③ 只负责**协调顺序与计数**，不负责「共享变量」。

---

## 二、六个硬真相（踩坑必读）

| # | 真相 | 反例（不会生效） | 正确做法 |
|---|------|----------------|---------|
| 1 | **全局变量 / 静态属性 / 单例不共享** | 主线程 `$GLOBALS['x']=1`，线程内读到的是空；`self::$cache` 每个线程各一份 | 需要共享计数用 `Atomic`；共享只读配置放 bootstrap 预加载 |
| 2 | **自动加载器不在线程内** | 线程里 `new Foo()` 报「类不存在」 | 构造池时传 bootstrap：`new WorkerPool(11, null, __DIR__.'/vendor/autoload.php')` |
| 3 | **资源不能跨线程传** | 把主线程的 `PDO` / `curl` / 文件句柄当参数传入 | 在线程内新建连接，用完即弃；每任务自包含事务 |
| 4 | **闭包必须「纯」** | 捕获了对象 / 资源 / `$this` 的闭包 | 只捕获标量/纯数组；需要对象状态就传参数进去 |
| 5 | **不可序列化对象会被丢弃** | 返回值里含未实现序列化的对象 → 退化为 `Unavailable` | 返回值只给标量/纯数组/可序列化 DTO；异常本库已自动降级重建为 `ParallelException` |
| 6 | **没有「共享内存变量」** | 以为 `Atomic` 是共享内存，期望零开销 | `Atomic` 跨进程模式是**文件锁 + 数据文件**的可移植实现（见下），有文件 I/O |

> 第 6 点是性能预期的关键：`Atomic` / `Lock` / `Semaphore` / `Barrier` 的**命名（跨进程）模式**底层是 `FileLock`（flock 文件锁）+ 数据文件，保证协调正确（高并发压测零丢失更新），但每次读写有文件 I/O，**吞吐远低于真正的共享内存**，也远低于同类的进程内内存模式。需要极致吞吐且只在单进程内协调时，用**未命名（不传 `$name`）**模式——纯内存、零 I/O、百万级 ops/s。

---

## 三、正确做法：按目的选原语

| 目的 | 原语 | 示例 |
|------|------|------|
| 全局计数 / 进度 / 去重序号 | `Atomic` | `Atomic::named(0, 'done')->inc()` |
| 限流（DB / 下游连接池） | `Semaphore` | `Semaphore::named(8, 'db_pool')` |
| 临界区（读改写需互斥） | `Lock` | `Lock::named('ledger')->withLock(fn () => ...)` |
| 多段任务汇合（栅栏） | `Barrier` | `new Barrier(4, 'phase1')` |
| 进程内生产者-消费者 | `Channel` | **仅同一运行时内** |
| 跨进程传递数据 | 返回值 / 参数 / 文件 | `Channel` **不能**跨进程 |

### 进程内 vs 跨进程模式（自动按是否传 `$name` 选择）

```php
use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\Lock;

// 未命名：纯内存，零 I/O —— 只在单进程/单运行时内协调时用
$counter = new Atomic(0);
$counter->inc();                 // 百万级 ops/s

// 命名：文件锁 + 数据文件 —— 多进程用同一 name 即共享同一计数
$shared  = Atomic::named(0, 'global_done');
$lock    = Lock::named('ledger');   // 多进程竞争同一把锁
```

> `Channel` 是单运行时内存队列，用于同一进程内的生产者-消费者；**真正的跨进程 IPC 请用任务返回值 / 参数，或文件 + `Lock`/`Atomic` 协调**，不要指望 `Channel` 跨进程。

---

## 四、典型模式与反模式

**✅ 计数器（进度上报 / 去重序号）**

```php
$pool = new WorkerPool(11, null, __DIR__ . '/vendor/autoload.php');
$done = Atomic::named(0, 'import_done');

$pool->mapBatch($rows, static function (array $row) use ($done) {
    import_row($row);
    $done->inc();          // 跨线程安全累加，零丢失
    return true;
});

echo $done->get(), " 行已导入\n";
```

**✅ 限流（保护下游连接池）**

```php
$db = Semaphore::named(8, 'db_pool');   // 至多 8 个并发 DB 操作

$pool->map($tasks, static function ($t) use ($db) {
    return $db->withPermits(1, fn () => query_db($t));
});
```

**✅ 临界区（读改写需互斥）**

```php
$lock = Lock::named('ledger');
$pool->mapBatch($items, static function ($it) use ($lock) {
    $lock->withLock(function () use ($it) {
        $balance = read_balance();     // 读
        $balance -= $it['amount'];     // 改
        write_balance($balance);       // 写 —— 整段互斥
    });
    return true;
});
```

**❌ 反模式：靠静态变量全局累加**

```php
class Counter { public static int $n = 0; }

// 每个线程是独立解释器，self::$n 各加各的，主线程看到的仍是 0
$pool->map($items, static function ($i) {
    Counter::$n++;          // 不会生效！必须用 Atomic
    return $i;
});
echo Counter::$n;           // 仍是 0
```

**❌ 反模式：在线程内读「主线程设好的全局配置对象」**

```php
App::setConfig($cfg);       // 主线程设置

$pool->map($items, static function ($i) {
    $cfg = App::getConfig();   // 线程内是 null / 默认，不是主线程那份
    ...
});
// 正确：把配置作为任务参数传入，或放进 bootstrap 让每个线程独立加载同一份只读配置
```

---

## 五、一致性边界（务必清醒）

- 同步原语保证**协调正确**（谁先谁后、计数不丢），**不保证业务一致性**。跨线程共享 DB 仍需事务 + 幂等键；`Atomic` 的计数文件与 DB 不在同一事务里，**不能把它当分布式事务**用。
- 需要**强故障隔离**（一个任务段错误不能拖垮其他任务）→ 用 `kode/process` 多进程，经 `EngineFactory::register()` 接入；线程共享地址空间，一个线程段错误会拖垮整个进程。
- 只读配置 / 常量：放进 bootstrap，每个线程启动时加载一份（线程内只读使用即可，不要试图在线程间互相修改）。

---

## 六、真实压测数据（跨进程同步原语吞吐，ZTS + ext-parallel 11 核实测）

`benchmarks/bench_concurrency.php` 直采，多跑取稳定值。重点：**命名（跨进程）模式吞吐比进程内模式低 2~3 个数量级**——这是文件锁 + 数据文件的固有代价，务必据此评估是否真需要跨进程协调。

| 原语 | 模式 | 实测吞吐 | 备注 |
|------|------|---------|------|
| `Atomic` | 进程内（纯内存） | **12.6M ops/s** | 零 I/O，百万级 |
| `Atomic` | 跨进程（命名，6 进程 × 5000 次） | **15,390 ops/s** | 30,000 次累加 **零丢失更新** ✅ |
| `Lock` | 跨进程（命名 flock） | **98,363 ops/s** | withLock 自增 |
| `Semaphore` | 进程内（纯内存） | **6.4M ops/s** | acquire+release |
| `Barrier` | 跨进程（命名，4 方 × 50 回合） | **234 回合/s** | 代际自动复用，无死锁 |
| `Channel` | 进程内（内存队列） | **12.8M ops/s** | send+recv |

**取舍结论**：
- 只在单进程/单运行时内协调（如多线程任务间的本地计数、限流）→ 用**未命名**模式，吞吐百万级、零文件 I/O。
- 必须跨进程协调（多 worker 进程共享计数 / 连接池 / 临界区）→ 用**命名**模式，接受 ~1万~10万 ops/s 量级；正确性优先，已验证零丢失。

> 屏障代际语义（v1.17.0 修复）：`Barrier` 采用「代际（generation）」机制自动复用。参与者**仅在本代被整体放行并进入下一代（gen 递增）后才返回**，杜绝了「前一代残留的 `released` 标志被新一代首个参与者误读、提前返回」的跨进程误唤醒（旧实现在高负载多轮常驻进程场景下可能死锁）。回归测试 `testNamedBarrierSustainedRoundsNoSpuriousWakeup` 以 4 进程 × 30 轮同名字屏障验证无死锁且完成数精确为 N×R。

---

## 相关文档

- [使用场景与选型](USE_CASES.md) — 单线程 vs 多线程真实提速、何时该开多线程
- [引擎与外部进程池接入](ENGINE.md)
- [单进程 / 多线程 / 多进程怎么选](PROCESS_VS_THREAD.md)
