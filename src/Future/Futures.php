<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

use Kode\Parallel\Exception\ParallelException;

/**
 * Future 组合器
 *
 * 提供类似 Promise 的批量语义：all / settle / any / race，
 * 全部支持毫秒级超时，超时后可选择自动取消未完成任务。
 *
 * @since 1.6.0
 */
final class Futures
{
    /** 轮询间隔（微秒） */
    private const int POLL_INTERVAL_US = 500;

    public const string STATUS_FULFILLED = 'fulfilled';
    public const string STATUS_REJECTED = 'rejected';

    /**
     * 等待全部完成，任一失败立即抛出
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @param int $timeoutMs 超时毫秒数，<=0 表示无限等待
     * @return array<array-key, mixed> 与输入键一一对应的结果
     * @throws ParallelException 任一任务失败或整体超时
     */
    public static function all(iterable $futures, int $timeoutMs = 0): array
    {
        $list = self::normalize($futures);
        self::awaitAll($list, $timeoutMs, true);

        $results = [];

        foreach ($list as $key => $future) {
            $results[$key] = $future->get();
        }

        return $results;
    }

    /**
     * 等待全部结束并返回每个任务的状态，绝不抛出任务级异常
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @return array<array-key, array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public static function settle(iterable $futures, int $timeoutMs = 0): array
    {
        $list = self::normalize($futures);
        self::awaitAll($list, $timeoutMs, false);

        $results = [];

        foreach ($list as $key => $future) {
            if (!$future->done()) {
                $results[$key] = [
                    'status' => self::STATUS_REJECTED,
                    'reason' => new ParallelException('任务超时未完成', 0, null, ['id' => $future->getId()]),
                ];

                continue;
            }

            try {
                $results[$key] = ['status' => self::STATUS_FULFILLED, 'value' => $future->get()];
            } catch (\Throwable $e) {
                $results[$key] = ['status' => self::STATUS_REJECTED, 'reason' => $e];
            }
        }

        return $results;
    }

    /**
     * 返回第一个成功的结果，全部失败才抛出
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @throws ParallelException 全部失败或超时
     */
    public static function any(iterable $futures, int $timeoutMs = 0): mixed
    {
        $list = self::normalize($futures);

        if ($list === []) {
            throw new ParallelException('Futures::any() 需要至少一个任务');
        }

        $deadline = $timeoutMs > 0 ? hrtime(true) + ($timeoutMs * 1_000_000) : null;
        $pending = $list;
        $errors = [];

        while ($pending !== []) {
            foreach ($pending as $key => $future) {
                if (!$future->done()) {
                    continue;
                }

                unset($pending[$key]);

                try {
                    $value = $future->get();
                    self::cancelAll($pending);

                    return $value;
                } catch (\Throwable $e) {
                    $errors[$key] = $e->getMessage();
                }
            }

            if ($pending === []) {
                break;
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                self::cancelAll($pending);

                throw new ParallelException('Futures::any() 等待超时', 0, null, ['errors' => $errors]);
            }

            usleep(self::POLL_INTERVAL_US);
        }

        throw new ParallelException('全部任务均失败', 0, null, ['errors' => $errors]);
    }

    /**
     * 返回第一个结束的任务结果（成功或失败都算结束）
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @throws ParallelException 超时，或第一个结束的任务本身失败
     */
    public static function race(iterable $futures, int $timeoutMs = 0): mixed
    {
        $list = self::normalize($futures);

        if ($list === []) {
            throw new ParallelException('Futures::race() 需要至少一个任务');
        }

        $deadline = $timeoutMs > 0 ? hrtime(true) + ($timeoutMs * 1_000_000) : null;

        while (true) {
            foreach ($list as $key => $future) {
                if (!$future->done()) {
                    continue;
                }

                $rest = $list;
                unset($rest[$key]);
                self::cancelAll($rest);

                return $future->get();
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                self::cancelAll($list);

                throw new ParallelException('Futures::race() 等待超时');
            }

            usleep(self::POLL_INTERVAL_US);
        }
    }

    /**
     * 批量取消未完成任务
     *
     * @param iterable<array-key, FutureInterface> $futures
     * @return int 实际取消的数量
     */
    public static function cancelAll(iterable $futures): int
    {
        $count = 0;

        foreach ($futures as $future) {
            if (!$future instanceof FutureInterface) {
                continue;
            }

            if (!$future->done() && $future->cancel()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 统计已完成数量
     *
     * @param iterable<array-key, FutureInterface> $futures
     */
    public static function countDone(iterable $futures): int
    {
        $count = 0;

        foreach ($futures as $future) {
            if ($future instanceof FutureInterface && $future->done()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<array-key, FutureInterface> $futures
     * @throws ParallelException 超时且 $throwOnTimeout 为 true
     */
    private static function awaitAll(array $futures, int $timeoutMs, bool $throwOnTimeout): void
    {
        if ($futures === []) {
            return;
        }

        $deadline = $timeoutMs > 0 ? hrtime(true) + ($timeoutMs * 1_000_000) : null;

        while (true) {
            $pending = 0;

            foreach ($futures as $future) {
                if (!$future->done()) {
                    $pending++;
                }
            }

            if ($pending === 0) {
                return;
            }

            if ($deadline !== null && hrtime(true) >= $deadline) {
                if ($throwOnTimeout) {
                    self::cancelAll($futures);

                    throw new ParallelException(
                        '等待全部任务超时',
                        0,
                        null,
                        ['pending' => $pending, 'timeout_ms' => $timeoutMs]
                    );
                }

                return;
            }

            usleep(self::POLL_INTERVAL_US);
        }
    }

    /**
     * @param iterable<array-key, FutureInterface> $futures
     * @return array<array-key, FutureInterface>
     */
    private static function normalize(iterable $futures): array
    {
        $list = [];

        foreach ($futures as $key => $future) {
            if (!$future instanceof FutureInterface) {
                throw new ParallelException(
                    'Futures 组合器只接受 FutureInterface 实例，收到: ' . get_debug_type($future)
                );
            }

            $list[$key] = $future;
        }

        return $list;
    }
}
