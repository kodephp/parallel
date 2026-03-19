<?php

declare(strict_types=1);

namespace Kode\Parallel\Events;

use Kode\Parallel\Exception\ParallelException;
use Countable, Iterator;

/**
 * Events 事件循环类
 *
 * parallel\Events API 实现了原生特性（Traversable）事件循环，
 * 和 parallel\Events::poll() 方法。允许程序员使用 channel 和 future 组合。
 *
 * 程序员只需将 channel 和 future 添加到事件循环中，
 * 可以选择使用 parallel\Events::setInput() 设置用于写入的 input。
 * 然后进入 foreach：当对象可用时，parallel 将从这些对象中读取和写入数据，
 * 同时生成描述已经发生的操作的 parallel\Events\Event 对象。
 */
final class Events implements Iterator, Countable
{
    private \parallel\Events $events;
    private int $flags;
    /** @var array<string, \parallel\Future|\parallel\Channel> */
    private array $objects = [];
    private ?Event $current = null;
    private int $position = 0;
    private bool $iterating = false;

    public const POLLING_ENABLED = \parallel\Events::POLLING_ENABLED;
    public const POLLING_DISABLED = \parallel\Events::POLLING_DISABLED;

    public const LOOP_NONBLOCKING = \parallel\Events::LOOP_NONBLOCKING;
    public const LOOP_BLOCKING = \parallel\Events::LOOP_BLOCKING;

    public function __construct(int $polling = self::POLLING_ENABLED, int $loop = self::LOOP_BLOCKING)
    {
        $this->events = new \parallel\Events($polling, $loop);
        $this->flags = $polling;
    }

    /**
     * 添加 Future 到事件循环
     *
     * @param string $key 事件键名
     * @param \parallel\Future|\Kode\Parallel\Future\Future $future Future 对象
     * @return $this
     */
    public function attachFuture(string $key, \parallel\Future|\Kode\Parallel\Future\Future $future): static
    {
        $inner = $future instanceof \Kode\Parallel\Future\Future
            ? $this->extractInnerFuture($future)
            : $future;

        $this->events->addFuture($key, $inner);
        $this->objects[$key] = $inner;

        return $this;
    }

    /**
     * 添加 Channel 到事件循环
     *
     * @param string $key 事件键名
     * @param \parallel\Channel|\Kode\Parallel\Channel\Channel $channel Channel 对象
     * @return $this
     */
    public function attachChannel(string $key, \parallel\Channel|\Kode\Parallel\Channel\Channel $channel): static
    {
        $inner = $channel instanceof \Kode\Parallel\Channel\Channel
            ? $this->extractInnerChannel($channel)
            : $channel;

        $this->events->addChannel($key, $inner);
        $this->objects[$key] = $inner;

        return $this;
    }

    /**
     * 设置输入数据
     *
     * @param array<string, mixed> $input 关联数组，键为事件键名，值为要写入的数据
     * @return $this
     */
    public function setInput(array $input): static
    {
        $this->events->setInput($input);
        return $this;
    }

    /**
     * 等待并获取下一个事件
     *
     * @return Event|null 事件对象，如果没有事件则返回 null
     */
    public function poll(): ?Event
    {
        try {
            $event = $this->events->poll();

            if ($event === null) {
                return null;
            }

            return new Event($event);
        } catch (\parallel\Events\Error $e) {
            throw new ParallelException(
                '轮询事件失败: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * 获取所有已添加的事件键
     *
     * @return list<string>
     */
    public function getKeys(): array
    {
        return $this->events->getKeys();
    }

    /**
     * 清除所有事件
     *
     * @return $this
     */
    public function clear(): static
    {
        foreach ($this->getKeys() as $key) {
            $this->events->cancel($key);
        }
        $this->objects = [];
        return $this;
    }

    /**
     * 取消特定事件
     *
     * @param string $key 事件键名
     * @return $this
     */
    public function cancel(string $key): static
    {
        $this->events->cancel($key);
        unset($this->objects[$key]);
        return $this;
    }

    /**
     * 检查事件是否存在
     *
     * @param string $key 事件键名
     */
    public function has(string $key): bool
    {
        return in_array($key, $this->getKeys(), true);
    }

    /**
     * 获取当前事件（Iterator 接口）
     */
    public function current(): Event
    {
        if ($this->current === null && $this->iterating) {
            $this->current = $this->poll();
        }

        if ($this->current === null) {
            throw new \RuntimeException('没有更多事件');
        }

        return $this->current;
    }

    /**
     * 获取当前键（Iterator 接口）
     */
    public function key(): int
    {
        return $this->position;
    }

    /**
     * 前进到下一个事件（Iterator 接口）
     */
    public function next(): void
    {
        $this->position++;
        $this->current = $this->poll();
    }

    /**
     * 重置迭代器（Iterator 接口）
     */
    public function rewind(): void
    {
        $this->position = 0;
        $this->iterating = true;
        $this->current = $this->poll();
    }

    /**
     * 检查是否有效（Iterator 接口）
     */
    public function valid(): bool
    {
        if (!$this->iterating) {
            return false;
        }

        if ($this->current !== null) {
            return true;
        }

        $this->current = $this->poll();
        return $this->current !== null;
    }

    /**
     * 统计事件数量（Countable 接口）
     */
    public function count(): int
    {
        return count($this->getKeys());
    }

    /**
     * 提取内部 Future 对象
     *
     * @internal
     */
    private function extractInnerFuture(\Kode\Parallel\Future\Future $future): \parallel\Future
    {
        $reflection = new \ReflectionClass($future);
        $property = $reflection->getProperty('future');
        $property->setAccessible(true);
        return $property->getValue($future);
    }

    /**
     * 提取内部 Channel 对象
     *
     * @internal
     */
    private function extractInnerChannel(\Kode\Parallel\Channel\Channel $channel): \parallel\Channel
    {
        $reflection = new \ReflectionClass($channel);
        $property = $reflection->getProperty('channel');
        $property->setAccessible(true);
        return $property->getValue($channel);
    }

    public function __destruct()
    {
        $this->clear();
    }
}
