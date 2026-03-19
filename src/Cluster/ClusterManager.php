<?php

declare(strict_types=1);

namespace Kode\Parallel\Cluster;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Network\Node;
use Kode\Parallel\Network\TcpNodeTransport;
use Kode\Parallel\Runtime\Runtime;

class ClusterManager
{
    private TcpNodeTransport $transport;
    private ?Runtime $runtime = null;
    private ?Node $masterNode = null;
    private bool $isMaster = false;
    private array $localTasks = [];
    private float $heartbeatInterval = 5.0;
    private float $nodeTimeout = 30.0;

    public function __construct(?Runtime $runtime = null)
    {
        $this->transport = new TcpNodeTransport();
        $this->runtime = $runtime;
    }

    public function setLocalNode(Node $node): void
    {
        $this->transport->setLocalNode($node);
    }

    public function getLocalNode(): ?Node
    {
        return $this->transport->getLocalNode();
    }

    public function addNode(Node $node): void
    {
        $this->transport->registerNode($node);
    }

    public function removeNode(string $nodeId): void
    {
        $this->transport->unregisterNode($nodeId);
    }

    public function getNodes(): array
    {
        return $this->transport->getNodes();
    }

    public function getHealthyNodes(): array
    {
        return $this->transport->getHealthyNodes();
    }

    public function setAsMaster(): void
    {
        $this->isMaster = true;

        if ($this->masterNode === null && $this->transport->getLocalNode() !== null) {
            $this->masterNode = $this->transport->getLocalNode();
        }
    }

    public function isMaster(): bool
    {
        return $this->isMaster;
    }

    public function getMasterNode(): ?Node
    {
        return $this->masterNode;
    }

    public function electMaster(): ?Node
    {
        $healthyNodes = $this->getHealthyNodes();

        if (empty($healthyNodes)) {
            return null;
        }

        usort($healthyNodes, function(Node $a, Node $b) {
            $aLoad = $a->getMetadata()['load'] ?? 0;
            $bLoad = $b->getMetadata()['load'] ?? 0;

            if ($aLoad !== $bLoad) {
                return $aLoad <=> $bLoad;
            }

            return strcmp($a->getId(), $b->getId());
        });

        $this->masterNode = $healthyNodes[0];
        $this->isMaster = ($this->masterNode->getId() === $this->transport->getLocalNode()?->getId());

        return $this->masterNode;
    }

    public function submitTask(string $taskId, callable $task, array $args = []): array
    {
        if ($this->isMaster && $this->runtime !== null) {
            return $this->submitLocal($taskId, $task, $args);
        }

        $healthyNodes = $this->getHealthyNodes();

        if (empty($healthyNodes)) {
            if ($this->runtime !== null) {
                return $this->submitLocal($taskId, $task, $args);
            }

            throw new ParallelException('没有可用的节点执行任务');
        }

        $targetNode = $this->selectNode($healthyNodes);

        return $this->submitRemote($targetNode, $taskId, $task, $args);
    }

    public function submitLocal(string $taskId, callable $task, array $args = []): array
    {
        if ($this->runtime === null) {
            throw new ParallelException('本地 Runtime 未设置');
        }

        $startTime = microtime(true);

        $future = $this->runtime->run($task, $args);

        try {
            $result = $future->get();

            return [
                'task_id' => $taskId,
                'node' => $this->transport->getLocalNode()?->getId(),
                'success' => true,
                'result' => $result,
                'duration' => microtime(true) - $startTime,
            ];
        } catch (\Throwable $e) {
            return [
                'task_id' => $taskId,
                'node' => $this->transport->getLocalNode()?->getId(),
                'success' => false,
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $startTime,
            ];
        }
    }

    public function submitRemote(Node $node, string $taskId, callable $task, array $args = []): array
    {
        $startTime = microtime(true);

        $payload = [
            'task_id' => $taskId,
            'task' => serialize($task),
            'args' => $args,
            'timestamp' => $startTime,
        ];

        try {
            $response = $this->transport->sendWithRetry($node->getId(), $payload);

            if ($response['success']) {
                return [
                    'task_id' => $taskId,
                    'node' => $node->getId(),
                    'success' => true,
                    'result' => $response['response']['result'] ?? null,
                    'duration' => microtime(true) - $startTime,
                ];
            }

            return [
                'task_id' => $taskId,
                'node' => $node->getId(),
                'success' => false,
                'error' => $response['error'] ?? 'Unknown error',
                'duration' => microtime(true) - $startTime,
            ];
        } catch (\Throwable $e) {
            return [
                'task_id' => $taskId,
                'node' => $node->getId(),
                'success' => false,
                'error' => $e->getMessage(),
                'duration' => microtime(true) - $startTime,
            ];
        }
    }

    public function submitToAll(callable $task, array $args = []): array
    {
        $results = [];
        $healthyNodes = $this->getHealthyNodes();

        foreach ($healthyNodes as $node) {
            $taskId = uniqid('task_', true);
            $results[$node->getId()] = $this->submitRemote($node, $taskId, $task, $args);
        }

        return $results;
    }

    public function broadcast(callable $task, array $args = []): array
    {
        $results = [];

        if ($this->isMaster && $this->runtime !== null) {
            $taskId = uniqid('broadcast_', true);
            $results[$this->transport->getLocalNode()?->getId() ?? 'local'] = $this->submitLocal($taskId, $task, $args);
        }

        $responses = $this->transport->broadcast([
            'type' => 'broadcast',
            'task' => serialize($task),
            'args' => $args,
            'timestamp' => microtime(true),
        ]);

        foreach ($responses as $nodeId => $response) {
            if (isset($response['success']) && $response['success']) {
                $results[$nodeId] = $response;
            }
        }

        return $results;
    }

    public function healthCheck(): array
    {
        $status = [];

        foreach ($this->transport->getNodes() as $node) {
            $nodeId = $node->getId();
            $lastHeartbeat = $node->getLastHeartbeat();
            $timeSinceHeartbeat = microtime(true) - $lastHeartbeat;

            $isHealthy = $timeSinceHeartbeat < $this->nodeTimeout && $node->isHealthy();

            $status[$nodeId] = [
                'node' => $node->toArray(),
                'healthy' => $isHealthy,
                'time_since_heartbeat' => $timeSinceHeartbeat,
            ];

            if (!$isHealthy) {
                $node->setHealthy(false);
            }
        }

        return $status;
    }

    public function setHeartbeatInterval(float $interval): void
    {
        $this->heartbeatInterval = $interval;
    }

    public function setNodeTimeout(float $timeout): void
    {
        $this->nodeTimeout = $timeout;
    }

    public function getTransport(): TcpNodeTransport
    {
        return $this->transport;
    }

    private function selectNode(array $nodes): Node
    {
        if (empty($nodes)) {
            throw new ParallelException('没有可用的节点');
        }

        $loads = [];

        foreach ($nodes as $node) {
            $load = $node->getMetadata()['load'] ?? 0;
            $capacity = $node->getMetadata()['capacity'] ?? 100;
            $loads[$node->getId()] = $capacity > 0 ? $load / $capacity : 1;
        }

        asort($loads);

        $selectedId = array_key_first($loads);

        return $nodes[$selectedId];
    }
}
