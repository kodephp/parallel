<?php

declare(strict_types=1);

namespace Kode\Parallel\Task;

use Kode\Parallel\Exception\ParallelException;

/**
 * Task 任务类
 *
 * Task 只是用于并行执行的 Closure。Closure 几乎可以包含任何指令，包含嵌套闭包。
 * 但是在 task 中禁止使用一些指令：
 * - yield
 * - 使用引用
 * - 声明类
 * - 声明命名函数
 *
 * 注意：嵌套闭包可以 yield 或使用引用，但不得包含类声明或命名函数声明。
 */
final class Task
{
    private \Closure $closure;
    private ?string $file = null;
    private ?int $line = null;

    private const FORBIDDEN_PATTERNS = [
        'yield' => '/\b(yield)\b/',
        'reference' => '/\b(use\s+&)\b/',
        'class' => '/\b(class|interface|trait)\s+\w+\b/',
        'function' => '/\b(function\s+\w+\s*\()/',
    ];

    public function __construct(\Closure $closure)
    {
        $this->closure = $closure;
        $this->validate();
    }

    /**
     * 获取任务闭包
     */
    public function getClosure(): \Closure
    {
        return $this->closure;
    }

    /**
     * 从闭包创建 Task
     *
     * @param \Closure $closure 任务闭包
     */
    public static function from(\Closure $closure): static
    {
        return new static($closure);
    }

    /**
     * 从文件创建任务
     *
     * @param string $file 文件路径
     * @param int|null $startLine 开始行号
     * @param int $endLine 结束行号（0 表示文件末尾）
     */
    public static function fromFile(string $file, ?int $startLine = null, int $endLine = 0): static
    {
        if (!file_exists($file)) {
            throw new ParallelException("Task 文件不存在: {$file}");
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        $start = ($startLine ?? 1) - 1;
        $end = $endLine > 0 ? $endLine : $totalLines;
        $length = $end - $start;

        if ($start < 0 || $end > $totalLines || $start >= $end) {
            throw new ParallelException("无效的行号范围: {$startLine}-{$endLine}");
        }

        $taskCode = implode("\n", array_slice($lines, $start, $length));
        $task = eval('return function() { ' . $taskCode . ' };');

        $instance = new static($task);
        $instance->file = $file;
        $instance->line = $start + 1;

        return $instance;
    }

    /**
     * 验证闭包是否包含禁止的指令
     *
     * @throws ParallelException 如果包含禁止指令
     */
    private function validate(): void
    {
        $reflection = new \ReflectionFunction($this->closure);

        if (!$reflection->getFileName()) {
            return;
        }

        $this->file = $reflection->getFileName();
        $this->line = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $sourceCode = file_get_contents($this->file);
        $lines = explode("\n", $sourceCode);
        $functionCode = implode("\n", array_slice($lines, $this->line - 1, $endLine - $this->line + 1));

        if (preg_match(self::FORBIDDEN_PATTERNS['yield'], $functionCode)) {
            throw new ParallelException('Task 中禁止使用 yield 指令');
        }

        if (preg_match(self::FORBIDDEN_PATTERNS['reference'], $functionCode)) {
            throw new ParallelException('Task 中禁止使用引用传递');
        }

        if (preg_match(self::FORBIDDEN_PATTERNS['class'], $functionCode)) {
            throw new ParallelException('Task 中禁止声明类');
        }

        if (preg_match(self::FORBIDDEN_PATTERNS['function'], $functionCode)) {
            throw new ParallelException('Task 中禁止声明命名函数');
        }
    }

    /**
     * 获取任务所在文件
     */
    public function getFile(): ?string
    {
        return $this->file;
    }

    /**
     * 获取任务开始行号
     */
    public function getLine(): ?int
    {
        return $this->line;
    }

    /**
     * 执行任务（本地执行，非并行）
     *
     * @param array<string, mixed> $args 任务参数
     * @return mixed 任务返回值
     */
    public function execute(array $args = []): mixed
    {
        return call_user_func($this->closure, $args);
    }
}
