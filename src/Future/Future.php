<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

use Kode\Parallel\Exception\ParallelException;

/**
 * Future 用于访问 task 的返回值，并公开用于取消任务的 API
 *
 * Future 代表一个异步任务的未来结果，可以通过 poll() 方法检查任务是否完成，
 * 或者通过 get() 方法获取任务返回值（如果任务未完成会阻塞等待）。
 */
final class Future
{
    private \parallel\Future $future;
    private bool $cancelled = false;
    private readonly string $id;

    public function __construct(\parallel\Future $future)
    {
        $this->future = $future;
        $this->id = spl_object_id($future) . '_' . bin2hex(random_bytes(8));
    }

    /**
     * 检查任务是否完成
     *
     * @return bool 任务完成返回 true，否则返回 false
     */
    public function done(): bool
    {
        return $this->cancelled || $this->future->done();
    }

    /**
     * 获取任务返回值
     *
     * 如果任务未完成，此方法会阻塞等待直到任务完成。
     *
     * @return mixed 任务返回值
     * @throws ParallelException 如果任务被取消或执行失败
     */
    public function get(): mixed
    {
        if ($this->cancelled) {
            throw new ParallelException('任务已被取消，无法获取返回值');
        }

        try {
            return $this->future->value();
        } catch (\parallel\Future\Error $e) {
            throw new ParallelException(
                '获取任务返回值失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 非阻塞获取返回值（如果已完成）
     *
     * @return mixed|null 任务完成返回返回值，未完成返回 null
     */
    public function getOrNull(): mixed
    {
        return $this->done() ? $this->get() : null;
    }

    /**
     * 等待任务完成（可选带超时）
     *
     * @param int $timeoutMs 超时时间（毫秒），0 表示无限等待
     * @return bool 任务完成返回 true，超时返回 false
     */
    public function wait(int $timeoutMs = 0): bool
    {
        if ($this->cancelled) {
            return true;
        }

        if ($timeoutMs <= 0) {
            while (!$this->future->done()) {
                usleep(1000);
            }
            return true;
        }

        $startTime = hrtime(true);
        $timeoutNs = $timeoutMs * 1_000_000;

        while (!$this->future->done()) {
            if ((hrtime(true) - $startTime) >= $timeoutNs) {
                return false;
            }
            usleep(1000);
        }

        return true;
    }

    /**
     * 取消任务
     *
     * 注意：一旦任务开始执行，无法真正取消，只能标记为已取消状态。
     *
     * @return bool 取消成功返回 true
     */
    public function cancel(): bool
    {
        if ($this->done()) {
            return false;
        }

        $this->cancelled = true;
        return true;
    }

    /**
     * 检查任务是否被取消
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * 获取任务ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * 获取内部 Future 对象
     *
     * @internal
     */
    public function getInnerFuture(): \parallel\Future
    {
        return $this->future;
    }
}
