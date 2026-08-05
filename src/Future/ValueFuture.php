<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

use Kode\Parallel\Exception\ParallelException;

/**
 * 已就绪的 Future
 *
 * 用于同步引擎、缓存命中或测试场景：结果在创建时就已确定。
 *
 * @since 1.6.0
 */
final class ValueFuture implements FutureInterface
{
    use FutureComposeTrait;

    private bool $cancelled = false;
    private readonly string $id;

    private function __construct(
        private readonly mixed $value,
        private readonly ?\Throwable $error,
    ) {
        $this->id = 'value_' . bin2hex(random_bytes(8));
    }

    /**
     * 创建一个已成功的 Future
     */
    public static function resolved(mixed $value): self
    {
        return new self($value, null);
    }

    /**
     * 创建一个已失败的 Future
     */
    public static function rejected(\Throwable $error): self
    {
        return new self(null, $error);
    }

    public function done(): bool
    {
        return true;
    }

    public function get(): mixed
    {
        if ($this->error !== null) {
            throw $this->error instanceof ParallelException
                ? $this->error
                : new ParallelException(
                    '任务执行失败: ' . $this->error->getMessage(),
                    (int) $this->error->getCode(),
                    $this->error
                );
        }

        return $this->value;
    }

    public function getOrNull(): mixed
    {
        return $this->error === null ? $this->value : null;
    }

    public function wait(int $timeoutMs = 0): bool
    {
        return true;
    }

    public function cancel(): bool
    {
        return false;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 是否为失败态
     */
    public function isRejected(): bool
    {
        return $this->error !== null;
    }
}
