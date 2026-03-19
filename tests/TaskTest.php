<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use PHPUnit\Framework\TestCase;
use Kode\Parallel\Task\Task;
use Kode\Parallel\Exception\ParallelException;

class TaskTest extends TestCase
{
    public function testTaskCreation(): void
    {
        $task = new Task(function () {
            return 42;
        });

        $this->assertInstanceOf(Task::class, $task);
        $this->assertNotNull($task->getClosure());
    }

    public function testTaskFromClosure(): void
    {
        $closure = function ($args) {
            return $args['value'] * 2;
        };

        $task = new Task($closure);
        $result = call_user_func($task->getClosure(), ['value' => 21]);

        $this->assertEquals(42, $result);
    }

    public function testTaskWithForbiddenYield(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('yield');

        $code = 'return function() { yield 1; };';
        $task = eval($code);
        new Task($task);
    }

    public function testTaskWithForbiddenReference(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('引用');

        $code = 'return function() use (&$ref) { return $ref; };';
        $ref = 1;
        $task = eval($code);
        new Task($task);
    }

    public function testTaskWithArrayData(): void
    {
        $task = new Task(function ($args) {
            $result = [];
            foreach ($args['items'] as $item) {
                $result[] = $item * $item;
            }
            return $result;
        });

        $closure = $task->getClosure();
        $result = $closure(['items' => [1, 2, 3, 4, 5]]);

        $this->assertEquals([1, 4, 9, 16, 25], $result);
    }
}
