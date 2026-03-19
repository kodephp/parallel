<?php

declare(strict_types=1);

namespace Kode\Parallel\Events;

use Kode\Parallel\Exception\ParallelException;

/**
 * Event 事件对象类
 *
 * 当通过 Events 事件循环获取事件时，会返回 Event 对象。
 * Event 对象描述了已经发生的操作类型及其相关数据。
 */
final class Event
{
    public const TYPE_FUTURE = 'future';
    public const TYPE_CHANNEL = 'channel';

    public const READY = \parallel\Events\Event::READY;
    public const CLOSED = \parallel\Events\Event::CLOSED;

    private \parallel\Events\Event $event;
    private string $type;
    private string $key;
    private mixed $value;
    private int $source;

    public function __construct(\parallel\Events\Event $event)
    {
        $this->event = $event;
        $this->type = $this->detectType();
        $this->key = $event->key;
        $this->value = $event->value ?? null;
        $this->source = $event->source;
    }

    /**
     * 检测事件类型
     */
    private function detectType(): string
    {
        if (isset($this->event->value) && $this->event->value instanceof \parallel\Future) {
            return self::TYPE_FUTURE;
        }

        if (isset($this->event->value) && $this->event->value instanceof \parallel\Channel) {
            return self::TYPE_CHANNEL;
        }

        return 'unknown';
    }

    /**
     * 获取事件键名
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * 获取事件类型
     *
     * @return string 事件类型：TYPE_FUTURE 或 TYPE_CHANNEL
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * 获取事件值
     *
     * 对于 Future 事件，返回 Future 对象。
     * 对于 Channel 事件，返回从通道接收的数据。
     *
     * @return mixed 事件值
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * 获取事件来源
     *
     * @return int 事件来源标识
     */
    public function getSource(): int
    {
        return $this->source;
    }

    /**
     * 检查事件是否就绪
     *
     * @return bool 就绪返回 true
     */
    public function isReady(): bool
    {
        return $this->source === self::READY;
    }

    /**
     * 检查事件是否已关闭
     *
     * @return bool 关闭返回 true
     */
    public function isClosed(): bool
    {
        return $this->source === self::CLOSED;
    }

    /**
     * 判断是否为 Future 事件
     */
    public function isFuture(): bool
    {
        return $this->type === self::TYPE_FUTURE;
    }

    /**
     * 判断是否为 Channel 事件
     */
    public function isChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL;
    }

    /**
     * 获取原始事件对象
     *
     * @internal
     */
    public function getInnerEvent(): \parallel\Events\Event
    {
        return $this->event;
    }
}
