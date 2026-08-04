<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\Future;
use Kode\Parallel\Future\FutureInterface;

/**
 * ext-parallel 多线程引擎
 *
 * 基于 PHP 官方 ext-parallel 扩展，一个 Runtime 即一个 PHP 解释器线程，
 * FIFO 调度，是本库性能最高的执行后端。
 *
 * @since 1.6.0
 */
final class ParallelEngine implements EngineInterface
{
    public const string NAME = 'parallel';

    private ?\parallel\Runtime $runtime = null;

    private int $submitted = 0;

    public function __construct(private readonly ?string $bootstrap = null)
    {
        if (!self::supported()) {
            throw new ParallelException('parallel 引擎不可用：未加载 ext-parallel 扩展');
        }

        try {
            $this->runtime = $this->bootstrap !== null
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
        if ($this->runtime === null) {
            throw new ParallelException('引擎已关闭，无法提交任务');
        }

        try {
            $this->submitted++;

            return new Future($this->runtime->run($task, [$args]));
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
        $this->runtime = null;
    }

    /**
     * 已提交任务数
     */
    public function getSubmittedCount(): int
    {
        return $this->submitted;
    }

    /**
     * 获取底层 ext-parallel Runtime
     *
     * @internal
     */
    public function getNativeRuntime(): ?\parallel\Runtime
    {
        return $this->runtime;
    }
}
