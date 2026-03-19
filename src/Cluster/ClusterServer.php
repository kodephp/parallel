<?php

declare(strict_types=1);

namespace Kode\Parallel\Cluster;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Runtime\Runtime;

class ClusterServer
{
    private ?Node $node = null;
    private ?Runtime $runtime = null;
    private $serverSocket = null;
    private bool $running = false;
    private array $clients = [];
    private float $heartbeatInterval = 5.0;
    private array $tasks = [];

    public function __construct(Node $node, ?Runtime $runtime = null)
    {
        $this->node = $node;
        $this->runtime = $runtime;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->serverSocket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($this->serverSocket === false) {
            throw new ParallelException('Server socket 创建失败: ' . socket_strerror(socket_last_error()));
        }

        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!@socket_bind($this->serverSocket, $this->node->getHost(), $this->node->getPort())) {
            $error = socket_last_error($this->serverSocket);
            throw new ParallelException('绑定失败: ' . socket_strerror($error));
        }

        if (!socket_listen($this->serverSocket, 128)) {
            throw new ParallelException('监听失败: ' . socket_strerror(socket_last_error($this->serverSocket)));
        }

        socket_set_nonblock($this->serverSocket);

        $this->running = true;

        $this->acceptLoop();
    }

    public function stop(): void
    {
        $this->running = false;

        if ($this->serverSocket !== null) {
            socket_close($this->serverSocket);
            $this->serverSocket = null;
        }

        foreach ($this->clients as $client) {
            socket_close($client);
        }

        $this->clients = [];
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    private function acceptLoop(): void
    {
        while ($this->running) {
            $read = [$this->serverSocket];
            $write = null;
            $except = null;

            $selected = @socket_select($read, $write, $except, 1);

            if ($selected === false) {
                break;
            }

            if ($selected > 0) {
                $client = @socket_accept($this->serverSocket);

                if ($client !== false) {
                    $this->handleClient($client);
                }
            }

            $this->processCompletedTasks();
        }
    }

    private function handleClient($client): void
    {
        socket_set_nonblock($client);

        $this->clients[(int) $client] = $client;

        $buffer = '';

        while ($this->running) {
            $data = @socket_read($client, 8192);

            if ($data === false || $data === '') {
                break;
            }

            $buffer .= $data;

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                if ($line === '') {
                    continue;
                }

                $this->processMessage($client, $line);
            }
        }

        unset($this->clients[(int) $client]);
        @socket_close($client);
    }

    private function processMessage($client, string $message): void
    {
        try {
            $packet = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->sendResponse($client, ['error' => 'Invalid JSON']);
            return;
        }

        $type = $packet['type'] ?? 'unknown';

        switch ($type) {
            case 'task':
                $this->handleTask($client, $packet);
                break;

            case 'broadcast':
                $this->handleBroadcast($client, $packet);
                break;

            case 'ping':
                $this->sendResponse($client, ['type' => 'pong', 'timestamp' => microtime(true)]);
                break;

            case 'heartbeat':
                $this->handleHeartbeat($client, $packet);
                break;

            default:
                $this->sendResponse($client, ['error' => "Unknown type: {$type}"]);
        }
    }

    private function handleTask($client, array $packet): void
    {
        $payload = $packet['payload'] ?? [];

        $taskId = $payload['task_id'] ?? uniqid('task_', true);
        $serializedTask = $payload['task'] ?? null;
        $args = $payload['args'] ?? [];

        if ($serializedTask === null) {
            $this->sendResponse($client, [
                'type' => 'task_result',
                'task_id' => $taskId,
                'success' => false,
                'error' => 'No task provided',
            ]);
            return;
        }

        try {
            $task = unserialize($serializedTask);

            if ($this->runtime !== null) {
                $future = $this->runtime->run($task, $args);

                $this->tasks[$taskId] = [
                    'client' => $client,
                    'future' => $future,
                    'start_time' => microtime(true),
                ];
            } else {
                $startTime = microtime(true);
                $result = $task($args);

                $this->sendResponse($client, [
                    'type' => 'task_result',
                    'task_id' => $taskId,
                    'success' => true,
                    'result' => $result,
                    'duration' => microtime(true) - $startTime,
                ]);
            }
        } catch (\Throwable $e) {
            $this->sendResponse($client, [
                'type' => 'task_result',
                'task_id' => $taskId,
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleBroadcast($client, array $packet): void
    {
        $payload = $packet['payload'] ?? [];
        $serializedTask = $payload['task'] ?? null;
        $args = $payload['args'] ?? [];

        if ($serializedTask === null) {
            $this->sendResponse($client, [
                'type' => 'broadcast_result',
                'success' => false,
                'error' => 'No task provided',
            ]);
            return;
        }

        try {
            $task = unserialize($serializedTask);
            $startTime = microtime(true);
            $result = $task($args);

            $this->sendResponse($client, [
                'type' => 'broadcast_result',
                'success' => true,
                'result' => $result,
                'duration' => microtime(true) - $startTime,
            ]);
        } catch (\Throwable $e) {
            $this->sendResponse($client, [
                'type' => 'broadcast_result',
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleHeartbeat($client, array $packet): void
    {
        $this->node->updateHeartbeat();

        $this->sendResponse($client, [
            'type' => 'heartbeat_ack',
            'timestamp' => microtime(true),
            'node_id' => $this->node->getId(),
            'load' => sys_getloadavg()[0] ?? 0,
            'memory_usage' => memory_get_usage(true),
        ]);
    }

    private function processCompletedTasks(): void
    {
        foreach ($this->tasks as $taskId => $info) {
            $future = $info['future'];

            if ($future->isComplete()) {
                try {
                    $result = $future->get();

                    $this->sendResponse($info['client'], [
                        'type' => 'task_result',
                        'task_id' => $taskId,
                        'success' => true,
                        'result' => $result,
                        'duration' => microtime(true) - $info['start_time'],
                    ]);
                } catch (\Throwable $e) {
                    $this->sendResponse($info['client'], [
                        'type' => 'task_result',
                        'task_id' => $taskId,
                        'success' => false,
                        'error' => $e->getMessage(),
                        'duration' => microtime(true) - $info['start_time'],
                    ]);
                }

                unset($this->tasks[$taskId]);
            }
        }
    }

    private function sendResponse($client, array $data): void
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR) . "\n";
        @socket_write($client, $json, strlen($json));
    }

    public function setHeartbeatInterval(float $interval): void
    {
        $this->heartbeatInterval = $interval;
    }

    public function getActiveTaskCount(): int
    {
        return count($this->tasks);
    }

    public function getClientCount(): int
    {
        return count($this->clients);
    }
}
