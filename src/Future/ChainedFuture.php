<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

use Kode\Parallel\Exception\ParallelException;

/**
 * 由 then/map/catch 组合出的派生 Future。
 *
 * 持有父 Future 与回调，在父 Future 就绪时惰性解析：父成功则执行 $onFulfilled，
 * 父失败则执行 $onRejected（若存在），否则向上抛出父异常。
 *
 * @since 1.7.0
 */
final class ChainedFuture implements FutureInterface
{
    use FutureComposeTrait;

    private ?bool $resolved = null;
    private mixed $result = null;
    private ?\Throwable $error = null;
    private bool $cancelled = false;

    /**
     * @param callable(mixed): mixed|null $onFulfilled
     * @param callable(\Throwable): mixed|null $onRejected
     */
    public function __construct(
        private readonly FutureInterface $parent,
        private readonly mixed $onFulfilled,
        private readonly mixed $onRejected,
    ) {
    }

    private function resolve(): void
    {
        if ($this->resolved !== null) {
            return;
        }
        try {
            $value = $this->parent->get();
        } catch (\Throwable $e) {
            if ($this->onRejected !== null) {
                try {
                    $this->result = ($this->onRejected)($e);
                    $this->resolved = true;
                    return;
                } catch (\Throwable $e2) {
                    $this->error = $e2;
                    $this->resolved = true;
                    return;
                }
            }
            $this->error = $e;
            $this->resolved = true;
            return;
        }

        if ($this->onFulfilled !== null) {
            try {
                $this->result = ($this->onFulfilled)($value);
            } catch (\Throwable $e) {
                $this->error = $e;
            }
        } else {
            $this->result = $value;
        }
        $this->resolved = true;
    }

    public function done(): bool
    {
        return $this->parent->done();
    }

    public function get(): mixed
    {
        $this->resolve();
        if ($this->error !== null) {
            throw $this->error instanceof ParallelException
                ? $this->error
                : new ParallelException('组合 Future 执行失败: ' . $this->error->getMessage(), (int) $this->error->getCode(), $this->error);
        }
        return $this->result;
    }

    public function getOrNull(): mixed
    {
        try {
            return $this->get();
        } catch (\Throwable) {
            return null;
        }
    }

    public function wait(int $timeoutMs = 0): bool
    {
        return $this->parent->wait($timeoutMs);
    }

    public function cancel(): bool
    {
        return $this->parent->cancel();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled || $this->parent->isCancelled();
    }

    public function getId(): string
    {
        return $this->parent->getId() . '.chained';
    }
}
