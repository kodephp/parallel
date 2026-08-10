# Kode/Parallel 性能基准报告

> 版本：`v1.15.0` ｜ kode 栈：`kode/context 3.1.0` / `kode/facade 3.2.0` / `kode/fibers 4.5.0`
> 本次重点：**单线程 vs 多线程真实提速审计、跨线程/进程数据同步文档、Futures::race() 轮询路径 bug 修复**，所有数据均为 ZTS 实测多跑取稳定值。
> 下方数据均为真实运行结果（非估算），场景化解读见 [USE_CASES.md](USE_CASES.md)。

## 测试环境（本仓库实测，可复现）

| 项目 | ZTS 主线配置 |
|------|--------------|
| PHP 版本 | 8.3.33（**ZTS: YES**） |
| ext-parallel | LOADED |
| ext-pcntl | LOADED |
| 默认引擎 | `parallel`（ext-parallel 真线程） |
| CPU | 11 逻辑核心（Apple Silicon） |
| 运行方式 | `php benchmarks/bench_compare.php` ｜ `bench_concurrency.php` |
| 测试日期 | 2026-08-10 |

> 说明：kode/parallel 自动探测引擎——装了 ext-parallel 的 ZTS PHP 走真线程，否则走同步回退；多进程后端（kode/process）
> 通过 `EngineFactory::register()` 接入。引擎无关原语（Channel/Lock/Atomic/Barrier/Semaphore）在多引擎下语义与表现一致。
> 线程/进程扇出数值随机器负载轻微波动，取多次运行代表值。

## 核心对比：单进程 vs 多线程 vs 多进程

同一份 CPU 任务、N=2000 个独立单元，三种执行模型直接可比（见 `benchmarks/bench_compare.php`）：

| 工作负载 | 单进程(1线程) | 多线程 parallel(最优) | 多进程 fork池(最优) | 多线程加速比 | 多进程加速比 |
|---------|-------------|--------------------|--------------------|------------|------------|
| trivial（仅 return） | **19.8M/s** | 432k/s（x2） | 1.6M/s（x1） | 0.02× | 0.08× |
| light（2k 次循环） | 51k/s | 162k/s（x8） | 194k/s（x8） | 3.2× | 3.8× |
| medium（20万次 sqrt/sin） | 104/s | 508/s（x8） | 641/s（x8） | 4.9× | 6.1× |

### 派发开销专项（每单元独立派发，空任务 submit+get）

| 模型 | 吞吐 | 说明 |
|------|------|------|
| 多线程 parallel（x8） | **≈ 233k ops/s** | 共享内存、零 fork/IPC 重建 |
| 多进程 每任务独立 fork | ≈ 3.4k ops/s | fork + 序列化 + socket 回传 |
| **差距** | **≈ 68×（可达百倍）** | 来自 fork/IPC 固有开销 |

> **关键结论（实测）**：
> 1. **任务派发**：多线程比「每任务独立 fork 的多进程」快约 **1~2 个数量级**（~23万 vs ~3.4k ops/s）。这正是把
>    多进程任务处理切换到多线程后，吞吐可提升 **十倍到百倍** 的来源。
> 2. **稳态并发（worker 池）**：轻/中负载下，多线程与多进程都随核心数**近似线性加速**（8 核 ~5–6×），二者量级相当
>    （多线程约为多进程的 0.8×）。多线程胜在共享内存、无数据拷贝、编程模型更简单。
> 3. **琐碎任务**：单进程顺序执行反而最快（零调度开销）——并行仅在「处理量足以摊销调度成本」时才有收益。
> 4. **最优配置**：多线程线程数 ≈ CPU 逻辑核心数（`Runtime(null,'parallel', cores)`）；超过核心数不再提速。

## v1.13.0 新增：批量合并 `mapBatch`（高频短任务）

`map()` 逐条派发时每条都要序列化闭包与参数；`mapBatch()` 每批只序列化一次。
`benchmarks/bench_batch.php`（11 线程，批 128）：

| 负载 | 单进程串行 | `map` 逐条 | `mapBatch` 批量 | 批量 vs 逐条 |
|------|-----------|-----------|----------------|-------------|
| trivial（`return $i`，N=40,000） | 28,093,991 ops/s | 250,370 ops/s | 5,522,224 ops/s | **22.1×** |
| light（2k 次整数循环，N=20,000） | 51,404 ops/s | 145,470 ops/s | 293,588 ops/s | **2.0×** |
| medium（20 万次 sqrt/sin，N=2,000） | 105 ops/s | 626 ops/s | 533 ops/s | 0.9× |

> CPU 密集（medium）由并发度决定，批量无增益——如实呈现，不做修饰。

## v1.14.0 更新：map 自动批量 + Futures 轮询退避

`v1.14.0` 起 `map()` / `mapSettled()` 在元素数 > 并发度 × 4 时**自动走批量合并**（无需改 API）；
同时把 `Futures` 组合器（all/settle/any/race/select）的固定 500µs 轮询改为指数退避（10µs→500µs）。
两者叠加，逐条 `map` 的 trivial 吞吐从 v1.13.0 的 25 万 ops/s 提升到约 **1,030 万 ops/s**（综合 ≈41×）。

`benchmarks/bench_batch.php`（11 线程；「强制逐条」= `mapBatch` 批大小 1）：

| 负载 | 串行 | 强制逐条 | `map` 自动批量 | `mapBatch` 显式 x128 | 自动 vs 强制逐条 |
|------|------|----------|----------------|----------------------|------------------|
| trivial（N=40,000） | 28.2M ops/s | 6.41M | **10.32M** | 8.03M | **1.6×** |
| light（N=20,000） | 51.4k ops/s | 337k | 340k | 338k | ≈1.0× |
| medium（N=2,000） | 106 ops/s | 618 | 584 | 494 | ≈1.0× |

> 退避让「强制逐条」路径本身已从 25 万 → 6.41M ops/s（≈26×）；自动批量再叠加 1.6×。CPU 密集任务由并发度主导。

## v1.13.0 新增：群发消息三档数据（短 / 中 / 大）

`benchmarks/bench_message.php`（10 线程 / 4 进程 / 批大小 auto），单条 = 模板渲染 + 校验 + 摘要：

| 数据规模 | 条数 | 串行 | 线程 `map`（自动批量） | 线程 `mapBatch` | 4 进程 × 10 线程 |
|---------|------|------|----------------------|----------------|-----------------|
| 短 120B（推送通知） | 50,000 | 1,070,000 msg/s | ≈1.0× * | ≈1.0× * | 1,550,000（1.5×） |
| 中 4KB（站内信） | 20,000 | 118,000 msg/s | 461,000（3.9×） | 473,000（4.0×） | 500,000（4.2×） |
| 大 64KB（富文本邮件） | 2,000 | 8,070 msg/s | 49,800（6.2×） | 51,600（6.4×） | 38,000（4.7×） |

> \* **短数据（单条 <1μs 的模板替换 + md5）并行开销与任务本身同量级**：倍率受调度抖动影响在
> **0.7×–1.4×** 间波动，多次运行 `map` / `mapBatch` 会互相颠倒——这不是稳定负优化，而是派发 + 序列化成本吃掉收益。
> 可复现的批量合并收益见上方 `bench_batch`（强制逐条 6.4M → 自动批量 10.3M，**1.6×**）。结论：高频短任务直接
> `map()` 即可（v1.14.0 起自动批量），不会比 `mapBatch` 差。

## v1.15.0 新增：单线程 vs 多线程真实提速（ZTS 实测，11 核）

`benchmarks/bench_tune.php`，串行基线取 3 轮最快。先看**单线程到底有没有提速**——结论很反直觉：

| 负载 | 串行基线 | **单线程（threads=1）** | 单进程多线程最优 | 进程 × 线程最优 |
|------|---------|------------------------|-----------------|----------------|
| 短 120B × 50,000 | 1,526,345 msg/s | ≈ 1.0×（与串行持平） | 8 线程 + 批 512 → 1,839,865（1.2×） | 8 进程 × 2 线程 → 2,126,045（1.4×） |
| 中 4KB × 20,000 | 129,232 msg/s | 103,386（**0.8×，比串行慢**） | **11 线程 + 批 128 → 672,498（5.4×）** | 8 进程 × 2 线程 → 570,386（4.6×） |
| 大 64KB × 2,000 | 8,205 msg/s | 7,385（**0.9×，略慢**） | **22 线程 + 批 auto → 54,736（6.8×）** | 4 进程 × 4 线程 → 45,784（5.6×） |

**单线程（threads=1）不会提速，反而比串行慢 10%~20%**：每个任务仍走「主线程序列化闭包+参数 → 派发到唯一 worker 线程 → 返回」的 FIFO 队列，多了一次派发/序列化往返，却没有并发。它只适合**需要线程隔离语义、又不想引入并发不确定性**的场景（见 USE_CASES「什么时候用单线程」）。

**真实提速来自多线程**，且随线程数接近核数（11）趋于饱和：

| 线程数 | 中 4KB（相对串行） | 大 64KB（相对串行） |
|-------|-------------------|-------------------|
| 1 | 0.8× | 0.9× |
| 2 | 1.6× | 1.8× |
| 4 | 2.9× | 3.4× |
| 8 | 4.2× | 5.5× |
| **11（=核数）** | **4.9×（批128 5.4×）** | **6.6×** |
| 22（超配） | 5.1× | 6.7× |

**结论**：
- 线程数 ≈ 核数（11）即达最优；继续加到 22 仅边际增益（上下文切换 + 内存带宽瓶颈）。
- 中/大数据下**单进程多线程优于任何多进程组合**，因为省掉了 fork 与 IPC；多线程相对单线程本身约 **6×~7×**。
- 短数据下并行收益本就有限（串行已 150 万条/秒），开线程只为均摊、不为提速。
- 进程 × 线程也应 ≈ 核数：若已用 `kode/process` 起 4 个进程，每进程再开 2~3 线程即可，不要每进程再开 10 线程。

## v1.13.0 新增：多用户 HTTP 扇出

`benchmarks/bench_curl.php`（自带本机 Python `ThreadingHTTPServer`，每请求 20ms，200 请求）：

| 方式 | 耗时 | 吞吐 | 相对顺序理论下限（200×20ms=4000ms） |
|------|------|------|---------|
| 顺序 curl | 5434 ms | 37 req/s | 1.4× |
| `CurlMulti` 不限并发（洪泛 200 连接） | 10019 ms | 20 req/s | **0.4×（连接风暴，比顺序还慢）** |
| `CurlMulti` 并发 8 | 700 ms | 286 req/s | 5.7× |
| `CurlMulti` 并发 16 | 395 ms | 506 req/s | 10.1× |
| `CurlMulti` 并发 32 | 270 ms | 741 req/s | 14.8× |
| `CurlMulti` 并发 64 | 2993 ms | 67 req/s | 1.3×（对端过载） |
| parallel 线程 × 8 + curl | 776 ms | 258 req/s | 5.2× |

> 最优并发档在 **16~32** 之间波动（多次运行 x16≈10×、x32≈15× 会互相颠倒），**不要死记某一个数字**；
> 上线前用 `bench_curl.php` 在本机扫一遍取最优点（经验值 8~32）。

## 引擎无关同步原语（与引擎无关，多引擎下一致）

| 测试项 | 吞吐（代表值） |
|--------|----------------|
| `Concurrency\Channel`（send+recv ×200k，进程内） | ≈ 14M ops/s |
| `Concurrency\Lock`（withLock 自增 ×20k） | ≈ 110k ops/s |
| `Concurrency\Atomic`（进程内 inc ×5M，纯内存快路径） | ≈ 13.6M ops/s |
| `Concurrency\Atomic`（跨进程 inc ×30k，6 进程争用） | ≈ 16.7k ops/s，最终值 30,000 ✅ 零丢失 |
| `Concurrency\Barrier`（跨进程 4 方 ×50 回合） | ≈ 431 回合/s |
| `Concurrency\Semaphore`（进程内 acquire+release ×5M） | ≈ 7.1M ops/s |

## v1.9.0 关键优化：未命名 `Atomic` 进程内纯内存快路径

未命名（不传 `$name`）的 `Atomic` 在语义上**不可能**跨进程共享（数据文件随机且不可被发现），因此 v1.9.0
将其改为**纯内存实现**：零文件 I/O、零加锁，吞吐从「跨进程 ~19.7k ops/s」跃升至 **~16.6M ops/s（≈840×）**。

命名（跨进程）`Atomic` 维持「独立锁文件 + 数据文件」的跨进程安全语义不变，6 进程 × 各 5000 次高频争用压测仍
**零丢失更新**。这样既保住了正确性，又让最常见的进程内计数场景获得数量级加速。

```php
use Kode\Parallel\Concurrency\Atomic;

$counter = new Atomic(0);          // 进程内快路径（纯内存）
for ($i = 0; $i < 1_000_000; $i++) {
    $counter->inc();               // ≈ 16.6M ops/s
}

$shared = Atomic::named(0, 'g');   // 跨进程共享（文件锁兜底，≈ 19.7k ops/s）
```

## v1.10.0 新增：`Concurrency\Semaphore` 计数信号量

对标 ext-parallel 的 `parallel\Sync\Semaphore` 与 pthreads 计数信号量。未命名走纯内存快路径（~7.7M ops/s），
命名走「独立锁文件 + 数据文件」跨进程共享，已通过 6 进程 × 8 轮「持有期间其余进程见 0 可用、释放后额度恢复」
的并发守恒压测（零丢失）。

```php
use Kode\Parallel\Concurrency\Semaphore;

$db = Semaphore::named(8, 'db_pool');   // 跨进程 8 个连接许可
$db->withPermits(1, fn () => query());  // 至多 8 个并发
```

非阻塞 API：`Lock::tryLock()` / `Lock::withLockTimeout(ms, cb)`、`Atomic::tryAdd()` / `Atomic::trySub()`，
便于在弱竞争下自旋/退避而非阻塞等待（详见 `docs/PERFORMANCE.md`）。

## 同类对比：四角基线（kode / Swoole / ext-parallel / 裸 pcntl）

> 脚本同口径（均为「派发 N 个任务并回收结果」），可并排比较：
> `bench_compare.php`（单进程/多线程/多进程对比）｜`bench_concurrency.php`（kode 引擎无关原语）｜
> `bench_swoole.php`（Swoole 6.2 线程）｜`bench_ext_parallel.php`（ext-parallel 真线程）｜`bench_pcntl.php`（裸 pcntl 地板）。

| 测试项 | kode（ZTS 真线程） | 每任务 fork 多进程 | 裸 pcntl | ext-parallel | Swoole 6 |
|--------|-------------------|--------------------|----------|--------------|----------|
| 运行前提 | ZTS + ext-parallel | 普通 PHP | 普通 PHP | **必须 ZTS** | **必须 ZTS** |
| 任务派发 submit+get | ≈ 23万 ops/s | ≈ 3.4k ops/s | ≈ 2.6k ops/s | 更高（真线程） | 更高（真线程） |
| 进程内原子 inc | ≈ 13–17M ops/s | — | — | 更高（共享内存） | 更高（共享内存） |
| 跨进程共享状态 | ✅ 原生（原语） | ✅（fork 后各自独立） | ❌ | ❌（线程内） | ❌（线程内） |

**关键结论**：
- **kode 真线程引擎（ZTS）远超每任务 fork 的多进程**：任务派发快约 1~2 个数量级，适合高频率细粒度并行。
- **与 Swoole 6 / ext-parallel 的差异在 ZTS 门槛**：后两者同进程内吞吐更高（共享内存），但强制 ZTS 构建；
  kode 以「文件锁兜底」换得**任意普通 PHP 即可运行 + 跨进程共享**，适用面更广。
- 需要**真正的多进程编排**（隔离/跨机/集群）时，用 `EngineFactory::register()` 接入 `kode/process`，
  与本库统一调度无缝协作（详见 `docs/PROCESS_VS_THREAD.md`）。

## 调优点（历次验证确认并修复）

1. **跨进程 `Atomic` 曾存在高并发丢失更新**：旧实现把 `flock` 与数据读写放在同一文件句柄上，在 macOS
   高并发下丢失排他性。已重构为「独立锁文件（`flock`）+ 数据文件（`file_get_contents`/`put_contents`）」，
   并通过 6 进程 × 5000 次压测验证零丢失。
2. **`flock` 在同一 fd 反复 lock/unlock 不可靠（macOS）**：复用单一 fd 跨多次加锁循环会产生丢失更新；
   改为**每次加锁/解锁都打开并关闭一把新 fd**，稳定性恢复。
3. **未命名 `Atomic`/`Semaphore` 走文件锁是浪费（v1.9.0 修复）**：未命名原语本不跨进程，改为纯内存快路径，
   `Atomic` 提升约 840×、`Semaphore` 达 ~7.7M ops/s。
4. **性能取舍**：为正确性，`Lock`/跨进程 `Atomic`/`Semaphore` 每次操作都会开/关锁文件 fd（约 58k~20k ops/s）。
   这是可移植跨进程原语的固有成本；**同进程内的高频计数/许可请用未命名 `Atomic`/`Semaphore` 或原生变量**。
5. **v1.12.0 移除自研多进程引擎、聚焦多线程**：`Future` 的每任务 ID 改为惰性生成（仅在 `getId()` 时计算），
   去掉派发热路径上的 CSPRNG 调用；`ParallelEngine` 维护可增长线程池并以轮询派发，让并发上限在真线程下真实生效。
6. **v1.13.0 三项调优（均为实测收益）**：
   - `WorkerPool::waitForSlot()` 固定 500µs 轮询 → 指数退避（10µs 起）：1 线程逐条派发 **1,566 → 58,348 task/s（37×）**，
     2 线程 **3,100 → 130,333（42×）**，8 线程 1.17×；
   - `CurlMulti` 分波执行 → 滑动窗口（完成一个补一个）：并发 16 档 **2474 ms → 451 ms（5.5×）**；
   - `mapBatch` 自动批大小：64KB 群发 **38,525 → 45,824 msg/s（1.19×）**，同时消除固定大批次导致的 OOM。
7. **v1.14.0 优化（均为实测收益）**：
   - `Futures` 组合器（all/settle/any/race/select）固定 500µs 轮询 → 指数退避（10µs→500µs）：逐条 `map` 4 万 trivial
     **250,370 → 6,410,000 ops/s（≈26×）**；
   - `map` / `mapSettled` 在元素 > 并发度×4 时自动批量合并：trivial **6.41M → 10.32M ops/s（1.6×）**，相对 v1.13.0 逐条综合 **≈41×**；
   - 基准可信度：`bench_curl.php` 改为自带本地并发服务，`?delay=` 真实生效，顺序 curl 从失真的 46ms 修正为约 5 秒（4930~5434ms，随环境）；
   - 健壮性：批量回收 `mapBatch` 的 pending、`CurlMulti` 关闭泄漏的句柄并加调度死锁守卫（避免 100% CPU 空转）。

## 复现

```bash
php benchmarks/bench_compare.php         # 单进程 / 多线程 / 多进程 三向对比（本机最优配置自动扫描）
php benchmarks/bench_batch.php           # 批量合并 vs 逐条派发（自对比）
php benchmarks/bench_message.php         # 群发消息：短/中/大三档数据 + 多进程×多线程
PROFILE=medium php benchmarks/bench_tune.php   # 配置寻优：线程×批大小、进程×线程 网格
php benchmarks/bench_concurrency.php     # kode 引擎无关原语
php benchmarks/bench_pcntl.php           # 裸 pcntl_fork 地板（普通 PHP 即可）
php benchmarks/bench_swoole.php          # Swoole 6.2 线程（需 ZTS + --enable-swoole-thread）
php benchmarks/bench_ext_parallel.php    # ext-parallel 真线程（需 ZTS + ext-parallel）

# 多用户 HTTP 扇出（bench_curl.php 现在自带本地并发服务，无需手动起）
DELAY=20 N=200 THREADS=8 php benchmarks/bench_curl.php
# 如需对外部/已起服务压测：BASE=http://127.0.0.1:8899 php benchmarks/bench_curl.php
```

多个脚本输出格式一致（总结均按 ops/s），可跨环境并排比较。
