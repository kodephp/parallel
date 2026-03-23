<?php

declare(strict_types=1);

namespace Kode\Parallel\Runtime;

use Kode\Runtime\ChannelInterface;
use Kode\Runtime\RuntimeInterface;
use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Channel\Channel;

/**
 * ext-parallel 运行时适配器
 *
 * 为 kode/runtime 提供 ext-parallel 支持
 */
final class ParallelRuntimeAdapter implements RuntimeInterface
{
    private ?Runtime $runtime = null;
    private array $channels = [];
    private array $deferredCallbacks = [];

    public function __construct(?string $bootstrap = null)
    {
        $this->runtime = new Runtime($bootstrap);
    }

    public function getName(): string
    {
        return 'PARALLEL';
    }

    public function async(callable $callback): mixed
    {
        $future = $this->runtime->run($callback);
        return new ParallelFutureHandle($future);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int)($seconds * 1_000_000));
        }
    }

    public function createChannel(int $capacity = 0): ChannelInterface
    {
        $channel = Channel::make('runtime_channel_' . uniqid(), $capacity);
        $this->channels[] = $channel;
        return new ParallelChannelAdapter($channel);
    }

    public function defer(callable $callback): void
    {
        $this->deferredCallbacks[] = $callback;
    }

    public function wait(): void
    {
        foreach ($this->deferredCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('Deferred callback error: ' . $e->getMessage());
            }
        }

        $this->deferredCallbacks = [];
    }

    public function close(): void
    {
        foreach ($this->channels as $channel) {
            try {
                $channel->close();
            } catch (\Throwable $e) {
            }
        }

        $this->channels = [];

        if ($this->runtime !== null) {
            $this->runtime->close();
            $this->runtime = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

/**
 * Parallel Future 句柄包装
 */
final class ParallelFutureHandle
{
    private \Kode\Parallel\Future\Future $future;

    public function __construct(\Kode\Parallel\Future\Future $future)
    {
        $this->future = $future;
    }

    public function get(?float $timeout = null): mixed
    {
        if ($timeout !== null) {
            $this->future->wait((int)($timeout * 1000));
        }

        return $this->future->get();
    }

    public function isComplete(): bool
    {
        return $this->future->isComplete();
    }

    public function cancel(): bool
    {
        return $this->future->cancel();
    }
}

/**
 * Parallel Channel 适配器
 */
final class ParallelChannelAdapter implements ChannelInterface
{
    private \Kode\Parallel\Channel\Channel $channel;

    public function __construct(\Kode\Parallel\Channel\Channel $channel)
    {
        $this->channel = $channel;
    }

    public function push(mixed $data, ?float $timeout = null): bool
    {
        $this->channel->send($data);
        return true;
    }

    public function pop(?float $timeout = null): mixed
    {
        return $this->channel->recv();
    }

    public function close(): void
    {
        $this->channel->close();
    }

    public function isEmpty(): bool
    {
        return $this->channel->isEmpty();
    }

    public function isFull(): bool
    {
        return false;
    }

    public function getCapacity(): int
    {
        return 0;
    }

    public function getLength(): int
    {
        return $this->channel->isEmpty() ? 0 : 1;
    }
}
