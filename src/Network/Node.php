<?php

declare(strict_types=1);

namespace Kode\Parallel\Network;

use Kode\Parallel\Exception\ParallelException;

final class Node
{
    private string $id;
    private string $host;
    private int $port;
    private array $metadata;
    private bool $healthy;
    private float $lastHeartbeat;

    public function __construct(
        string $host,
        int $port,
        ?string $id = null,
        array $metadata = []
    ) {
        $this->id = $id ?? $this->generateId($host, $port);
        $this->host = $host;
        $this->port = $port;
        $this->metadata = $metadata;
        $this->healthy = true;
        $this->lastHeartbeat = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getAddress(): string
    {
        return "tcp://{$this->host}:{$this->port}";
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function setHealthy(bool $healthy): void
    {
        $this->healthy = $healthy;
    }

    public function getLastHeartbeat(): float
    {
        return $this->lastHeartbeat;
    }

    public function updateHeartbeat(): void
    {
        $this->lastHeartbeat = microtime(true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'port' => $this->port,
            'metadata' => $this->metadata,
            'healthy' => $this->healthy,
            'last_heartbeat' => $this->lastHeartbeat,
        ];
    }

    public static function fromArray(array $data): self
    {
        $node = new self(
            $data['host'],
            $data['port'],
            $data['id'] ?? null,
            $data['metadata'] ?? []
        );

        if (isset($data['healthy'])) {
            $node->setHealthy($data['healthy']);
        }

        return $node;
    }

    private function generateId(string $host, int $port): string
    {
        return md5("{$host}:{$port}:" . uniqid((string) getmypid(), true));
    }
}
