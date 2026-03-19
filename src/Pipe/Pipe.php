<?php

declare(strict_types=1);

namespace Kode\Parallel\Pipe;

use Kode\Parallel\Exception\ParallelException;

/**
 * Pipe 管道
 *
 * 提供进程间单向数据传输的管道。
 * 支持面向字节和面向行的读取。
 *
 * @since PHP 8.1+
 * @since PHP 8.5+ (增强的管道操作)
 */
final class Pipe
{
    private \parallel\Pipe $pipe;
    private readonly string $name;
    private bool $closed = false;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->pipe = new \parallel\Pipe($name);
    }

    /**
     * 创建管道
     *
     * @param string $name 管道名称
     */
    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * 打开已存在的管道
     *
     * @param string $name 管道名称
     */
    public static function open(string $name): static
    {
        return new static($name);
    }

    /**
     * 写入数据
     *
     * @param string $data 要写入的数据
     * @param int $timeout 超时时间（毫秒）
     * @return bool 成功返回 true
     */
    public function write(string $data, int $timeout = 0): bool
    {
        if ($this->closed) {
            throw new ParallelException('管道已关闭');
        }

        return $this->pipe->write($data, $timeout);
    }

    /**
     * 读取数据
     *
     * @param int $length 读取字节数，0 表示读取所有可用数据
     * @param int $timeout 超时时间（毫秒）
     * @return string|null 读取的数据，超时返回 null
     */
    public function read(int $length = 0, int $timeout = 0): ?string
    {
        if ($this->closed) {
            throw new ParallelException('管道已关闭');
        }

        return $this->pipe->read($length, $timeout);
    }

    /**
     * 读取一行数据
     *
     * @param int $timeout 超时时间（毫秒）
     * @return string|null 读取的行，超时返回 null
     */
    public function readLine(int $timeout = 0): ?string
    {
        if ($this->closed) {
            throw new ParallelException('管道已关闭');
        }

        $line = '';
        $chunkSize = 1024;
        $elapsed = 0;
        $startTime = hrtime(true);

        while ($elapsed < $timeout || $timeout === 0) {
            $chunk = $this->pipe->read($chunkSize, max(100, $timeout - $elapsed));

            if ($chunk === null) {
                if ($timeout > 0) {
                    return null;
                }
                continue;
            }

            $line .= $chunk;

            if (str_contains($line, "\n")) {
                break;
            }

            $elapsed = (int)((hrtime(true) - $startTime) / 1_000_000);
        }

        return $line === '' ? null : rtrim($line, "\r\n");
    }

    /**
     * 检查管道是否可读
     */
    public function isReadable(): bool
    {
        return !$this->closed && $this->pipe->isReadable();
    }

    /**
     * 检查管道是否可写
     */
    public function isWritable(): bool
    {
        return !$this->closed && $this->pipe->isWritable();
    }

    /**
     * 关闭管道
     */
    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            $this->pipe->close();
        }
    }

    /**
     * 获取管道名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 检查管道是否已关闭
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->close();
    }
}
