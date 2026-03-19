<?php

declare(strict_types=1);

namespace Kode\Parallel\Exception;

use RuntimeException;

class ParallelException extends RuntimeException
{
    protected array $context = [];

    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}
