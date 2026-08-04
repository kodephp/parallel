<?php

declare(strict_types=1);

namespace Kode\Parallel\Engine;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Future\FutureInterface;
use Kode\Parallel\Future\ProcessFuture;

/**
 * 多进程引擎（pcntl_fork）
 *
 * 无需任何 PECL 扩展即可获得真正的并行能力：
 * fork 天然复制父进程内存，因此任务闭包无需序列化，
 * 结果通过 UNIX socket pair 以 serialize() 回传。
 *
 * 限制：
 * - 仅类 UNIX 系统可用（Windows 请使用 sync 引擎）
 * - 任务返回值必须可序列化（闭包、资源、PDO 连接等不可回传）
 * - 子进程修改的内存不会同步回父进程，共享状态请使用 Channel / 外部存储
 *
 * @since 1.6.0
 */
final class ProcessEngine implements EngineInterface
{
    public const string NAME = 'process';

    /** @var array<int, ProcessFuture> */
    private array $futures = [];

    private int $submitted = 0;

    public function __construct(private readonly ?string $bootstrap = null)
    {
        if (!self::supported()) {
            throw new ParallelException(
                'process 引擎不可用：需要 pcntl 扩展与类 UNIX 系统',
                0,
                null,
                ['os' => PHP_OS_FAMILY, 'pcntl' => extension_loaded('pcntl')]
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
        return PHP_OS_FAMILY !== 'Windows'
            && function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('stream_socket_pair');
    }

    #[\Override]
    public function isConcurrent(): bool
    {
        return true;
    }

    #[\Override]
    public function submit(\Closure $task, array $args = []): FutureInterface
    {
        $pair = @stream_socket_pair(
            PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
        );

        if ($pair === false) {
            throw new ParallelException('创建进程间通道失败');
        }

        [$parentSide, $childSide] = $pair;

        $pid = @pcntl_fork();

        if ($pid === -1) {
            fclose($parentSide);
            fclose($childSide);

            throw new ParallelException('fork 子进程失败，可能已达到系统进程上限');
        }

        if ($pid === 0) {
            fclose($parentSide);
            $this->runChild($task, $args, $childSide);
        }

        fclose($childSide);
        stream_set_blocking($parentSide, false);

        $future = new ProcessFuture($pid, $parentSide);
        $this->futures[] = $future;
        $this->submitted++;

        return $future;
    }

    #[\Override]
    public function close(): void
    {
        foreach ($this->futures as $future) {
            if (!$future->done()) {
                $future->cancel();
            }
        }

        $this->futures = [];
    }

    /**
     * 已提交任务数
     */
    public function getSubmittedCount(): int
    {
        return $this->submitted;
    }

    /**
     * 子进程执行体：执行任务 → 回写结果 → 立即终止
     *
     * @param array<array-key, mixed> $args
     * @param resource $channel
     */
    private function runChild(\Closure $task, array $args, mixed $channel): never
    {
        $payload = ['ok' => true, 'value' => null];

        try {
            if ($this->bootstrap !== null && is_file($this->bootstrap)) {
                require_once $this->bootstrap;
            }

            $payload['value'] = $task($args);
        } catch (\Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => [
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                    'code' => (int) $e->getCode(),
                    'trace' => $e->getTraceAsString(),
                ],
            ];
        }

        try {
            $data = serialize($payload);
        } catch (\Throwable $e) {
            $data = serialize([
                'ok' => false,
                'error' => [
                    'class' => $e::class,
                    'message' => '任务返回值无法序列化: ' . $e->getMessage(),
                    'code' => 0,
                    'trace' => null,
                ],
            ]);
        }

        @fwrite($channel, $data);
        @fflush($channel);
        @fclose($channel);

        $this->terminateChild();
    }

    /**
     * 强制终止子进程
     *
     * 使用 SIGKILL 而非 exit()，避免触发父进程注册的 shutdown 回调
     * （典型如 PHPUnit 结果输出、框架的日志刷写）造成重复副作用。
     */
    private function terminateChild(): never
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
