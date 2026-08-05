<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

/**
 * Future 异步组合子的默认实现。
 *
 * 通过持有父 Future 与回调，在父 Future 就绪时惰性解析（调用 get()/wait() 时触发），
 * 因此对所有执行引擎（parallel / process / sync）通用，无需为每个引擎单独实现。
 *
 * @internal
 */
trait FutureComposeTrait
{
    /**
     * @param callable(mixed): mixed $onFulfilled
     * @param callable(\Throwable): mixed|null $onRejected
     */
    public function then(callable $onFulfilled, ?callable $onRejected = null): FutureInterface
    {
        return new ChainedFuture($this, $onFulfilled, $onRejected);
    }

    /**
     * @param callable(mixed): mixed $transform
     */
    public function map(callable $transform): FutureInterface
    {
        return new ChainedFuture($this, $transform, null);
    }

    /**
     * @param callable(\Throwable): mixed $onRejected
     */
    public function catch(callable $onRejected): FutureInterface
    {
        return new ChainedFuture($this, null, $onRejected);
    }
}
