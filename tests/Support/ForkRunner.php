<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests\Support;

/**
 * 测试辅助：直接 fork 子进程并收集返回值
 *
 * `Concurrency\*` 原语基于文件锁实现，其**跨进程**共享能力是原语自身的属性，
 * 与执行引擎无关。本库聚焦多线程、不再内置多进程引擎，因此这些回归测试
 * 直接使用 pcntl 验证，避免依赖任何引擎实现。
 */
final class ForkRunner
{
    /**
     * 当前环境是否可 fork
     */
    public static function supported(): bool
    {
        return PHP_OS_FAMILY !== 'Windows'
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair');
    }

    /**
     * fork 出 $count 个子进程并发执行 $child，按索引顺序收集返回值
     *
     * @param \Closure(int): mixed $child 子进程执行体，入参为从 0 开始的序号
     * @return list<mixed>
     * @throws \RuntimeException fork 失败或子进程异常
     */
    public static function run(int $count, \Closure $child): array
    {
        $channels = [];
        $pids = [];

        for ($i = 0; $i < $count; $i++) {
            $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($pair === false) {
                throw new \RuntimeException('创建进程间通道失败');
            }

            [$parentSide, $childSide] = $pair;
            $pid = @pcntl_fork();

            if ($pid === -1) {
                fclose($parentSide);
                fclose($childSide);

                throw new \RuntimeException('fork 子进程失败');
            }

            if ($pid === 0) {
                fclose($parentSide);
                self::runChild($child, $i, $childSide);
            }

            fclose($childSide);
            $channels[$i] = $parentSide;
            $pids[$i] = $pid;
        }

        $results = [];

        foreach ($channels as $i => $channel) {
            $buffer = stream_get_contents($channel);
            fclose($channel);
            pcntl_waitpid($pids[$i], $status);

            $payload = @unserialize((string) $buffer, ['allowed_classes' => false]);

            if (!is_array($payload) || !array_key_exists('ok', $payload)) {
                throw new \RuntimeException("子进程 #{$i} 未回传有效结果");
            }

            if ($payload['ok'] !== true) {
                throw new \RuntimeException("子进程 #{$i} 异常: " . $payload['error']);
            }

            $results[$i] = $payload['value'];
        }

        return $results;
    }

    /**
     * 子进程执行体：执行 → 回写 → 立即终止
     *
     * @param resource $channel
     */
    private static function runChild(\Closure $child, int $index, mixed $channel): never
    {
        try {
            $payload = ['ok' => true, 'value' => $child($index)];
        } catch (\Throwable $e) {
            $payload = ['ok' => false, 'error' => $e->getMessage()];
        }

        @fwrite($channel, serialize($payload));
        @fflush($channel);
        @fclose($channel);

        self::terminate();
    }

    /**
     * 强制终止子进程
     *
     * 使用 SIGKILL 而非 exit()，避免触发父进程注册的 shutdown 回调
     * （典型如 PHPUnit 结果输出）造成重复副作用。
     */
    private static function terminate(): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            @posix_kill(posix_getpid(), defined('SIGKILL') ? SIGKILL : 9);
        }

        exit(0);
    }
}
