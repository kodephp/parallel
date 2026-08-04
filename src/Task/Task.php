<?php

declare(strict_types=1);

namespace Kode\Parallel\Task;

use Kode\Parallel\Exception\ParallelException;

/**
 * Task 任务封装
 *
 * Task 是用于并行执行的 Closure。在 ext-parallel（真线程）下，任务体内禁止：
 * - yield（生成器）
 * - 引用传递 use (&$var)
 * - 声明类 / 接口 / trait / enum
 * - 声明命名函数
 *
 * process / sync 引擎没有上述限制，可通过 Task::unchecked() 跳过校验。
 *
 * 校验策略（1.6.0 起）：
 * 1. 生成器通过反射精确判定，eval 产生的闭包同样有效；
 * 2. 其余限制基于源码扫描，源码不可读（eval、内置函数）时自动跳过并标记；
 * 3. 校验失败抛出 ParallelException，绝不再因源码读取失败抛 TypeError。
 */
final class Task
{
    /** 引用传递：use (&$var) */
    private const string PATTERN_REFERENCE = '/\buse\s*\([^)]*&\s*\$/i';

    /** 类型声明：class/interface/trait/enum Foo */
    private const string PATTERN_TYPE_DECLARATION = '/\b(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+[A-Za-z_]\w*/i';

    /** 命名函数声明 */
    private const string PATTERN_NAMED_FUNCTION = '/\bfunction\s+[A-Za-z_]\w*\s*\(/i';

    private ?string $file = null;
    private ?int $line = null;
    private bool $sourceChecked = false;

    /**
     * @param \Closure $closure 任务闭包，签名建议为 fn(array $args): mixed
     * @param bool $validate 是否执行 ext-parallel 限制校验
     */
    public function __construct(
        private readonly \Closure $closure,
        private readonly bool $validate = true,
    ) {
        $this->inspect();
    }

    /**
     * 获取任务闭包
     */
    public function getClosure(): \Closure
    {
        return $this->closure;
    }

    /**
     * 从闭包创建 Task（带校验）
     */
    public static function from(\Closure $closure): self
    {
        return new self($closure);
    }

    /**
     * 从闭包创建 Task（跳过 ext-parallel 限制校验）
     *
     * 适用于确定运行在 process / sync 引擎的任务。
     */
    public static function unchecked(\Closure $closure): self
    {
        return new self($closure, false);
    }

    /**
     * 从文件片段创建任务
     *
     * @param string $file 文件路径
     * @param int|null $startLine 起始行号（从 1 开始）
     * @param int $endLine 结束行号，0 表示到文件末尾
     */
    public static function fromFile(string $file, ?int $startLine = null, int $endLine = 0): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new ParallelException("Task 文件不存在或不可读: {$file}");
        }

        $content = file_get_contents($file);

        if ($content === false) {
            throw new ParallelException("Task 文件读取失败: {$file}");
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        $start = ($startLine ?? 1) - 1;
        $end = $endLine > 0 ? $endLine : $totalLines;

        if ($start < 0 || $end > $totalLines || $start >= $end) {
            throw new ParallelException("无效的行号范围: {$startLine}-{$endLine}");
        }

        $taskCode = implode("\n", array_slice($lines, $start, $end - $start));

        self::assertSource($taskCode);

        /** @var \Closure|false $task */
        $task = @eval('return function(array $args = []) { ' . $taskCode . ' };');

        if (!$task instanceof \Closure) {
            throw new ParallelException("Task 代码片段无法编译: {$file}:{$startLine}");
        }

        $instance = new self($task);
        $instance->file = $file;
        $instance->line = $start + 1;

        return $instance;
    }

    /**
     * 任务所在文件（eval 产生的闭包为 null）
     */
    public function getFile(): ?string
    {
        return $this->file;
    }

    /**
     * 任务起始行号
     */
    public function getLine(): ?int
    {
        return $this->line;
    }

    /**
     * 是否已完成源码级校验（源码不可读时为 false）
     */
    public function isSourceChecked(): bool
    {
        return $this->sourceChecked;
    }

    /**
     * 是否开启了校验
     */
    public function isValidated(): bool
    {
        return $this->validate;
    }

    /**
     * 本地执行任务（不并行，便于调试与单测）
     *
     * @param array<array-key, mixed> $args
     */
    public function execute(array $args = []): mixed
    {
        return ($this->closure)($args);
    }

    /**
     * 反射解析并按需校验
     */
    private function inspect(): void
    {
        $reflection = new \ReflectionFunction($this->closure);

        if ($this->validate && $reflection->isGenerator()) {
            throw new ParallelException('Task 中禁止使用 yield 指令（生成器闭包无法跨线程传递）');
        }

        $file = $reflection->getFileName();

        // eval() 产生的闭包文件名形如 "/path/file.php(42) : eval()'d code"，不可读
        if ($file === false || !is_file($file) || !is_readable($file)) {
            return;
        }

        $this->file = $file;
        $this->line = $reflection->getStartLine() ?: null;

        if (!$this->validate) {
            return;
        }

        $source = self::readLines($file, $reflection->getStartLine(), $reflection->getEndLine());

        if ($source === null) {
            return;
        }

        self::assertSource($source);
        $this->sourceChecked = true;
    }

    /**
     * 源码级限制校验
     *
     * @throws ParallelException 命中禁止指令
     */
    private static function assertSource(string $source): void
    {
        if (preg_match(self::PATTERN_REFERENCE, $source) === 1) {
            throw new ParallelException('Task 中禁止使用引用传递 use (&$var)');
        }

        if (preg_match(self::PATTERN_TYPE_DECLARATION, $source) === 1) {
            throw new ParallelException('Task 中禁止声明类 / 接口 / trait / enum');
        }

        if (preg_match(self::PATTERN_NAMED_FUNCTION, $source) === 1) {
            throw new ParallelException('Task 中禁止声明命名函数');
        }
    }

    /**
     * 读取闭包对应的源码片段
     */
    private static function readLines(string $file, int|false $startLine, int|false $endLine): ?string
    {
        if ($startLine === false || $endLine === false || $endLine < $startLine) {
            return null;
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            return null;
        }

        $lines = explode("\n", $content);
        $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);

        return $slice === [] ? null : implode("\n", $slice);
    }
}
