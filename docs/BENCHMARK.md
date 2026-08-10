# Kode/Parallel 性能基准报告

> 版本：`v1.11.0` ｜ kode 栈：`kode/context 3.1.0` / `kode/facade 3.2.0` / `kode/fibers 4.5.0`
> 本次重点：**已移除无测试/设计有误的 `Cluster`/`Network` 分布式子系统**，并以 **ZTS + ext-parallel 真线程引擎** 作为压测主线；
> 引擎无关同步原语在 ZTS 与普通 PHP 下表现一致，下方数据均为真实运行结果（非估算）。

## 测试环境（本仓库实测，可复现）

| 项目 | ZTS 主线配置 | 普通 PHP 回退配置 |
|------|--------------|-------------------|
| PHP 版本 | 8.3.33（**ZTS: YES**） | 8.3.33（**ZTS: NO**） |
| ext-parallel | LOADED | 未加载 |
| ext-pcntl | LOADED | LOADED |
| 默认引擎 | `parallel`（ext-parallel 真线程） | `process`（pcntl_fork 多进程） |
| 操作系统 | macOS（Apple Silicon） | macOS（Apple Silicon） |
| 运行方式 | `php benchmarks/bench_concurrency.php` | 同左 |
| 测试日期 | 2026-08-10 | 2026-08-10 |

> 说明：kode/parallel 自动探测引擎——装了 ext-parallel 的 ZTS PHP 走真线程，否则走 pcntl 多进程或同步回退。
> 引擎无关原语（Channel/Lock/Atomic/Barrier/Semaphore）在多引擎下语义与表现一致。`fork` 类扇出数值随机器负载轻微波动，取多次运行代表值。

## 实测结果（ZTS + ext-parallel 真线程，v1.11.0）

| 测试项 | 吞吐（代表值） |
|--------|----------------|
| **parallel 引擎任务扇出（submit+get ×200，空任务）** | **≈ 18万–30万 ops/s** |
| 并行映射（parallel_map ×100，轻量计算） | ≈ 1.8万–2.0万 ops/s |
| `WorkerPool.map`（×200，并发 8） | ≈ 3.7万–8.0万 ops/s |
| `Futures::all`（聚合 ×200 个 Future） | ≈ 2.3k ops/s |
| `Concurrency\Channel`（send+recv ×200k，进程内） | ≈ 13–18M ops/s |
| `Concurrency\Lock`（withLock 自增 ×20k） | ≈ 96k–109k ops/s |
| `Concurrency\Atomic`（进程内 inc ×5M，纯内存快路径） | ≈ 13–17M ops/s |
| `Concurrency\Atomic`（跨进程 inc ×30k，6 进程争用） | ≈ 17k ops/s，最终值 30,000 ✅ 零丢失 |
| `Concurrency\Barrier`（跨进程 4 方 ×50 回合） | ≈ 340–374 回合/s |
| `Concurrency\Semaphore`（进程内 acquire+release ×5M） | ≈ 7.3M ops/s |

## 实测结果（普通 PHP，pcntl 多进程回退，v1.11.0）

| 测试项 | 吞吐（代表值） |
|--------|----------------|
| process 引擎任务扇出（submit+get ×200，空任务） | ≈ 2.0k–2.6k ops/s |
| 并行映射（parallel_map ×100，轻量计算） | ≈ 2.5k ops/s |
| `WorkerPool.map`（×200，并发 8） | ≈ 1.9k–2.4k ops/s |
| `Futures::all`（聚合 ×200 个 Future） | ≈ 2.4k ops/s |
| `Concurrency\Channel`（send+recv ×200k，进程内） | ≈ 11–35M ops/s |
| `Concurrency\Lock`（withLock 自增 ×20k） | ≈ 58k–109k ops/s |
| `Concurrency\Atomic`（进程内 inc ×5M，纯内存快路径） | ≈ 15–34M ops/s |
| `Concurrency\Atomic`（跨进程 inc ×30k，6 进程争用） | ≈ 17k ops/s，最终值 30,000 ✅ 零丢失 |
| `Concurrency\Barrier`（跨进程 4 方 ×50 回合） | ≈ 275–372 回合/s |
| `Concurrency\Semaphore`（进程内 acquire+release ×5M） | ≈ 7.6–16.4M ops/s |

> **真线程 vs 多进程**：parallel 引擎的任务派发（submit+get）比 process 引擎快约 **2 个数量级**（~20万 vs ~2.2k ops/s），
> 因为真线程免去了 `fork` 与 IPC 重建开销；进程内原子/信号量在 ZTS 下因线程安全簿记略低于 NTS，但跨进程原语两者一致。

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

> 四个脚本同口径（均为「派发 N 个任务并回收结果」），可并排比较：
> `bench_concurrency.php`（kode）｜`bench_swoole.php`（Swoole 6.2 线程）｜`bench_ext_parallel.php`（ext-parallel 真线程）｜`bench_pcntl.php`（裸 pcntl 地板）。

| 测试项 | kode（ZTS 真线程） | kode（普通 PHP 多进程） | 裸 pcntl | ext-parallel | Swoole 6 |
|--------|-------------------|------------------------|----------|--------------|----------|
| 运行前提 | ZTS + ext-parallel | 普通 PHP | 普通 PHP | **必须 ZTS** | **必须 ZTS** |
| 任务派发 submit/run+get | ≈ 20万 ops/s | ≈ 2.2k ops/s | ≈ 2.6k ops/s | 更高（真线程） | 更高（真线程） |
| 进程内原子 inc | ≈ 13–17M ops/s | ≈ 15–34M ops/s | — | 更高（共享内存） | 更高（共享内存） |
| 跨进程共享状态 | ✅ 原生 | ✅ 原生 | ❌ | ❌（线程内） | ❌（线程内） |

**关键结论**：
- **kode 进程引擎 ≈ 裸 pcntl 地板 + 约 16% 封装开销**：说明 kode 在 fork 之上几乎零额外成本，
  其价值在 Future 组合子、引擎自动降级、以及 `Lock`/`Atomic`/`Barrier`/`Semaphore` 的跨进程能力。
- **kode 真线程引擎（ZTS）远超多进程**：任务派发快约 2 个数量级，适合高频率细粒度并行。
- **与 Swoole 6 / ext-parallel 的差异在 ZTS 门槛**：后两者同进程内吞吐更高（共享内存），但强制 ZTS 构建；
  kode 以「文件锁兜底」换得**任意普通 PHP 即可运行 + 跨进程共享**，适用面更广。

## 调优点（历次验证确认并修复）

1. **跨进程 `Atomic` 曾存在高并发丢失更新**：旧实现把 `flock` 与数据读写放在同一文件句柄上，在 macOS
   高并发下丢失排他性。已重构为「独立锁文件（`flock`）+ 数据文件（`file_get_contents`/`put_contents`）」，
   并通过 6 进程 × 5000 次压测验证零丢失。
2. **`flock` 在同一 fd 反复 lock/unlock 不可靠（macOS）**：复用单一 fd 跨多次加锁循环会产生丢失更新；
   改为**每次加锁/解锁都打开并关闭一把新 fd**，稳定性恢复（已写入 `Concurrency\FileLock`）。
3. **未命名 `Atomic`/`Semaphore` 走文件锁是浪费（v1.9.0 修复）**：未命名原语本不跨进程，改为纯内存快路径，
   `Atomic` 提升约 840×、`Semaphore` 达 ~7.7M ops/s。
4. **性能取舍**：为正确性，`Lock`/跨进程 `Atomic`/`Semaphore` 每次操作都会开/关锁文件 fd（约 58k~20k ops/s）。
   这是可移植跨进程原语的固有成本；**同进程内的高频计数/许可请用未命名 `Atomic`/`Semaphore` 或原生变量**。

## 复现

```bash
php benchmarks/bench_concurrency.php     # kode 引擎无关原语（自动探测：ZTS 走真线程，否则多进程）
php benchmarks/bench_pcntl.php           # 裸 pcntl_fork 地板（普通 PHP 即可）
php benchmarks/bench_swoole.php          # Swoole 6.2 线程（需 ZTS + --enable-swoole-thread）
php benchmarks/bench_ext_parallel.php    # ext-parallel 真线程（需 ZTS + ext-parallel）
```

四个脚本输出格式一致（总结均按 ops/s），可跨环境并排比较。
