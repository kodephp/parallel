<?php

declare(strict_types=1);

namespace Kode\Parallel\Curl;

use Kode\Parallel\Exception\ParallelException;

/**
 * CurlMulti 并行 Curl 请求封装
 *
 * 提供便捷的多线程 HTTP 请求能力，
 * 支持并发执行多个 HTTP 请求并统一处理结果。
 *
 * @since PHP 8.1+
 */
final class CurlMulti
{
    /** @var array<string, array{handle: \CurlHandle, url: string, options: array}] */
    private array $requests = [];
    private ?\CurlMultiHandle $multiHandle = null;
    private bool $running = false;

    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
        if ($this->multiHandle === false) {
            throw new ParallelException('无法初始化 curl_multi');
        }
    }

    /**
     * 添加一个请求
     *
     * @param string $url 请求 URL
     * @param array<string, mixed> $options Curl 选项
     * @param string|null $key 请求标识键
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
        ];

        $mergedOptions = $defaultOptions + $options;

        foreach ($mergedOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        curl_multi_add_handle($this->multiHandle, $ch);

        $this->requests[$key] = [
            'handle' => $ch,
            'url' => $url,
            'options' => $mergedOptions,
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
     * @param array|string $data POST 数据
     * @param array $headers 请求头
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
     * 执行所有请求
     *
     * @param int $timeout 超时时间（秒）
     * @return array<string, array{error: string|null, response: string|null, info: array}>
     */
    public function execute(int $timeout = 30): array
    {
        if (empty($this->requests)) {
            return [];
        }

        $results = [];
        $this->running = true;
        $startTime = hrtime(true);

        do {
            $status = curl_multi_exec($this->multiHandle, $running);
            $info = curl_multi_info_read($this->multiHandle);

            if ($status > 0) {
                foreach ($this->requests as $key => &$request) {
                    if (!isset($request['finished'])) {
                        $error = curl_error($request['handle']);
                        $results[$key] = [
                            'error' => $error ?: 'Unknown error',
                            'response' => null,
                            'info' => curl_getinfo($request['handle']),
                        ];
                        $request['finished'] = true;
                    }
                }
                break;
            }

            if ($running === 0) {
                break;
            }

            curl_multi_select($this->multiHandle, 0.1);

            $elapsed = (int)((hrtime(true) - $startTime) / 1_000_000);
            if ($timeout > 0 && $elapsed > $timeout * 1000) {
                break;
            }
        } while ($running || $status === CURLM_CALL_MULTI_PERFORM);

        foreach ($this->requests as $key => &$request) {
            if (isset($request['finished'])) {
                continue;
            }

            $response = curl_multi_getcontent($request['handle']);
            $info = curl_getinfo($request['handle']);
            $error = curl_error($request['handle']);

            $results[$key] = [
                'error' => $error ?: null,
                'response' => $response,
                'info' => $info,
            ];

            $request['finished'] = true;
        }

        $this->running = false;
        return $results;
    }

    /**
     * 获取请求数量
     */
    public function count(): int
    {
        return count($this->requests);
    }

    /**
     * 清除所有请求
     */
    public function clear(): void
    {
        foreach ($this->requests as $request) {
            curl_multi_remove_handle($this->multiHandle, $request['handle']);
            curl_close($request['handle']);
        }

        $this->requests = [];
    }

    /**
     * 获取最后一个错误信息
     */
    public function error(): ?string
    {
        if ($this->multiHandle === null) {
            return 'CurlMulti not initialized';
        }

        $errno = curl_multi_errno($this->multiHandle);
        return $errno > 0 ? "CurlMulti error code: {$errno}" : null;
    }

    public function __destruct()
    {
        $this->clear();

        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
        }
    }
}
