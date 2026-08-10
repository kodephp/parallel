<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Future;
use Kode\Parallel\Future\FutureInterface;

/**
 * ext-parallel 多线程引擎
 *
 * 基于 PHP 官方 ext-parallel 扩展，是本库性能最高的执行后端。
 *
 * **关键语义**：一个 `\parallel\Runtime` 就是一个 PHP 解释器线程，且其内部
 * 采用 FIFO 调度——投递到同一个 Runtime 的任务是**串行**执行的。因此本引擎
 * 维护一个可增长的线程池，按需懒创建线程并轮询派发：
 *
 * - `$threads = 1`（默认）：单线程 FIFO，保持 {@see \Kode\Parallel\Runtime\Runtime} 的顺序语义
 * - `$threads > 1`：前 N 个任务各自占用独立线程真正并行，超出后轮询复用
 *
 * {@see \Kode\Parallel\Pool\WorkerPool} 会把并发上限作为线程数传入，
 * 从而让并发上限在真线程下真实生效。
 *
 * @since 1.6.0
 */
final class ParallelEngine implements EngineInterface
{
    public const string NAME = 'parallel';

    /** @var list<\parallel\Runtime> 懒创建的线程池 */
    private array $runtimes = [];

    /** 轮询游标 */
    private int $cursor = 0;

    private int $submitted = 0;

    private bool $closed = false;

    /** 线程数上限 */
    private readonly int $threads;

    /**
     * @param string|null $bootstrap 引导文件（每个线程启动时加载）
     * @param int $threads 线程数上限，<=1 表示单线程 FIFO
     * @throws ParallelException 环境不支持或线程初始化失败
     */
    public function __construct(private readonly ?string $bootstrap = null, int $threads = 1)
    {
        if (!self::supported()) {
            throw new ParallelException('parallel 引擎不可用：未加载 ext-parallel 扩展');
        }

        $this->threads = max(1, $threads);
    }

    #[\Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[\Override]
    public static function supported(): bool
    {
        return extension_loaded('parallel') && class_exists('\parallel\Runtime', false);
    }

    #[\Override]
    public function isConcurrent(): bool
    {
        return true;
    }

    #[\Override]
    public function submit(\Closure $task, array $args = []): FutureInterface
    {
        if ($this->closed) {
            throw new ParallelException('引擎已关闭，无法提交任务');
        }

        try {
            $future = new Future($this->pick()->run($task, [$args]));
            $this->submitted++;

            return $future;
        } catch (\Throwable $e) {
            throw new ParallelException(
                '任务提交失败: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
        $this->runtimes = [];
        $this->cursor = 0;
    }

    /**
     * 线程数上限
     */
    public function getThreadCount(): int
    {
        return $this->threads;
    }

    /**
     * 已实际创建的线程数
     */
    public function getActiveThreadCount(): int
    {
        return count($this->runtimes);
    }

    /**
     * 已提交任务数
     */
    public function getSubmittedCount(): int
    {
        return $this->submitted;
    }

    /**
     * 获取底层 ext-parallel Runtime（线程池中的第一个）
     *
     * @internal
     */
    public function getNativeRuntime(): ?\parallel\Runtime
    {
        return $this->runtimes[0] ?? null;
    }

    /**
     * 选取用于本次派发的线程
     *
     * 未达线程数上限时优先新建，使并发任务落在不同线程上；达到上限后轮询复用。
     */
    private function pick(): \parallel\Runtime
    {
        if (count($this->runtimes) < $this->threads) {
            $this->runtimes[] = $this->createRuntime();
        }

        $runtime = $this->runtimes[$this->cursor % count($this->runtimes)];
        $this->cursor++;

        return $runtime;
    }

    /**
     * @throws ParallelException 线程创建失败
     */
    private function createRuntime(): \parallel\Runtime
    {
        try {
            return $this->bootstrap !== null
                ? new \parallel\Runtime($this->bootstrap)
                : new \parallel\Runtime();
        } catch (\Throwable $e) {
            throw new ParallelException(
                'ext-parallel Runtime 初始化失败: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e,
                ['bootstrap' => $this->bootstrap]
            );
        }
    }
}
