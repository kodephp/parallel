<?php

declare(strict_types=1);

namespace Kode\Parallel\Network;

use Kode\Parallel\Exception\ParallelException;

class TcpNodeTransport
{
    private ?Node $localNode = null;
    private array $nodes = [];
    private float $connectTimeout = 5.0;
    private float $readTimeout = 30.0;
    private int $maxRetries = 3;

    public function __construct(?Node $localNode = null)
    {
        $this->localNode = $localNode;
    }

    public function registerNode(Node $node): void
    {
        $this->nodes[$node->getId()] = $node;
    }

    public function unregisterNode(string $nodeId): void
    {
        unset($this->nodes[$nodeId]);
    }

    public function getNode(string $nodeId): ?Node
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function getHealthyNodes(): array
    {
        return array_filter($this->nodes, fn(Node $node) => $node->isHealthy());
    }

    public function setLocalNode(Node $node): void
    {
        $this->localNode = $node;
    }

    public function getLocalNode(): ?Node
    {
        return $this->localNode;
    }

    public function send(string $nodeId, array $payload): array
    {
        $node = $this->getNode($nodeId);

        if ($node === null) {
            throw new ParallelException("节点 {$nodeId} 未找到");
        }

        if (!$node->isHealthy()) {
            throw new ParallelException("节点 {$nodeId} 不健康");
        }

        $result = $this->doSend($node, $payload);

        $node->updateHeartbeat();

        return $result;
    }

    public function broadcast(array $payload): array
    {
        $results = [];

        foreach ($this->getHealthyNodes() as $nodeId => $node) {
            try {
                $results[$nodeId] = $this->doSend($node, $payload);
            } catch (\Throwable $e) {
                $results[$nodeId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
                $node->setHealthy(false);
            }
        }

        return $results;
    }

    public function roundRobin(array $payload): array
    {
        $healthyNodes = $this->getHealthyNodes();

        if (empty($healthyNodes)) {
            throw new ParallelException('没有可用的健康节点');
        }

        $node = array_shift($healthyNodes);

        return $this->send($node->getId(), $payload);
    }

    public function sendWithRetry(string $nodeId, array $payload): array
    {
        $lastError = null;

        for ($i = 0; $i < $this->maxRetries; $i++) {
            try {
                return $this->send($nodeId, $payload);
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep(100000 * ($i + 1));
            }
        }

        throw new ParallelException(
            "发送失败，已重试 {$this->maxRetries} 次: " . $lastError?->getMessage()
        );
    }

    public function setTimeouts(float $connect, float $read): void
    {
        $this->connectTimeout = $connect;
        $this->readTimeout = $read;
    }

    public function setMaxRetries(int $retries): void
    {
        $this->maxRetries = $retries;
    }

    private function doSend(Node $node, array $payload): array
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new ParallelException('Socket 创建失败: ' . socket_strerror(socket_last_error()));
        }

        socket_set_nonblock($socket);

        try {
            $startTime = microtime(true);
            $connected = @socket_connect($socket, $node->getHost(), $node->getPort());

            if ($connected === false) {
                $error = socket_last_error($socket);

                if ($error !== SOCKET_EINPROGRESS && $error !== SOCKET_EALREADY) {
                    throw new ParallelException('连接失败: ' . socket_strerror($error));
                }

                $write = [$socket];
                $except = null;
                $selectResult = @socket_select($read, $write, $except, (int) $this->connectTimeout);

                if ($selectResult === 0) {
                    throw new ParallelException('连接超时');
                }

                if (socket_get_option($socket, SOL_SOCKET, SO_ERROR) !== 0) {
                    throw new ParallelException('连接错误');
                }
            }

            $data = json_encode([
                'type' => 'task',
                'payload' => $payload,
                'from' => $this->localNode?->getId(),
                'timestamp' => microtime(true),
            ], JSON_THROW_ON_ERROR);

            $message = $data . "\n";
            $written = @socket_write($socket, $message, strlen($message));

            if ($written === false) {
                throw new ParallelException('发送失败: ' . socket_strerror(socket_last_error($socket)));
            }

            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => (int) $this->readTimeout,
                'usec' => (int)(($this->readTimeout - (int)$this->readTimeout) * 1000000),
            ]);

            $response = '';
            while (true) {
                $chunk = @socket_read($socket, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $response .= $chunk;

                if (str_contains($response, "\n")) {
                    break;
                }
            }

            if ($response === '') {
                return [
                    'success' => true,
                    'node' => $node->getId(),
                    'response' => null,
                    'timestamp' => microtime(true) - $startTime,
                ];
            }

            $decoded = json_decode(trim($response), true);

            return [
                'success' => true,
                'node' => $node->getId(),
                'response' => $decoded,
                'timestamp' => microtime(true) - $startTime,
            ];
        } finally {
            socket_close($socket);
        }
    }

    public function receive(Node $node, float $timeout = 30.0): ?array
    {
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new ParallelException('Socket 创建失败');
        }

        try {
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => (int) $timeout,
                'usec' => (int)(($timeout - (int)$timeout) * 1000000),
            ]);

            $connected = @socket_connect($socket, $node->getHost(), $node->getPort());

            if ($connected === false) {
                throw new ParallelException('连接失败');
            }

            $data = '';
            while (true) {
                $chunk = @socket_read($socket, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $data .= $chunk;

                if (str_contains($data, "\n")) {
                    break;
                }
            }

            if ($data === '') {
                return null;
            }

            return json_decode(trim($data), true);
        } finally {
            socket_close($socket);
        }
    }
}
