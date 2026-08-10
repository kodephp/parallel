# 执行引擎（Engines）

`kode/parallel` v1.6.0 引入统一的**执行引擎抽象层**，让同一套 API（`Runtime` / `Future` / `WorkerPool` / 全局函数）在三种不同的并行后端上无缝运行。

## 为什么需要引擎

- **`ext-parallel` 不是必装项**。没有它，库也能通过 `sync` 引擎（同步回退）正常工作；需要多进程时再接入 `kode/process`。
- **代码与后端解耦**。业务代码只依赖 `FutureInterface` 契约，不关心底层是真线程、同步还是外部多进程后端。
- **可移植性**。同一份代码在共享主机、容器、CI、无 ZTS 的 PHP 环境都能跑。

## 内置引擎 + 可插拔外部后端

| 引擎 | 后端 | 并发模型 | 需要扩展 | 适用场景 |
|------|------|----------|----------|----------|
| `parallel` | `ext-parallel` 真线程 | 线程，共享进程内存 | `ext-parallel` | **本库主线**，性能最佳，真并行 CPU 密集 |
| `sync` | 当前进程内直接调用 | 同步、串行 | 无 | 兼容/调试/单元测试/受限环境 |
| 外部（如 `process`） | `kode/process` 等 | 取决于后端 | 取决于后端 | 需要多进程编排时通过 `register()` 接入 |

> 多进程编排**不是本包职责**。本库聚焦多线程；当你确实需要跨进程并行（如利用多机/隔离），
> 用 `EngineFactory::register()` 把 `kode/process` 注册为外部引擎即可与本库统一调度、自动探测协作。

## 引擎优先级与探测

内置引擎按 `[parallel, sync]` 顺序探测；通过 `register()` 注册的外部引擎按其 `priority` 插入排序：

```php
use Kode\Parallel\Engine\EngineFactory;

// 当前环境下自动选择的最高优先级引擎
$engine = EngineFactory::detect();      // e.g. "parallel"  (ZTS + ext-parallel)
var_dump(EngineFactory::available());   // ["parallel", "sync"]

// 强制指定（等价于环境变量 KODE_PARALLEL_ENGINE）
EngineFactory::setDefault('sync');
```

- 环境变量 `KODE_PARALLEL_ENGINE=parallel|sync|<已注册外部引擎名>` 可强制覆盖自动探测。
- 需要多进程时注册外部引擎（见下文「接入 kode/process」）。

## 统一契约：FutureInterface

所有引擎返回的 future 都实现 `FutureInterface`：

```php
interface FutureInterface {
    public function done(): bool;
    public function get();                 // 阻塞直到完成，异常向上抛
    public function getOrNull();           // 失败返回 null，不抛
    public function wait(int $timeoutMs = 0): bool;
    public function cancel(): void;
    public function isCancelled(): bool;
    public function getId(): string;
}
```

内置实现：`Future`（parallel 线程）、`ValueFuture`（已就绪/已失败）；外部引擎可返回同样实现 `FutureInterface` 的 future。

## 组合器：Futures

```php
use Kode\Parallel\Future\Futures;

// 全部完成（任一失败则整体失败）
$results = Futures::all([$f1, $f2, $f3]);

// 全部结算（不抛，返回 fulfilled / rejected 分类）
$settled = Futures::settle([$f1, $f2]);

// 任意一个先完成即返回
$winner = Futures::race([$f1, $f2]);

// 任意一个完成即返回（返回该 future）
$any = Futures::any([$f1, $f2]);

// 取消一组
Futures::cancelAll([$f1, $f2, $f3]);
```

以上组合器均支持超时参数。

## WorkerPool 工作池

引擎无关的并发池，自动限制并发上限并回收完成的工作单元：

```php
use Kode\Parallel\Pool\WorkerPool;

$pool = new WorkerPool(concurrency: 8);                 // 自动探测（ZTS 下即 parallel）
// 或显式指定线程数（parallel 引擎 = 线程数；并发上限即线程数）
$pool = new WorkerPool(concurrency: 8, engine: 'parallel');

$pool->submit(fn ($x) => $x * $x, [2]);
$pool->submit(fn ($x) => $x * $x, [3]);

// 并发映射，保持顺序
$squares = $pool->map(fn ($x) => $x * $x, [1, 2, 3, 4]);

// 容错映射，不抛
$settled = $pool->mapSettled(fn ($x) => 10 / $x, [1, 0, 2]);

print_r($pool->stats());  // 提交数 / 完成数 / 失败数 / 并发上限
$pool->close();
```

## 接入 kode/process（多进程后端）

本库内置只有 `parallel`（真线程）与 `sync`（回退）；多进程不是本包职责。当你需要真正的
多进程并行（更彻底的隔离、可跨机器、复用 kode/process 的集群能力），通过 `EngineFactory::register()`
把它接入，即可参与自动探测与本库统一调度：

```php
use Kode\Parallel\Engine\EngineFactory;

EngineFactory::register(
    name: 'process',
    factory: static fn(?string $bootstrap, int $workers): EngineInterface
        => new \Kode\Process\Parallel\EngineAdapter($bootstrap, $workers),
    supported: static fn(): bool => extension_loaded('pcntl'),
    priority: EngineFactory::PRIORITY_EXTERNAL,   // 低于 parallel(100)，高于 sync(0)
);

// 之后即可像内置引擎一样使用，kode/process 的多进程后端自动进入探测候选
$runtime = new Runtime(null, 'process');
```

注册后 `EngineFactory::available()` / `detect()` / `WorkerPool(engine: 'process')` 都会把 `process`
视为一等公民；`unregister('process')` 可随时移除。

## 引擎无关同步原语（Concurrency）

v1.7.0 新增 `Kode\Parallel\Concurrency\*` 系列，**无需 ext-parallel / ZTS**，在 stock PHP CLI
（sync 引擎）下即可使用，对标 Swoole 6 的 `Thread\*` 原语，且能在非 ZTS、无 ext-parallel
的普通 PHP 上运行（Swoole 多线程必须 ZTS 构建）。

```php
use Kode\Parallel\Concurrency\Lock;
use Kode\Parallel\Concurrency\Atomic;
use Kode\Parallel\Concurrency\AtomicLong;
use Kode\Parallel\Concurrency\Barrier;
use Kode\Parallel\Concurrency\Channel;

// 互斥锁（命名锁跨进程共享，底层 flock）
$lock = Lock::named('order');
$lock->withLock(fn () => /* 临界区 */ null);

// 原子计数器（跨进程安全；flock 保证无丢失更新）
$c = new Atomic(0);
$c->inc();
$c->compareAndSwap(1, 10);

// 屏障：N 个参与者到齐后整体放行，并自动进入下一代
$barrier = Barrier::named(4, 'phase');
$barrier->wait();

// 单运行时消息通道（有界 / 无界）
$ch = Channel::bounded(8);
$ch->send($item);
$item = $ch->recv();
```

全局快捷函数：`sync_lock()` / `atomic()` / `atomic_long()` / `barrier()` / `concurrent_channel()`。

> 命名原语（传 `$name`）通过共享文件 + `flock` 在多进程间协作；匿名原语作用于同一运行时内部。
> 若在 **parallel 引擎的线程内部**需要同步原语，请使用既有的 `Kode\Parallel\Sync\*`（`Mutex` / `Semaphore` / `Cond` / `Barrier`，基于 `parallel\Sync`）。

## Future 组合子与 select

`FutureInterface` 自 v1.7.0 起支持链式组合（惰性解析、对所有引擎通用）：

```php
$future = runtime()->run(fn($a) => $a['x'] + 1, ['x' => 41]);

$chained = $future
    ->then(fn($v) => $v * 2)
    ->map(fn($v) => "result:$v")
    ->catch(fn($e) => "fallback");

echo $chained->get();   // "result:84"
```

`Futures::select()` 提供**非阻塞选择**，返回第一个已就绪的 Future（对标 `parallel\Events::poll` / Swoole `Channel::select`）：

```php
$ready = Futures::select([$f1, $f2, $f3], timeoutMs: 1000); // 超时无就绪返回 null
```

## 对比同类方案

| 维度 | **kode/parallel** | Swoole 6（Thread） | ext-parallel |
|------|-------------------|--------------------|--------------|
| 并行模型 | 真线程（ext-parallel）+ 可插拔外部进程后端 | 真线程（ZTS） | 真线程（ZTS） |
| 批量合并派发 | ✅ `mapBatch` / `mapBatchSettled` | ❌ 需自行分片 | ❌ |
| 统一 Future 契约 | ✅ | ❌ | 部分 |
| 组合器 / select | ✅ all/settle/any/race/select | ❌ | ⚠️ 仅 Events |
| 同步原语 | ✅ Lock/Atomic/Barrier/Channel（引擎无关） | ✅ Lock/Atomic/Map/Queue | ✅ Mutex/Semaphore/Cond/Barrier |
| 工作池 | ✅ 引擎无关 WorkerPool | ⚠️ Thread\Pool | ❌ |

## 诊断

```bash
# 检查运行环境与可用引擎
vendor/bin/kode-parallel doctor

# 输出引擎 / CPU / 并发建议
vendor/bin/kode-parallel info

# 基准测试（对比串行）
vendor/bin/kode-parallel bench
```

详见 [README](../README.md#执行引擎)。
