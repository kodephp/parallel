<?php

/**
 * PHPStan 专用符号桩：parallel 为 PECL 可选扩展，无法经 composer 提供存根。
 * 按本包实际调用面声明最小 API，真实实现以运行期 extension_loaded('parallel') 为准。
 */

namespace parallel {

    class Pipe
    {
        public function __construct(string $name)
        {
        }

        public function write(string $data, int $timeout = 0): bool
        {
        }

        public function read(int $length = 0, int $timeout = 0): ?string
        {
        }

        public function isReadable(): bool
        {
        }

        public function isWritable(): bool
        {
        }

        public function close(): void
        {
        }
    }

    class Runtime
    {
        public function __construct(?string $bootstrap = null)
        {
        }

        /** @param array<mixed> $args */
        public function run(callable $task, array $args = []): Future
        {
        }

        public function close(): void
        {
        }
    }

    class Future
    {
        public function done(): bool
        {
        }

        public function value(): mixed
        {
        }

        public function cancel(): bool
        {
        }

        public function wait(?float $timeout = null): bool
        {
        }
    }
}

namespace parallel\Future {

    class Error extends \Exception
    {
    }
}
