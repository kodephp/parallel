# 执行引擎（Engines）

`kode/parallel` v1.6.0 引入统一的**执行引擎抽象层**，让同一套 API（`Runtime` / `Future` / `WorkerPool` / 全局函数）在三种不同的并行后端上无缝运行。

## 为什么需要引擎

- **`ext-parallel` 不是必装项**。没有它，库也能通过 `process` 引擎（`pcntl_fork` + socket 回传）或 `sync` 引擎（同步回退）正常工作。
- **代码与后端解耦**。业务代码只依赖 `FutureInterface` 契约，不关心底层是真线程还是子进程。
- **可移植性**。同一份代码在共享主机、容器、CI、无 ZTS 的 PHP 环境都能跑。

## 三种引擎

| 引擎 | 后端 | 并发模型 | 需要扩展 | 适用场景 |
|------|------|----------|----------|----------|
| `parallel` | `ext-parallel` 真线程 | 线程，COW 内存 | `ext-parallel` | 性能最佳，真并行 CPU 密集 |
| `process` | `pcntl_fork` + socket pair | 子进程，独立内存 | `ext-pcntl` / `ext-posix` / `ext-sockets` | 无 ext-parallel 时的真并行 |
| `sync` | 当前进程内直接调用 | 同步、串行 | 无 | 兼容/调试/单元测试 |

## 引擎优先级与探测

`EngineFactory::detect()` 按 `[parallel, process, sync]` 顺序探测可用引擎：

```php
use Kode\Parallel\Engine\EngineFactory;

// 当前环境下自动选择的最高优先级引擎
$engine = EngineFactory::detect();      // e.g. "process"
var_dump(EngineFactory::available());   // ["process", "sync"]

// 强制指定（等价于环境变量 KODE_PARALLEL_ENGINE）
EngineFactory::setDefault('sync');
```

- 环境变量 `KODE_PARALLEL_ENGINE=parallel|process|sync` 可强制覆盖自动探测。
- 单元测试中用 `skipWithoutProcessEngine()` 适配无 `ext-parallel` 的环境。

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

内置实现：`Future`（parallel 线程）、`ProcessFuture`（进程）、`ValueFuture`（已就绪/已失败）。

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

$pool = new WorkerPool(concurrency: 8, engine: 'process');

$pool->submit(fn ($x) => $x * $x, [2]);
$pool->submit(fn ($x) => $x * $x, [3]);

// 并发映射，保持顺序
$squares = $pool->map(fn ($x) => $x * $x, [1, 2, 3, 4]);

// 容错映射，不抛
$settled = $pool->mapSettled(fn ($x) => 10 / $x, [1, 0, 2]);

print_r($pool->stats());  // 提交数 / 完成数 / 失败数 / 并发上限
$pool->close();
```

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
