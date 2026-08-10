<?php

declare(strict_types=1);

namespace Kode\Parallel\Runtime;

use Kode\Parallel\Engine\EngineFactory;
use Kode\Parallel\Engine\EngineInterface;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Task\Task;

/**
 * Runtime 表示一个并行执行上下文
 *
 * 传入可选的 bootstrap 文件可在任务执行前完成预加载（通常是自动加载器）。
 * 默认采用单线程 FIFO 调度，任务按提交顺序执行——与 ext-parallel 的
 * `\parallel\Runtime` 语义一致。
 *
 * Runtime 构建在引擎抽象之上：
 * - 安装了 ext-parallel（ZTS）→ 使用真线程（parallel 引擎）
 * - 其余环境 → 同步执行（sync 引擎），语义保持一致
 * - 多进程后端可由 kode/process 通过 {@see EngineFactory::register()} 接入
 *
 * 需要在单个 Runtime 内并行执行时，把 `$threads` 设为大于 1；
 * 若只是想要并发上限与批量映射，优先使用 {@see \Kode\Parallel\Pool\WorkerPool}。
 *
 * 可通过构造参数或环境变量 KODE_PARALLEL_ENGINE 强制指定引擎。
 */
final class Runtime
{
    private ?EngineInterface $engine = null;
    private readonly ?string $bootstrap;
    private bool $running = false;
    private int $taskCount = 0;
    /** @var array<int, FutureInterface> 已提交但尚未回收的 Future */
    private array $pending = [];

    /**
     * @param string|null $bootstrap 引导文件路径
     * @param string|null $engine 引擎名（parallel / sync 或外部注册引擎），null 表示自动探测
     * @param int $threads 线程数，默认 1（FIFO 顺序执行）；>1 时在 parallel 引擎下真正并行
     */
    public function __construct(?string $bootstrap = null, ?string $engine = null, int $threads = 1)
    {
        if ($bootstrap !== null && !is_file($bootstrap)) {
            throw new ParallelException(
                "引导文件不存在: {$bootstrap}",
                0,
                null,
                ['bootstrap' => $bootstrap]
            );
        }

        $this->bootstrap = $bootstrap;
        $this->engine = EngineFactory::create($engine, $bootstrap, max(1, $threads));
    }

    /**
     * 执行任务
     *
     * @param Task|callable $task 任务，签名为 fn(array $args): mixed
     * @param array<array-key, mixed> $args 任务参数
     * @throws ParallelException Runtime 已关闭或任务提交失败
     */
    public function run(Task|callable $task, array $args = []): FutureInterface
    {
        if ($this->engine === null) {
            throw new ParallelException('Runtime 已关闭，无法执行任务');
        }

        $closure = $task instanceof Task
            ? $task->getClosure()
            : \Closure::fromCallable($task);

        $future = $this->engine->submit($closure, $args);
        $this->taskCount++;
        $this->pending[spl_object_id($future)] = $future;

        return $future;
    }

    /**
     * 批量执行任务
     *
     * @param iterable<array-key, Task|callable> $tasks
     * @param array<array-key, mixed> $args 所有任务共用的参数
     * @return array<array-key, FutureInterface>
     */
    public function runAll(iterable $tasks, array $args = []): array
    {
        $futures = [];

        foreach ($tasks as $key => $task) {
            $futures[$key] = $this->run($task, $args);
        }

        return $futures;
    }

    /**
     * 当前使用的引擎名
     */
    public function getEngineName(): string
    {
        return $this->engine?->name() ?? 'closed';
    }

    /**
     * 引擎是否真正并行执行
     */
    public function isConcurrent(): bool
    {
        return $this->engine?->isConcurrent() ?? false;
    }

    /**
     * 是否还有已提交但尚未回收（get/wait）的任务在运行
     *
     * 通过跟踪待处理 Future 的真实完成状态得出，跨所有引擎一致。
     */
    public function isRunning(): bool
    {
        foreach ($this->pending as $id => $future) {
            if ($future->done()) {
                unset($this->pending[$id]);
            }
        }

        $this->running = $this->pending !== [];
        return $this->running;
    }

    /**
     * 已提交任务总数
     */
    public function getTaskCount(): int
    {
        return $this->taskCount;
    }

    /**
     * 获取引导文件路径
     */
    public function getBootstrap(): ?string
    {
        return $this->bootstrap;
    }

    /**
     * 关闭 Runtime
     */
    public function close(): void
    {
        $this->engine?->close();
        $this->engine = null;
        $this->running = false;
        $this->pending = [];
    }

    /**
     * 是否已关闭
     */
    public function isClosed(): bool
    {
        return $this->engine === null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
