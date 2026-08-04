<?php

declare(strict_types=1);

namespace Kode\Parallel\Future;

use Kode\Parallel\Exception\ParallelException;

/**
 * 进程 Future
 *
 * 由 ProcessEngine（pcntl_fork）产生，通过 UNIX socket pair 回传结果。
 * 结果协议：子进程 serialize(['ok' => bool, 'value' => mixed, 'error' => array]) 后写入并关闭写端，
 * 父进程读到 EOF 即认为任务结束。
 *
 * @since 1.6.0
 */
final class ProcessFuture implements FutureInterface
{
    /** 单次读取块大小 */
    private const int CHUNK_SIZE = 65536;

    private string $buffer = '';
    private bool $finished = false;
    private bool $cancelled = false;
    private bool $reaped = false;
    private mixed $value = null;
    private ?ParallelException $error = null;
    private readonly string $id;

    /**
     * @param int $pid 子进程 PID
     * @param resource $stream 读端（非阻塞）
     */
    public function __construct(
        private readonly int $pid,
        private mixed $stream,
    ) {
        $this->id = 'process_' . $pid . '_' . bin2hex(random_bytes(6));
    }

    public function done(): bool
    {
        if ($this->finished) {
            return true;
        }

        $this->drain(false);

        return $this->finished;
    }

    public function get(): mixed
    {
        if ($this->cancelled && !$this->finished) {
            throw new ParallelException('任务已被取消，无法获取返回值', 0, null, ['pid' => $this->pid]);
        }

        if (!$this->finished) {
            $this->drain(true);
        }

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    public function getOrNull(): mixed
    {
        return $this->done() && $this->error === null ? $this->value : null;
    }

    public function wait(int $timeoutMs = 0): bool
    {
        if ($this->finished) {
            return true;
        }

        if ($timeoutMs <= 0) {
            $this->drain(true);

            return true;
        }

        $deadline = hrtime(true) + ($timeoutMs * 1_000_000);

        while (!$this->finished) {
            $this->drain(false);

            if ($this->finished) {
                return true;
            }

            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(500);
        }

        return true;
    }

    public function cancel(): bool
    {
        if ($this->finished) {
            return false;
        }

        $this->cancelled = true;

        if (function_exists('posix_kill')) {
            @posix_kill($this->pid, defined('SIGKILL') ? SIGKILL : 9);
        }

        $this->reap();
        $this->closeStream();
        $this->finished = true;
        $this->error = new ParallelException('任务已被取消', 0, null, ['pid' => $this->pid]);

        return true;
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
     * 子进程 PID
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * 读取子进程输出
     *
     * @param bool $blocking true 时一直读到 EOF
     */
    private function drain(bool $blocking): void
    {
        if ($this->finished || !is_resource($this->stream)) {
            return;
        }

        do {
            $chunk = @fread($this->stream, self::CHUNK_SIZE);

            if ($chunk === false) {
                break;
            }

            if ($chunk !== '') {
                $this->buffer .= $chunk;

                continue;
            }

            if (feof($this->stream)) {
                $this->complete();

                return;
            }

            if (!$blocking) {
                return;
            }

            // 阻塞模式下让出 CPU，等待子进程继续写入
            $read = [$this->stream];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 2000);
        } while (true);
    }

    /**
     * 解析结果并回收子进程
     */
    private function complete(): void
    {
        $this->finished = true;
        $this->closeStream();
        $this->reap();

        if ($this->buffer === '') {
            $this->error = new ParallelException(
                '子进程未返回任何结果（可能异常退出）',
                0,
                null,
                ['pid' => $this->pid]
            );

            return;
        }

        $payload = @unserialize($this->buffer, ['allowed_classes' => true]);

        if (!is_array($payload) || !array_key_exists('ok', $payload)) {
            $this->error = new ParallelException(
                '子进程返回数据无法解析',
                0,
                null,
                ['pid' => $this->pid, 'bytes' => strlen($this->buffer)]
            );

            return;
        }

        if ($payload['ok'] === true) {
            $this->value = $payload['value'] ?? null;

            return;
        }

        $detail = $payload['error'] ?? [];
        $this->error = new ParallelException(
            '任务执行失败: ' . ($detail['message'] ?? '未知错误'),
            (int) ($detail['code'] ?? 0),
            null,
            [
                'pid' => $this->pid,
                'exception' => $detail['class'] ?? \Throwable::class,
                'trace' => $detail['trace'] ?? null,
            ]
        );
    }

    private function reap(): void
    {
        if ($this->reaped || !function_exists('pcntl_waitpid')) {
            return;
        }

        $status = 0;
        @pcntl_waitpid($this->pid, $status);
        $this->reaped = true;
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->stream = null;
    }

    public function __destruct()
    {
        $this->closeStream();
        $this->reap();
    }
}
