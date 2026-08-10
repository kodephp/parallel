<?php

declare(strict_types=1);

namespace Kode\Parallel\Curl;

use Kode\Parallel\Exception\ParallelException;

/**
 * CurlMulti 并行 Curl 请求封装
 *
 * 基于 libcurl 的 curl_multi 事件循环，在**单一进程内**并发执行多个 HTTP 请求，
 * 自动复用 TCP 连接（keep-alive），是 I/O 密集型 HTTP 扇出（如多用户群发回调、
 * 批量抓取、聚合多个第三方 API）的最优解——无需线程/进程开销，内存占用极低。
 *
 * 与 parallel 引擎的线程模式对比：
 * - 纯 I/O（等网络）：curl_multi 最快、最省资源；线程模式每个请求要独立建连 + 序列化开销
 * - 需边请求边做 CPU 计算：用 parallel 引擎的 {@see \Kode\Parallel\Pool\WorkerPool::mapBatch()}
 *   把「请求 + 计算」整体并行化更合适
 *
 * 并发度控制：{@see setConcurrency()} 限制同时在途连接数（分波执行），既避免“一次性
 * 打开几百个连接压垮对端/触发限流/撑爆文件描述符”，也让压测可与线程模型的并发度对齐。
 *
 * 典型用法：
 * ```php
 * $curl = new CurlMulti();
 * $curl->setConcurrency(16);          // 最多 16 个在途连接
 * $curl->get('https://api.example.com/u/1', [], 'u1');
 * $curl->post('https://api.example.com/u/2', ['v' => 1], [], 'u2');
 * $results = $curl->execute();         // ['u1' => [...], 'u2' => [...]]
 * ```
 *
 * @since PHP 8.1+
 */
final class CurlMulti
{
    /** @var array<string, array{handle: \CurlHandle, url: string, options: array, added: bool, finished: bool}> */
    private array $requests = [];

    /** \CurlHandle 的资源 ID → 请求键，便于从 curl_multi_info_read 反查 */
    private array $handleIndex = [];

    private ?\CurlMultiHandle $multiHandle = null;

    /** 最大在途连接数，<=0 表示不限制（一次性全部发出） */
    private int $concurrency = 0;

    private bool $running = false;

    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
        if ($this->multiHandle === false) {
            throw new ParallelException('无法初始化 curl_multi');
        }
    }

    /**
     * 设置最大在途连接数（滑动窗口）
     *
     * 0 表示不限制；>0 时始终保持至多该数量的请求在途，**完成一个立即补一个**，
     * 不存在“整波等最慢者”的队头阻塞。用于保护对端 API、规避限流、控制本机文件描述符占用。
     */
    public function setConcurrency(int $concurrency): self
    {
        $this->concurrency = max(0, $concurrency);

        return $this;
    }

    /**
     * 添加一个请求
     *
     * @param string $url 请求 URL
     * @param array<int, mixed> $options 自定义 Curl 选项（与默认选项合并，后者优先于前者）
     * @param string|null $key 请求标识键；不传则自动生成
     * @return string 使用的请求键
     */
    public function add(string $url, array $options = [], ?string $key = null): string
    {
        $key = $key ?? 'request_' . count($this->requests);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new ParallelException('无法初始化 curl');
        }

        $defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            // 允许连接复用（同名主机多次请求共享 TCP），对多用户同域名场景至关重要
            CURLOPT_HTTPHEADER => [],
        ];

        $merged = $defaultOptions;
        foreach ($options as $option => $value) {
            $merged[$option] = $value;
        }

        foreach ($merged as $option => $value) {
            if (curl_setopt($ch, $option, $value) === false) {
                curl_close($ch);
                throw new ParallelException('curl_setopt 失败：option=' . $option);
            }
        }

        $this->requests[$key] = [
            'handle' => $ch,
            'url' => $url,
            'options' => $merged,
            'added' => false,
            'finished' => false,
        ];

        return $key;
    }

    /**
     * 添加 GET 请求
     */
    public function get(string $url, array $headers = [], ?string $key = null): string
    {
        return $this->add($url, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
        ], $key);
    }

    /**
     * 添加 POST 请求
     *
     * @param string $url 请求 URL
     * @param array<string, mixed>|string $data POST 数据
     * @param array<int, string> $headers 请求头，如 ['Content-Type: application/json']
     * @param string|null $key 请求标识键
     */
    public function post(string $url, array|string $data = [], array $headers = [], ?string $key = null): string
    {
        return $this->add($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => is_array($data) ? http_build_query($data) : $data,
            CURLOPT_HTTPHEADER => $headers,
        ], $key);
    }

    /**
     * 执行所有请求（事件循环，单进程并发；受 setConcurrency 滑动窗口约束）
     *
     * @param int $timeout 整体超时（秒），<=0 表示不限制
     * @return array<string, array{error: string|null, response: string|null, info: array}>
     */
    public function execute(int $timeout = 30): array
    {
        if ($this->requests === []) {
            return [];
        }

        $results = [];
        $this->running = true;
        $startNs = hrtime(true);

        $keys = array_keys($this->requests);
        $total = count($keys);
        $window = $this->concurrency > 0 ? min($this->concurrency, $total) : $total;
        $next = 0;

        // 初始填满窗口
        for (; $next < $window; $next++) {
            $this->addHandle($keys[$next]);
        }

        $active = 0;

        do {
            do {
                $status = curl_multi_exec($this->multiHandle, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($status !== CURLM_OK) {
                $this->running = false;

                throw new ParallelException('curl_multi_exec 失败，code=' . $status);
            }

            // 收割已完成的请求，并立即补位，保持在途数恒定
            $refilled = false;

            while (($info = curl_multi_info_read($this->multiHandle)) !== false) {
                $key = $this->handleIndex[(int) $info['handle']] ?? null;

                if ($key === null) {
                    continue;
                }

                $this->collectOne($key, $results);
                $this->removeHandle($key);

                if ($next < $total) {
                    $this->addHandle($keys[$next++]);
                    $refilled = true;
                }
            }

            if ($timeout > 0 && (hrtime(true) - $startNs) / 1_000_000 >= $timeout * 1000) {
                break;
            }

            // 刚补位的句柄需要先 exec 才会启动，跳过本轮等待
            if (!$refilled && $active > 0 && curl_multi_select($this->multiHandle, 0.1) === -1) {
                usleep(100);
            }
        } while ($active > 0 || $next < $total);

        // 未完成的请求标记为超时（不抛异常，交由调用方按 key 处理）
        foreach ($this->requests as $key => $request) {
            if (!isset($results[$key])) {
                $results[$key] = [
                    'error' => 'timeout',
                    'response' => null,
                    'info' => ['url' => $request['url']],
                ];
            }

            $this->removeHandle($key);
        }

        $this->running = false;

        return $results;
    }

    /**
     * 把请求句柄挂到 multi 句柄上
     */
    private function addHandle(string $key): void
    {
        $handle = $this->requests[$key]['handle'];

        if ($this->requests[$key]['added']) {
            return;
        }

        if (curl_multi_add_handle($this->multiHandle, $handle) === CURLM_OK) {
            $this->requests[$key]['added'] = true;
            $this->handleIndex[(int) $handle] = $key;
        }
    }

    /**
     * 从 multi 句柄摘下请求句柄，释放窗口位
     */
    private function removeHandle(string $key): void
    {
        if (!$this->requests[$key]['added']) {
            return;
        }

        $handle = $this->requests[$key]['handle'];
        curl_multi_remove_handle($this->multiHandle, $handle);
        unset($this->handleIndex[(int) $handle]);
        $this->requests[$key]['added'] = false;
    }

    /**
     * 收割单个已完成请求的结果
     *
     * @param array<string, array{error: string|null, response: string|null, info: array}> $results
     */
    private function collectOne(string $key, array &$results): void
    {
        $handle = $this->requests[$key]['handle'];

        $error = curl_error($handle);
        $results[$key] = [
            'error' => $error !== '' ? $error : null,
            'response' => curl_multi_getcontent($handle),
            'info' => curl_getinfo($handle),
        ];

        $this->requests[$key]['finished'] = true;
    }

    /**
     * 便捷静态方法：并发发起一组 GET 请求
     *
     * @param array<string, string>|list<string> $urls 键=>URL，或纯 URL 列表
     * @param int $concurrency 最大在途连接数，<=0 不限制
     * @param int $timeout 整体超时（秒）
     * @return array<string, array{error: string|null, response: string|null, info: array}>
     */
    public static function fetch(array $urls, int $concurrency = 0, int $timeout = 30): array
    {
        $multi = new self();
        $multi->setConcurrency($concurrency);

        try {
            foreach ($urls as $key => $url) {
                $multi->get(is_string($key) ? $url : (string) $url, [], is_string($key) ? $key : null);
            }

            return $multi->execute($timeout);
        } finally {
            $multi->clear();
        }
    }

    /**
     * 请求数量
     */
    public function count(): int
    {
        return count($this->requests);
    }

    /**
     * 清除所有请求并释放句柄
     */
    public function clear(): void
    {
        if ($this->multiHandle !== null) {
            foreach ($this->requests as $request) {
                if ($request['added']) {
                    curl_multi_remove_handle($this->multiHandle, $request['handle']);
                }
                curl_close($request['handle']);
            }
        }

        $this->requests = [];
        $this->handleIndex = [];
    }

    /**
     * 最后一个 curl_multi 错误信息
     */
    public function error(): ?string
    {
        if ($this->multiHandle === null) {
            return 'CurlMulti not initialized';
        }

        $errno = curl_multi_errno($this->multiHandle);
        return $errno > 0 ? 'CurlMulti error code: ' . $errno : null;
    }

    public function __destruct()
    {
        $this->clear();

        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
            $this->multiHandle = null;
        }
    }
}
