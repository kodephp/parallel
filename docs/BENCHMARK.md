# Kode/Parallel 性能基准报告

> 版本：`v1.9.0` ｜ kode 栈：`kode/context 3.0.0` / `kode/facade 3.2.0` / `kode/fibers 4.5.0`
> 本次新增：**未命名 `Atomic` 进程内纯内存快路径**（约 16.6M ops/s，比跨进程文件锁实现快 ~840×），以及 Swoole 6.2 同类对比基准 `benchmarks/bench_swoole.php`。

## 测试环境（本仓库实测，可复现）

| 项目 | 配置 |
|------|------|
| PHP 版本 | 8.3.31（**ZTS: NO**，普通 stock PHP） |
| ext-parallel | 未加载 |
| ext-pcntl | LOADED |
| ext-sockets | LOADED |
| 默认引擎 | `process`（多进程 / `pcntl_fork`） |
| 操作系统 | macOS（Apple Silicon） |
| 运行方式 | `php benchmarks/bench_concurrency.php` |
| 测试日期 | 2026-08-06 |

> 说明：本环境没有 ZTS / ext-parallel，因此走的是**引擎无关**路径——这也正是 kode/parallel 的核心卖点：
> 在**普通非 ZTS PHP** 上即可运行并行与同步原语。下方所有数据均为该路径真实结果（非估算）。

## 实测结果（stock PHP，非 ZTS，v1.9.0）

| 测试项 | 数值 | 吞吐 |
|--------|------|------|
| 进程引擎任务扇出（submit+get ×200，空任务） | 69.1 ms | ≈ 2.9k ops/s |
| 并行映射（parallel_map ×100，轻量计算） | 34.0 ms | ≈ 2.9k ops/s |
| `WorkerPool.map`（×200，平方，并发 8） | 77.2 ms | ≈ 2.6k ops/s |
| `Futures::all`（聚合 ×200 个 Future） | 64.7 ms | ≈ 3.1k ops/s |
| `Concurrency\Channel`（send+recv ×200k，进程内） | 24.0 ms | ≈ 16.7M ops/s |
| `Concurrency\Lock`（withLock 自增 ×20k） | 182.8 ms | ≈ 109k ops/s |
| **`Concurrency\Atomic`（进程内 inc ×5M，纯内存快路径）** | 300.7 ms | **≈ 16.6M ops/s** |
| `Concurrency\Atomic`（跨进程 inc ×30k，6 进程争用） | 1.52 s | ≈ 19.7k ops/s，最终值 30,000 ✅ 零丢失 |
| `Concurrency\Barrier`（跨进程 4 方 ×50 回合） | 134.4 ms | ≈ 372 回合/s |

## v1.9.0 关键优化：未命名 `Atomic` 进程内快路径

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

## 性能评价

| 测试项 | 评价 | 说明 |
|--------|------|------|
| 进程引擎扇出 | ✅ 合格 | fork 模型固有开销，适合 CPU/IO 友好型粗粒度任务 |
| Channel（进程内） | ✅ 极快 | 纯内存队列，对标 Swoole `Thread\Queue` 进程内场景（同量级） |
| **Atomic（进程内）** | ✅✅ 极快 | v1.9.0 新增纯内存快路径，≈ 16.6M ops/s |
| Lock / 跨进程 Atomic | ✅ 正确优先 | 每次加锁开/关独立 fd，换取 macOS 下可靠排他（见「调优点」） |
| 跨进程 Atomic 正确性 | ✅ 关键胜利 | 6 进程 × 5000 次高频争用，**零丢失更新** |
| Barrier 同步 | ✅ 稳定 | 代际屏障，跨进程可复用 |

## 与 Swoole 6 多线程对比（客观定位）

> 同类对比基准：`php benchmarks/bench_swoole.php`（需 ZTS + `--enable-swoole-thread` 构建；否则优雅跳过）。

| 测试项 | kode/parallel（本仓库，非 ZTS） | Swoole 6 `Thread\*` | 差异说明 |
|--------|------------------------------|---------------------|----------|
| 运行前提 | 普通 PHP 即可 | 必须 ZTS + `--enable-swoole-thread` | kode 部署门槛低得多 |
| 原子计数（进程内） | ≈ 16.6M ops/s（内存快路径） | 更高（同进程共享内存） | Swoole 线程走共享内存，天然更快 |
| 原子计数（跨进程） | ≈ 19.7k ops/s（文件锁） | ❌ 不支持（限同进程） | kode 独有能力 |
| 跨进程共享 | ✅（`Lock`/`Atomic`/`Barrier` 原生跨进程） | ❌（线程共享内存，限同进程） | kode 独有能力 |
| 跨机器 | ✅ Cluster | ❌ | kode 独有能力 |
| 单进程 Channel | ≈ 16.7M ops/s | 同量级（进程内） | 接近 |
| 互斥锁 | ≈ 109k ops/s（文件锁） | 更高（线程锁） | Swoole 线程锁更省 |

**结论**：
- Swoole 6 线程原语在**同进程内**吞吐更高（共享内存），但**强制 ZTS**，把部署门槛大幅提高；
- kode/parallel 以「文件锁兜底」换取**可移植 + 跨进程/跨机器**，且 v1.9.0 通过进程内内存快路径把
  最常见的计数场景推到 ~16.6M ops/s；
- 若你的环境已是 ZTS + Swoole 6，用 Swoole 做同进程高性能、用 kode/parallel 做跨进程/跨机器协调，两者互补。

## 调优点（本次验证中确认并修复）

1. **跨进程 `Atomic` 曾存在高并发丢失更新**：旧实现把 `flock` 与数据读写放在同一文件句柄上，在 macOS
   高并发下丢失排他性。已重构为「独立锁文件（`flock`）+ 数据文件（`file_get_contents`/`put_contents`）」，
   并通过 6 进程 × 5000 次压测验证零丢失。
2. **`flock` 在同一 fd 反复 lock/unlock 不可靠（macOS）**：复用单一 fd 跨多次加锁循环会产生丢失更新；
   改为**每次加锁/解锁都打开并关闭一把新 fd**，稳定性恢复（已写入 `Concurrency\FileLock`）。
3. **未命名 `Atomic` 走文件锁是浪费（v1.9.0 修复）**：未命名计数器本不跨进程，旧版仍走文件 I/O。
   现改为纯内存快路径，吞吐提升约 840×。
4. **性能取舍**：为正确性，`Lock`/跨进程 `Atomic` 每次操作都会开/关锁文件 fd（约 109k~19.7k ops/s）。
   这是可移植跨进程原语的固有成本；**同进程内的高频计数请直接用 `Atomic`（未命名）或原生 PHP 变量**。

## 复现

```bash
# kode/parallel 引擎无关原语基准（普通 PHP 即可）
php benchmarks/bench_concurrency.php

# Swoole 6.2 同类对比基准（需 ZTS + --enable-swoole-thread；否则优雅跳过）
php benchmarks/bench_swoole.php
```

两个脚本输出格式一致（总结均按 ops/s），可跨环境并排比较。
