# Kode/Parallel 项目规则

## 项目信息

- **包名**: kode/parallel
- **类型**: PHP Composer 库
- **许可证**: Apache-2.0
- **PHP 版本**: >= 8.1
- **依赖扩展**: ext-parallel

## 代码规范

### PSR 标准

- 遵循 [PSR-4](https://www.php-fig.org/psr/psr-4/) 自动加载规范
- 遵循 [PSR-12](https://www.php-fig.org/psr/psr-12/) 代码风格规范

### 命名规范

- 命名空间: `Kode\Parallel`
- 类名: 大驼峰 (PascalCase)
- 方法名: 小驼峰 (camelCase)
- 常量: 全大写下划线分隔 (UPPER_SNAKE_CASE)
- 文件名: 与类名一致

### 代码质量要求

1. **类型声明**
   - 所有函数和方法必须有严格的类型声明
   - 使用 `declare(strict_types=1);`

2. **文档注释**
   - 所有公共 API 必须有中文文档注释
   - 使用 PHPDoc 格式

3. **错误处理**
   - 使用自定义异常类 `Kode\Parallel\Exception\ParallelException`
   - 捕获并转换底层扩展异常

## 目录结构

```
kode/parallel/
├── src/
│   ├── Channel/          # Channel 通道类
│   ├── Events/           # Events 事件循环类
│   ├── Exception/        # 异常类
│   ├── Future/           # Future 未来对象类
│   ├── Runtime/          # Runtime 运行时类
│   ├── Task/             # Task 任务类
│   └── functions.php     # 快捷函数
├── tests/                # 单元测试
├── .gitignore
├── .travis.yml
├── composer.json
├── LICENSE
└── README.md
```

## 依赖关系

### 必需依赖

- `ext-parallel`: PHP 并行扩展
- `kode/context`: 请求上下文传递
- `kode/facade`: 门面模式支持

### 开发依赖

- `phpunit/phpunit`: 单元测试框架

## 任务执行规则

### Task 限制

Task 中禁止使用以下指令:
- `yield`: 禁止在 Task 中使用生成器
- 引用传递: 禁止使用 `use &$var`
- 类声明: 禁止在 Task 中声明类
- 命名函数: 禁止在 Task 中声明命名函数

嵌套闭包可以 yield 或使用引用，但不得包含类声明或命名函数声明。

### Runtime 使用

- Runtime 采用 FIFO 调度策略
- 任务按调度顺序执行
- 可选引导文件用于预加载配置

### Channel 通信

- 无界限通道: 容量无限制
- 有界限通道: 指定容量，满了会阻塞

### Events 事件循环

- 支持 Future 和 Channel 事件监听
- 支持非阻塞和阻塞两种模式
- 可设置输入数据到指定通道

## 测试要求

- 所有核心类必须有对应单元测试
- 测试覆盖率目标: >= 80%
- 使用 PHPUnit 测试框架

## 发布要求

1. 版本号遵循语义化版本 (SemVer)
2. 更新 `composer.json` 中的版本号
3. 更新 CHANGELOG
4. 创建 Git tag

## 相关链接

- [PHP parallel 扩展文档](https://www.php.net/manual/zh/book.parallel.php)
- [KodePHP 官方仓库](https://github.com/kodephp)
