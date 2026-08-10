<?php

declare(strict_types=1);

namespace Kode\Parallel\Pool;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureComposeTrait;
use Kode\Parallel\Future\FutureInterface;

/**
 * ThreadPool 提交任务返回的 Future
 *
 * 解析是惰性的：调用 {@see get()}/\span{wait()} 时才触发线程池的派发与回收循环，
 * 因此即使一口气入队成千上万个任务，也不会在 submit 时阻塞或预占线程。
 *
 * @internal
 * @since 1.18.0
 */
final class ThreadPoolFuture implements FutureInterface
{
    use FutureComposeTrait;

    public function __construct(
        private readonly ThreadPool $pool,
        private readonly int $id,
        private ?string $key = null
    ) {
    }

    public function withKey(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    #[\Override]
    public function done(): bool
    {
        // 主动驱动线程池推进：回收已完成任务并派发队列积压，
        // 否则只查询状态的调用方（如 Futures::all 的轮询）会永远等待。
        $this->pool->pump();

        return $this->pool->isResolved($this->id);
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->pool->await($this->id, 0);
    }

    #[\Override]
    public function getOrNull(): mixed
    {
        if (!$this->pool->isResolved($this->id)) {
            return null;
        }

        return $this->pool->resultOf($this->id);
    }

    #[\Override]
    public function wait(int $timeoutMs = 0): bool
    {
        if ($this->pool->isResolved($this->id)) {
            return true;
        }

        try {
            $this->pool->await($this->id, $timeoutMs);

            return true;
        } catch (ParallelException $e) {
            if (str_contains($e->getMessage(), '超时')) {
                return false;
            }

            // 任务自身的失败也算“已完成”，wait 返回 true
            return $this->pool->isResolved($this->id);
        }
    }

    #[\Override]
    public function cancel(): bool
    {
        return $this->pool->cancel($this->id);
    }

    #[\Override]
    public function isCancelled(): bool
    {
        return $this->pool->isCancelled($this->id);
    }

    #[\Override]
    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }
}
