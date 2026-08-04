<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\ValueFuture;

/**
 * 同步回退引擎
 *
 * 在当前进程内立即执行任务，语义与并行引擎完全一致（返回 Future），
 * 用于 Windows 无扩展环境、CI 兜底或单步调试。任何环境下都可用。
 *
 * @since 1.6.0
 */
final class SyncEngine implements EngineInterface
{
    public const string NAME = 'sync';

    private int $executed = 0;

    public function __construct(private readonly ?string $bootstrap = null)
    {
        if ($this->bootstrap !== null && is_file($this->bootstrap)) {
            require_once $this->bootstrap;
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
        return true;
    }

    #[\Override]
    public function isConcurrent(): bool
    {
        return false;
    }

    #[\Override]
    public function submit(\Closure $task, array $args = []): FutureInterface
    {
        $this->executed++;

        try {
            return ValueFuture::resolved($task($args));
        } catch (\Throwable $e) {
            return ValueFuture::rejected($e);
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->executed = 0;
    }

    /**
     * 已执行任务数
     */
    public function getExecutedCount(): int
    {
        return $this->executed;
    }
}
