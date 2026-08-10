# Kode/Parallel 调优指南

> 目标：在「引擎无关、可移植、跨进程安全」的前提下，把吞吐与延迟推到合理上限。
> 配合 `docs/BENCHMARK.md`（实测数据）与 `docs/SWOOLE_COMPARISON.md`（同类对比）阅读。

---

## 1. 先选对引擎（决定性能天花板）

`EngineFactory` 探测优先级：**`parallel`（真线程，需 ZTS + ext-parallel） ＞ `sync`（同步回退） ＞〔通过 `register()` 接入的外部引擎，如 kode/process〕**。

| 你的环境 | 默认引擎 | 说明 |
|----------|----------|------|
| 装了 `ext-parallel`（ZTS PHP） | `parallel` | **本库主线**，真线程，与 Swoole 线程同档 |
| 普通 PHP（无 ext-parallel） | `sync` | 顺序执行回退，API 一致 |
| 需要多进程编排 | `kode/process`（经 `register()` 接入） | 隔离/跨机/集群，与本库统一调度协作 |

**调优点**：生产环境若追求极致同进程吞吐，优先部署 **ZTS + ext-parallel**；否则 `sync` 回退仍保证 API 一致，
跨进程共享状态用 `Lock`/`Atomic`/`Barrier`。需要真正的多进程时再接入 `kode/process`。可用 `EngineFactory::detect()` 确认当前引擎。

---

## 2. 并发度：不要超过 CPU 核数太多

`WorkerPool` / `Runtime` 的并发上限建议设为 **CPU 核数**（或核数 + 1~2）：

```php
use Kode\Parallel\Pool\WorkerPool;

$pool = new WorkerPool(concurrency: (int) shell_exec('nproc') ?: 4);
```

- 计算密集型：并发 = 核数即可，过多反而因上下文切换掉速；
- IO 密集型（网络/磁盘）：可适当高于核数（如 2×），让等待时间被其他任务填充；
- `parallel` 引擎每个 worker 是一条真线程（共享进程内存），并发越高内存占用越可控，但线程数超过核数不再提速。

---

## 3. 计数/互斥：按「是否跨进程」选原语

这是 v1.9.0 最重要的调优点。

| 场景 | 推荐原语 | 吞吐量级（实测） |
|------|----------|------------------|
| 进程内高频计数 | `new Atomic($n)`（**未命名**，纯内存） | ≈ 16.6M ops/s |
| 跨进程共享计数 | `Atomic::named($n, 'name')` | ≈ 19.7k ops/s |
| 跨进程互斥临界区 | `Lock::named('name')->withLock(fn)` | ≈ 109k ops/s |
| 并发度限制（连接池/限流） | `Semaphore::named($n, 'name')` | 进程内 ≈ 7.7M ops/s |
| 进程内消息传递 | `new Channel(0)`（无界） | ≈ 16.7M ops/s |
| 跨进程多进程会合 | `Barrier::named($parties, 'name')` | ≈ 431 回合/s |

**原则**：
- **只在需要跨进程共享时才付文件锁的代价**。进程内计数器、累加器一律用**未命名 `Atomic`**（内存快路径），
  切勿为了「统一风格」把进程内计数也命名化——会慢约 840×。
- **限流/连接池用 `Semaphore` 而非反复 `Lock`**：`Semaphore(8)` 允许 8 个并发，比「单锁串行」吞吐高得多；
  未命名 `Semaphore` 进程内约 7.7M ops/s（acquire+release），命名则跨进程共享同一许可额度。
- 高频临界区请缩小 `withLock` 闭包体，只把绝对必要的几行放进去；锁内不要做 IO / 网络 / 大循环。

---

## 3b. 非阻塞锁 / 原子：弱竞争下避免阻塞

`Lock` 与 `Atomic` 都提供非阻塞入口，适合「拿不到锁就先干点别的 / 退避重试」的调优模式：

```php
use Kode\Parallel\Concurrency\{Lock, Atomic};

$lock = Lock::named('hot');
// 非阻塞：拿不到立即返回 false，不阻塞等待
if ($lock->tryLock()) {
    try { /* 临界区 */ } finally { $lock->unlock(); }
} else {
    // 退避或做别的工作
}

// 带超时：最多等 50ms，否则抛 ParallelException
$lock->withLockTimeout(50, fn () => critical());

// 原子非阻塞自增：弱竞争下零等待
$at = new Atomic(0);
if ($at->tryAdd(1)) { /* 成功 */ } else { /* 退避重试 */ }
```

**适用**：自旋/退避调度、超时保护关键路径、避免长尾任务被慢锁拖死。重争用下仍建议阻塞 `withLock`，
因为非阻塞失败会空转消耗 CPU。

---

## 4. 减少序列化开销（parallel / sync 引擎）

`parallel` 引擎通过 ext-parallel 在**线程间**传递**参数与返回值**，任务闭包与参数会被序列化（线程内共享同一进程内存，
但 ext-parallel 的调度仍基于序列化通道）：

- **传递大数组/对象代价高**：把入参压到最小（传 id 而非整条记录，任务内再查）；
- **返回值同理**：返回摘要（计数、状态、少量字段）而非完整对象树；
- 需要共享大量只读数据 → 用 `kode/context` 的进程级上下文，或在任务外准备好，避免每个任务重复传递；
- `sync` 引擎无序列化（同进程直接调用），调试期用它最快定位逻辑问题；
- 多线程的派发开销（≈233k ops/s 空任务 submit+get）远低于「每任务独立 fork 的多进程」（≈3.4k ops/s），
  高频细粒度并行优先选 `parallel` 引擎。详见 `docs/PROCESS_VS_THREAD.md`。

---

## 5. Channel vs 返回值：选对通信方式

- **一对多结果收集**：`Futures::all([...])` 或 `Runtime::run()->get()` 聚合，最省心；
- **生产者/消费者流**：用 `Concurrency\Channel` 在**同一进程内**做流式传递（≈14M ops/s）；
- **跨进程流式**：`Channel` 是进程内结构，跨进程请用 `Atomic`/`Barrier` 协调 + 返回值汇总（或接入 kode/process）。

---

## 6. 任务粒度：粗一点更快

`parallel` 引擎「派发 + 取回」单次固定成本约 4~5 µs（见 BENCHMARK：空任务 submit+get ≈ 233k ops/s）；
「每任务独立 fork 的多进程」则约 0.3~0.4 ms（≈3.4k ops/s），高一个数量级。
因此：

- ❌ 不要把「循环里每个元素」当成一个任务 → 调度开销吃掉收益；
- ✅ 把一批（如 1k~10k 个元素）作为一个任务整体下发，任务内本地循环处理；
- ✅ `WorkerPool::map($bigArray, fn)` 内部已做分批，直接用它最稳。

---

## 7. 用 Futures 组合子避免等待空转

- `Futures::all([...])`：等全部完成，适合「扇出后统一汇总」；
- `Futures::select([...])` / `race`：谁先完成先处理，适合「多个异构数据源取最快」；
- `then/map/catch` 链式：把「A 完成 → 用其结果跑 B」写成依赖链，避免手动 `get()` 阻塞。

---

## 8. 调试与回归

- 本地先跑 `sync` 引擎验证逻辑（无 fork，栈清晰、可单步）；
- 用 `benchmarks/bench_concurrency.php` 做**回归基线**：升级 kode 栈或改原语后重跑，对比 ops/s 是否退化；
- `benchmarks/bench_swoole.php` 在 ZTS + Swoole 线程构建上跑，得到同类基准做横向对标。

---

## 9. 一句话调优清单

1. 部署 ZTS + ext-parallel 可吃满真线程性能；
2. 并发度 ≈ CPU 核数（IO 型可更高）；
3. 进程内计数用**未命名 `Atomic`**（≈16.6M ops/s）；跨进程才用命名 `Atomic`/`Lock`/`Barrier`；
4. 任务参数/返回值尽量小，避免序列化；
5. 任务粒度粗一点（批量下发）；
6. 用 `Futures::all/select` 替代手动轮询 `get()`；
7. 跨进程高频计数接受「文件锁 ≈ 19.7k ops/s」的合理成本；若争用极重，改用未命名 `Atomic` 进程内快路径或分片计数。
