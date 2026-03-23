<?php

declare(strict_types=1);

namespace Kode\Parallel\Fibers;

/**
 * Fiber 协调器
 *
 * 整合 kode/fibers 包的增强功能
 * 当 kode/fibers 可用时使用其高级功能，否则使用原生 Fiber
 */
final class FiberCoordinator
{
    private static bool $useKodeFibers = false;

    public static function isKodeFibersAvailable(): bool
    {
        return class_exists(\Kode\Fibers\Fibers::class);
    }

    public static function enableKodeFibers(): void
    {
        if (self::isKodeFibersAvailable()) {
            self::$useKodeFibers = true;
        }
    }

    public static function disableKodeFibers(): void
    {
        self::$useKodeFibers = false;
    }

    public static function shouldUseKodeFibers(): bool
    {
        return self::$useKodeFibers && self::isKodeFibersAvailable();
    }

    public static function run(callable $task, ?float $timeout = null): mixed
    {
        if (self::shouldUseKodeFibers()) {
            return \Kode\Fibers\Fibers::run($task, $timeout);
        }

        $fiber = new \Kode\Parallel\Fiber\Fiber($task);
        $fiber->start();

        if ($timeout !== null) {
            $startTime = microtime(true);

            while (!$fiber->isComplete() && (microtime(true) - $startTime) < $timeout) {
                usleep(1000);
            }

            if (!$fiber->isComplete()) {
                return null;
            }
        }

        return $fiber->getReturnValue();
    }

    public static function concurrent(array $tasks, ?float $timeout = null): array
    {
        if (self::shouldUseKodeFibers()) {
            return \Kode\Fibers\Fibers::concurrent($tasks, $timeout);
        }

        $futures = [];

        foreach ($tasks as $key => $task) {
            $fiber = new \Kode\Parallel\Fiber\Fiber($task);
            $fiber->start();
            $futures[$key] = $fiber;
        }

        $results = [];

        foreach ($futures as $key => $fiber) {
            if ($timeout !== null) {
                $startTime = microtime(true);

                while (!$fiber->isComplete() && (microtime(true) - $startTime) < $timeout) {
                    usleep(1000);
                }
            }

            $results[$key] = $fiber->isComplete() ? $fiber->getReturnValue() : null;
        }

        return $results;
    }

    public static function go(callable $task, ?float $timeout = null): mixed
    {
        return self::run($task, $timeout);
    }

    public static function sleep(float $seconds): void
    {
        if (self::shouldUseKodeFibers()) {
            \Kode\Fibers\Fibers::sleep($seconds);
            return;
        }

        usleep((int)($seconds * 1_000_000));
    }

    public static function retry(callable $task, int $maxRetries = 3, float $retryDelay = 0.5): mixed
    {
        if (self::shouldUseKodeFibers()) {
            return \Kode\Fibers\Fibers::retry($task, $maxRetries, $retryDelay);
        }

        $lastException = null;

        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                return self::run($task);
            } catch (\Throwable $e) {
                $lastException = $e;

                if ($i < $maxRetries - 1) {
                    usleep((int)($retryDelay * 1_000_000));
                }
            }
        }

        throw $lastException;
    }

    public static function withTimeout(callable $task, float $timeout): mixed
    {
        return self::run($task, $timeout);
    }
}
