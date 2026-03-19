<?php

declare(strict_types=1);

namespace Kode\Parallel\Channel;

use Kode\Parallel\Exception\ParallelException;

/**
 * Channel 通道类
 *
 * Task 可以通过参数调用，使用词法作用域变量（按值）和返回值（通过 Future），
 * 但这些仅允许单向通信：它们允许程序员将数据发送到 task 中并从 task 中检索数据，
 * 但不允许 task 之间进行双向通信。
 *
 * Channel API 允许 task 之间进行双向通信，Channel 是 task 之间类似套接字的链接，
 * 程序员可以用于发送和接收数据。
 */
final class Channel
{
    public const CAPACITY_UNBOUNDED = 0;

    private \parallel\Channel $channel;
    private readonly int $capacity;
    private readonly string $name;

    private function __construct(\parallel\Channel $channel, int $capacity, string $name)
    {
        $this->channel = $channel;
        $this->capacity = $capacity;
        $this->name = $name;
    }

    /**
     * 创建一个无界限通道
     *
     * @param string $name 通道名称
     */
    public static function make(string $name = ''): static
    {
        $channel = \parallel\Channel::make($name);
        return new static($channel, self::CAPACITY_UNBOUNDED, $name);
    }

    /**
     * 创建一个有界限通道
     *
     * 有界限通道会在通道满时阻塞发送操作，在通道空时阻塞接收操作。
     *
     * @param int<1, max> $capacity 通道容量
     * @param string $name 通道名称
     * @throws ParallelException 如果容量无效
     */
    public static function bounded(int $capacity, string $name = ''): static
    {
        if ($capacity < 1) {
            throw new ParallelException('通道容量必须大于等于 1');
        }

        $channel = \parallel\Channel::bounded($capacity, $name);
        return new static($channel, $capacity, $name);
    }

    /**
     * 发送数据到通道
     *
     * 如果通道已满，此操作会阻塞直到通道有空间。
     *
     * @param mixed $value 要发送的数据
     * @throws ParallelException 如果发送失败
     */
    public function send(mixed $value): void
    {
        try {
            $this->channel->send($value);
        } catch (\parallel\Channel\Error $e) {
            throw new ParallelException(
                '发送数据到通道失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 非阻塞发送数据到通道
     *
     * 如果通道已满，抛出异常而不是阻塞。
     *
     * @param mixed $value 要发送的数据
     * @throws ParallelException 如果通道已满或发送失败
     */
    public function sendNonBlocking(mixed $value): void
    {
        try {
            $this->channel->send($value);
        } catch (\parallel\Channel\Error\Busy) {
            throw new ParallelException('通道已满，无法非阻塞发送');
        } catch (\parallel\Channel\Error $e) {
            throw new ParallelException(
                '发送数据到通道失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 从通道接收数据
     *
     * 如果通道为空，此操作会阻塞直到通道有数据。
     *
     * @return mixed 接收到的数据
     * @throws ParallelException 如果接收失败
     */
    public function recv(): mixed
    {
        try {
            return $this->channel->recv();
        } catch (\parallel\Channel\Error $e) {
            throw new ParallelException(
                '从通道接收数据失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 非阻塞从通道接收数据
     *
     * 如果通道为空，返回 null 而不是阻塞。
     *
     * @return mixed|null 接收到数据返回数据，否则返回 null
     */
    public function recvNonBlocking(): mixed
    {
        try {
            return $this->channel->recv();
        } catch (\parallel\Channel\Error\Busy) {
            return null;
        } catch (\parallel\Channel\Error $e) {
            throw new ParallelException(
                '从通道接收数据失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 检查通道是否为空
     */
    public function isEmpty(): bool
    {
        return $this->channel->isEmpty();
    }

    /**
     * 检查通道是否已满
     *
     * 注意：只有有界限通道才能正确判断是否已满，无界限通道永远返回 false。
     */
    public function isFull(): bool
    {
        if ($this->capacity === self::CAPACITY_UNBOUNDED) {
            return false;
        }
        return $this->channel->isFull();
    }

    /**
     * 获取通道容量
     *
     * @return int CAPACITY_UNBOUNDED 表示无界限
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * 获取通道名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 关闭通道
     *
     * 关闭后的通道不能再发送数据，但可以继续接收已有数据。
     */
    public function close(): void
    {
        $this->channel->close();
    }

    /**
     * 获取内部 Channel 对象
     *
     * @internal
     */
    public function getInnerChannel(): \parallel\Channel
    {
        return $this->channel;
    }
}
