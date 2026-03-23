<?php

declare(strict_types=1);

namespace Kode\Parallel\Context;

use Kode\Context\Context;
use Kode\Parallel\Runtime\Runtime;

/**
 * 上下文感知的运行时包装器
 *
 * 为 kode/parallel 提供分布式追踪支持
 */
final class ContextualRuntime
{
    private Runtime $runtime;
    private ?string $traceId = null;
    private ?string $spanId = null;
    private ?string $nodeId = null;

    public function __construct(Runtime $runtime, ?string $nodeId = null)
    {
        $this->runtime = $runtime;
        $this->nodeId = $nodeId ?? gethostname() . ':' . getmypid();

        $this->initializeTracing();
    }

    private function initializeTracing(): void
    {
        $this->traceId = $this->generateTraceId();
        $this->spanId = $this->generateSpanId();
    }

    public function run(callable $task, array $args = []): \Kode\Parallel\Future\Future
    {
        $context = $this->captureContext();

        $wrappedTask = function($taskArgs) use ($task, $context) {
            $this->propagateContext($context);

            return $task($taskArgs);
        };

        return $this->runtime->run($wrappedTask, $args);
    }

    public function captureContext(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->generateSpanId(),
            'node_id' => $this->nodeId,
            'timestamp' => microtime(true),
            'process_id' => getmypid(),
            'context_data' => $this->getContextData(),
        ];
    }

    public function propagateContext(array $context): void
    {
        $this->traceId = $context['trace_id'] ?? $this->generateTraceId();
        $this->spanId = $context['span_id'] ?? $this->generateSpanId();

        if (isset($context['context_data']) && is_array($context['context_data'])) {
            foreach ($context['context_data'] as $key => $value) {
                Context::set($key, $value);
            }
        }
    }

    private function getContextData(): array
    {
        return [
            Context::TRACE_ID => $this->traceId,
            Context::SPAN_ID => $this->spanId,
            Context::NODE_ID => $this->nodeId,
            Context::PROCESS_ID => getmypid(),
            Context::REQUEST_ID => $this->generateRequestId(),
        ];
    }

    public function createChildSpan(): string
    {
        return $this->generateSpanId();
    }

    public function getTraceId(): ?string
    {
        return $this->traceId;
    }

    public function getSpanId(): ?string
    {
        return $this->spanId;
    }

    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'node_id' => $this->nodeId,
        ];
    }
}
