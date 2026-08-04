<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Exception\ParallelException;
use Kode\Parallel\Task\Task;
use PHPUnit\Framework\TestCase;

/**
 * Task 封装与校验测试
 */
final class TaskTest extends TestCase
{
    public function testTaskCreation(): void
    {
        $task = new Task(function (array $args = []) {
            return 42;
        });

        $this->assertInstanceOf(Task::class, $task);
        $this->assertNotNull($task->getClosure());
        $this->assertTrue($task->isValidated());
        $this->assertTrue($task->isSourceChecked());
        $this->assertSame(__FILE__, $task->getFile());
    }

    public function testTaskFromClosure(): void
    {
        $task = Task::from(static fn(array $args): int => $args['value'] * 2);

        $this->assertSame(42, $task->execute(['value' => 21]));
    }

    public function testTaskWithForbiddenYield(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('yield');

        /** @var \Closure $closure */
        $closure = eval('return function() { yield 1; };');
        new Task($closure);
    }

    public function testGeneratorDetectionWorksForInlineClosure(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('yield');

        new Task(function (array $args = []) {
            yield 1;
        });
    }

    public function testTaskWithForbiddenReference(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('引用');

        $ref = 1;
        new Task(function () use (&$ref) { return $ref; });
    }

    public function testTaskWithForbiddenClassDeclaration(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('禁止声明类');

        new Task(function (array $args = []) {
            $code = 'class Foo {}';

            return $code;
        });
    }

    public function testUncheckedTaskSkipsValidation(): void
    {
        $ref = 1;
        $task = Task::unchecked(function () use (&$ref) { return $ref; });

        $this->assertFalse($task->isValidated());
        $this->assertFalse($task->isSourceChecked());
        $this->assertSame(1, $task->execute());
    }

    public function testEvalClosureSkipsSourceCheckWithoutError(): void
    {
        /** @var \Closure $closure */
        $closure = eval('return function(array $args = []) { return ($args["n"] ?? 0) + 1; };');
        $task = new Task($closure);

        $this->assertFalse($task->isSourceChecked(), 'eval 闭包源码不可读，应跳过源码校验而非报错');
        $this->assertNull($task->getFile());
        $this->assertSame(3, $task->execute(['n' => 2]));
    }

    public function testTaskWithArrayData(): void
    {
        $task = new Task(function (array $args): array {
            $result = [];

            foreach ($args['items'] as $item) {
                $result[] = $item * $item;
            }

            return $result;
        });

        $this->assertSame([1, 4, 9, 16, 25], $task->execute(['items' => [1, 2, 3, 4, 5]]));
    }

    public function testFromFileRejectsMissingFile(): void
    {
        $this->expectException(ParallelException::class);
        $this->expectExceptionMessage('不存在');

        Task::fromFile('/tmp/kode-parallel-missing-' . bin2hex(random_bytes(4)) . '.php');
    }

    public function testFromFileBuildsRunnableTask(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kode_task_');
        $this->assertIsString($path);
        file_put_contents($path, "return 1 + 1;\n");

        try {
            $task = Task::fromFile($path);

            $this->assertSame(2, $task->execute());
            $this->assertSame($path, $task->getFile());
            $this->assertSame(1, $task->getLine());
        } finally {
            @unlink($path);
        }
    }
}
