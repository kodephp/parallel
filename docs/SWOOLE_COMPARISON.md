# Kode/Parallel vs Swoole 6（最新 6.2.x）对比分析

> 对标版本：Swoole **6.2.2**（2026-07-08，6.x 系列最新稳定版）。
> 测试环境版本：Swoole 6.0 起引入原生线程（`Swoole\Thread` 系列），6.2.x 进一步加入 io_uring HTTP、协程 FTP/SSH、PHP 8.5 支持等。
> kode 侧版本：`v1.18.0` ｜ kode 栈：`kode/context 3.1.0` / `kode/facade 3.2.0` / `kode/fibers 4.5.0`（均为当前各包最新版）。
> 同类对比基准（四角基线，同口径）：`bench_concurrency.php`（kode）｜`bench_swoole.php`（Swoole 6.2 线程，需 ZTS）｜`bench_ext_parallel.php`（ext-parallel 真线程，需 ZTS）｜`bench_pcntl.php`（裸 pcntl 地板，普通 PHP）。

## 核心结论（先讲重点）

| 维度 | kode/parallel（引擎无关原语） | Swoole 6 多线程 |
|------|------------------------------|-----------------|
| **运行前提** | stock PHP CLI（**非 ZTS** 即可） | **必须 ZTS 构建 + `--enable-swoole-thread`** 编译；且需禁用 pthreads |
| **跨进程共享** | ✅ `Lock`/`Atomic`/`Barrier` 基于文件锁，**天然跨进程** | ❌ `Thread\*` 是**同进程内线程共享内存** |
| **真线程可用** | ✅ 装了 `ext-parallel`（ZTS）时自动用真线程 | ✅ 原生线程 |
| **性能（同进程高频）** | 中（文件 I/O 兜底，保证可移植） | 高（线程共享内存，in-process） |
| **可移植性** | ✅ 任意 PHP 8.3+ CLI | ⚠️ 需重新编译 PHP + Swoole |

**一句话**：Swoole 6 的原生线程性能更强，但**强制要求 ZTS 构建**，把部署门槛大幅提高；kode/parallel 的 `Concurrency\*` 原语在**普通非 ZTS PHP** 上即可运行，并且能**跨进程**共享状态——这是 Swoole 线程（进程内共享内存）做不到的。两者并非替代关系，而是按部署约束选型。

---

## ⚠️ Swoole 6 多线程的硬性前提（极易踩坑）

Swoole 6 的多线程**不是「协程 + 线程」二选一**，而是必须满足以下全部条件，`Swoole\Thread` 才可用，否则运行时直接 `Class 'Swoole\Thread' not found`：

1. **PHP 必须是 ZTS 模式**：`php -i | grep "Thread Safety"` 必须为 `enabled`（编译期决定，运行期无法开启）；
2. **编译 Swoole 时开启**：`./configure --enable-swoole-thread`（PECL 安装默认不带线程支持，必须源码编译）；
3. **禁用 pthreads 扩展**（与 Swoole 线程冲突）。

而 kode/parallel 在以上条件**全部不满足**的普通 PHP 上也能跑（自动降级为 `process` 多进程或 `sync` 同步），这就是「引擎无关」的价值。

---

## 架构与能力对比

### 同步原语对照表

| 能力 | kode/parallel | Swoole 6 `Thread\*` | 说明 |
|------|---------------|---------------------|------|
| 互斥锁 | `Concurrency\Lock` | `Thread\Lock` | 两者都有；kode 无需 ZTS |
| 原子计数 | `Concurrency\Atomic` / `AtomicLong` | `Thread\Atomic` / `Atomic\Long` | 同语义；kode 走文件锁，跨进程安全 |
| 屏障 | `Concurrency\Barrier` | `Thread\Barrier` | 同语义；kode 跨进程可用 |
| 队列/通道 | `Concurrency\Channel`（进程内） | `Thread\Queue` / `Thread\Channel` | Swoole 走线程共享内存，吞吐更高 |
| 共享容器 | — | `Thread\Map` / `Thread\ArrayList` | Swoole 独有，进程内线程共享；kode 用返回值/参数传递跨进程数据 |
| 非阻塞选择 | `Futures::select()` | `Channel::select` | 语义近似 |

> 注：旧版文档里的 `ThreadMap` / `ThreadQueue` 是**进程内数据**的旧实现（名称有「线程」字样但不跨进程）。
> v1.7.0 起请改用引擎无关的 `Concurrency\*` 系列；需要跨进程共享计数/互斥/屏障时它们是正确的选择。

### 多引擎 vs 单一线程模型

kode/parallel 同一套 API 在不同环境下自动选择引擎：

```
探测优先级：parallel（真线程, 需 ZTS + ext-parallel）  >  process（多进程, 需 pcntl）  >  sync（同步回退）
```

- 生产环境装了 `ext-parallel` → 真线程，与 Swoole 线程同档性能；
- 只有 `pcntl` 的普通 Linux/macOS → 自动多进程，`Lock`/`Atomic`/`Barrier` 仍跨进程可用；
- Windows / 受限环境 → 同步回退，API 行为一致。

Swoole 6 则始终是「同一进程内的多线程 + 协程」，没有这种降级层次。

---

## 性能对比（实测 vs 官方能力）

### kode/parallel 实测（本仓库 `benchmarks/bench_concurrency.php` / `bench_compare.php`，ZTS + ext-parallel 真线程主线，v1.17.0）

| 测试项 | 数值 | 说明 |
|--------|------|------|
| 多线程任务扇出（submit+get ×1000，空任务） | ~4.3 ms（**≈233k ops/s**） | 真线程，共享内存、零 fork/IPC 重建 |
| 每任务独立 fork 多进程（对照地板） | ≈ 3.4k ops/s | fork 模型固有开销 |
| 并行映射（parallel_map ×100） | ≈ 50 ms | CPU 友好型任务 |
| `Concurrency\Channel`（send+recv ×200k） | ~14 ms（≈14M ops/s） | 进程内队列，极快 |
| `Concurrency\Lock`（withLock ×20k） | ~182 ms（≈110k ops/s） | 每次加锁开/关独立 fd，换取 macOS 下的可靠排他性 |
| `Concurrency\Atomic`（**进程内** inc ×5M） | ~367 ms（**≈13.6M ops/s**） | v1.9.0 纯内存快路径，未命名计数器 |
| `Concurrency\Atomic`（跨进程 inc ×30k） | ~1.8 s（≈16.7k ops/s） | 文件锁兜底，**正确性优先**，零丢失 |
| `Concurrency\Barrier`（跨进程 4 方 ×50 回合） | ~116 ms | 代际屏障，跨进程同步 |

> 关键：跨进程 `Atomic` 在 6 进程 × 各 5000 次争用压测下**零丢失更新**（详见修复记录）。
> v1.9.0 新增未命名 `Atomic` 纯内存快路径（≈13.6M ops/s），把进程内计数场景推到与 Channel 同量级。
> 多线程任务派发比「每任务独立 fork 的多进程」快约 **1~2 个数量级**（详见 `docs/PROCESS_VS_THREAD.md`）。
> 整体吞吐低于 Swoole 线程共享内存，但**不依赖 ZTS、可跨进程/跨机器**，且多进程编排可经 `register()` 接入 kode/process。

### Swoole 6 线程原语（官方定位）

- `Thread\Atomic` / `Thread\Map` / `Thread\Queue` 基于**线程共享内存**，同进程内吞吐显著高于文件锁实现；
- 多线程模式 `SWOOLE_THREAD` 支持 worker 线程重启、Manager 线程定时器等；
- 代价：必须 ZTS + `--enable-swoole-thread`，且多线程下需自行处理共享内存同步。

### 选型建议

| 场景 | 推荐 | 原因 |
|------|------|------|
| 部署环境只是普通 PHP（非 ZTS） | **kode/parallel** | Swoole 线程根本不可用 |
| 需要跨进程 / 跨机器共享状态 | **kode/parallel** | Swoole 线程是进程内共享内存 |
| 已部署 ZTS + Swoole 6 且要极致同进程吞吐 | **Swoole 6 线程** | 共享内存更省、更快 |
| 追求「一套代码多环境可跑」 | **kode/parallel** | 引擎自动降级 |

---

## 小结

Swoole 6 把 PHP 多线程往前推了一大步，但**门槛在 ZTS**。kode/parallel 的差异化定位是：
**在任意 PHP 8.3+ CLI 上即可获得可用的并发原语与多引擎并行能力，并把「跨进程共享状态」做成可移植的基础能力**。
如果你的环境已经是 ZTS + Swoole 6，两者结合（用 Swoole 做同进程高性能、用 kode/parallel 做跨进程/跨机器协调）也是合理架构。
