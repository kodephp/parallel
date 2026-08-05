# Kode/Parallel 性能基准报告

## 测试环境（本仓库实测，可复现）

| 项目 | 配置 |
|------|------|
| PHP 版本 | 8.3.31（**ZTS: NO**，普通 stock PHP） |
| ext-parallel | 未加载 |
| ext-pcntl | LOADED |
| 默认引擎 | `process`（多进程 / `pcntl_fork`） |
| 操作系统 | macOS（Apple Silicon） |
| 运行方式 | `php benchmarks/bench_concurrency.php` |
| 测试日期 | 2026-08-05 |

> 说明：本环境没有 ZTS / ext-parallel，因此走的是**引擎无关**路径——这也正是 kode/parallel 的核心卖点：
> 在**普通非 ZTS PHP** 上即可运行并行与同步原语。下方所有数据均为该路径真实结果。

## 实测结果（stock PHP，非 ZTS）

| 测试项 | 数值 | 吞吐 |
|--------|------|------|
| 进程引擎任务扇出（submit+get ×200，空任务） | 77.9 ms | ≈ 2.5k ops/s |
| 并行映射（parallel_map ×100，轻量计算） | 35.2 ms | ≈ 2.8k ops/s |
| `Concurrency\Channel`（send+recv ×100k，进程内） | 12.3 ms | ≈ 16.3M ops/s |
| `Concurrency\Lock`（withLock 自增 ×20k） | 183.4 ms | ≈ 109k ops/s |
| `Concurrency\Atomic`（跨进程 inc ×30k，6 进程争用） | 1.45 s | ≈ 20.7k ops/s，最终值 30,000 ✅ 零丢失 |
| `Concurrency\Barrier`（跨进程 4 方 ×50 回合） | 135.4 ms | — |

## 性能评价

| 测试项 | 评价 | 说明 |
|--------|------|------|
| 进程引擎扇出 | ✅ 合格 | fork 模型固有开销，适合 CPU/IO 友好型粗粒度任务 |
| Channel（进程内） | ✅ 极快 | 纯内存队列，对标 Swoole `Thread\Channel` 的进程内场景 |
| Lock / Atomic | ✅ 正确优先 | 每次加锁开/关独立 fd，换取 macOS 下可靠排他（见下文「调优点」） |
| 跨进程 Atomic 正确性 | ✅ 关键胜利 | 6 进程 × 5000 次高频争用，**零丢失更新** |
| Barrier 同步 | ✅ 稳定 | 代际屏障，跨进程可复用 |

## 与 Swoole 6 多线程对比（客观定位）

| 测试项 | kode/parallel（本仓库） | Swoole 6 `Thread\*` | 差异说明 |
|--------|------------------------|---------------------|----------|
| 运行前提 | 普通 PHP 即可 | 必须 ZTS + `--enable-swoole-thread` | kode 部署门槛低得多 |
| 原子计数吞吐 | ≈ 20k ops/s（跨进程，文件锁） | 更高（同进程共享内存） | Swoole 线程走共享内存，天然更快 |
| 跨进程共享 | ✅（Lock/Atomic/Barrier 原生跨进程） | ❌（线程共享内存，限同进程） | kode 独有能力 |
| 跨机器 | ✅ Cluster | ❌ | kode 独有能力 |
| 单进程 Channel | ≈ 16M ops/s | 同量级（进程内） | 接近 |

**结论**：
- Swoole 6 线程原语在**同进程内**吞吐更高（共享内存），但**强制 ZTS**，把部署门槛大幅提高；
- kode/parallel 以「文件锁兜底」换取**可移植 + 跨进程/跨机器**，吞吐低一档但**适用面更宽、正确性已验证**；
- 若你的环境已是 ZTS + Swoole 6，用 Swoole 做同进程高性能、用 kode/parallel 做跨进程/跨机器协调，两者可互补。

## 调优点（本次验证中确认并修复）

1. **跨进程 `Atomic` 曾存在高并发丢失更新**：旧实现把 `flock` 与数据读写放在同一文件句柄上，在 macOS
   高并发下丢失排他性。已重构为「独立锁文件（`flock`）+ 数据文件（`file_get_contents`/`put_contents`）」，
   并通过 6 进程 × 5000 次压测验证零丢失。
2. **`flock` 在同一 fd 反复 lock/unlock 不可靠（macOS）**：复用单一 fd 跨多次加锁循环会产生丢失更新；
   改为**每次加锁/解锁都打开并关闭一把新 fd**，稳定性恢复（已写入 `Concurrency\FileLock`）。
3. **性能取舍**：为正确性，`Lock`/`Atomic` 每次操作都会开/关锁文件 fd（约 109k~20k ops/s）。
   这是可移植跨进程原语的固有成本；**同进程内的高频计数请直接用原生 PHP 变量**，`Atomic` 面向「跨进程共享」场景。

## 复现

```bash
php benchmarks/bench_concurrency.php
```

脚本会打印当前 PHP/ZTS/引擎信息与上述全部测试项，可跨环境复现对比。
