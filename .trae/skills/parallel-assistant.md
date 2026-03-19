---
name: "parallel-assistant"
description: "Provides Kode/Parallel package development guidance. Invoke when user asks about parallel package usage or development."
---

# Kode/Parallel Assistant

This skill provides guidance for using and developing the kode/parallel package.

## Package Overview

`kode/parallel` is a high-performance PHP parallel concurrency library based on `ext-parallel`.

## Core Components

- **Runtime**: PHP interpreter thread management
- **Task**: Parallel task closure wrapper
- **Future**: Async task result access
- **Channel**: Bidirectional communication between tasks
- **Events**: Event loop driver
- **Fiber**: PHP Fiber coroutine wrapper (PHP 8.1+)

## Usage Examples

### Basic Task Execution

```php
use function Kode\Parallel\run;

$future = run(fn($args) => $args['a'] + $args['b'], ['a' => 10, 'b' => 20]);
echo $future->get();
```

### Using Runtime

```php
use Kode\Parallel\Runtime\Runtime;

$runtime = new Runtime();
$future = $runtime->run(fn() => expensiveComputation());
$result = $future->get();
$runtime->close();
```

## Task Restrictions

Tasks cannot use:
- yield
- Reference passing (use &$var)
- Class declarations
- Named function declarations

## Performance Tips

1. Use appropriate task granularity
2. Use Channels for large data transfer
3. Warm up Runtime for critical paths
4. Use bounded Channels to control memory
