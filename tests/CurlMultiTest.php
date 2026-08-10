<?php

declare(strict_types=1);

namespace Kode\Parallel\Tests;

use Kode\Parallel\Curl\CurlMulti;
use PHPUnit\Framework\TestCase;

/**
 * CurlMulti 真实 HTTP 压测/正确性测试
 *
 * 启动本地 Python 并发服务（benchmarks/_http_server.py），验证：
 * - GET / POST 基本收发
 * - execute() 返回数量与请求一致、无错误
 * - setConcurrency() 限并发仍能全部收回
 * 无 python3 / curl / proc_open 时跳过，避免污染环境。
 */
final class CurlMultiTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private ?int $port = null;

    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('ext-curl 未安装');
        }
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open 不可用');
        }
        $py = trim((string) @shell_exec('command -v python3'));
        if ($py === '' || !is_file(__DIR__ . '/../benchmarks/_http_server.py')) {
            self::markTestSkipped('python3 或 benchmarks/_http_server.py 不可用');
        }

        $this->port = 8900 + (int) (getmypid() % 100);
        $descr = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $this->proc = proc_open(
            escapeshellarg($py) . ' ' . escapeshellarg(__DIR__ . '/../benchmarks/_http_server.py') . " {$this->port} 20",
            $descr,
            $pipes
        );
        // 等待服务起来
        $ready = false;
        for ($i = 0; $i < 50; $i++) {
            $c = curl_init("http://127.0.0.1:{$this->port}/?delay=0");
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1]);
            $r = curl_exec($c);
            curl_close($c);
            if ($r !== false && str_contains((string) $r, 'ok')) {
                $ready = true;
                break;
            }
            usleep(100_000);
        }
        if (!$ready) {
            self::markTestSkipped('本地 HTTP 服务未能启动');
        }
    }

    protected function tearDown(): void
    {
        if ($this->proc !== null && is_resource($this->proc)) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
        }
    }

    public function testFetchReturnsAllResults(): void
    {
        $urls = [];
        for ($i = 0; $i < 50; $i++) {
            $urls["u{$i}"] = "http://127.0.0.1:{$this->port}/?delay=5";
        }

        $res = CurlMulti::fetch($urls);

        self::assertCount(50, $res);
        foreach ($res as $item) {
            self::assertNull($item['error'], '请求不应报错: ' . json_encode($item));
            self::assertNotNull($item['response']);
        }
    }

    public function testBoundedConcurrencyStillCollectsAll(): void
    {
        $m = new CurlMulti();
        $m->setConcurrency(4);
        for ($i = 0; $i < 40; $i++) {
            $m->get("http://127.0.0.1:{$this->port}/?delay=5", [], "k{$i}");
        }

        $res = $m->execute(30);
        self::assertCount(40, $res);
        foreach ($res as $item) {
            self::assertNull($item['error']);
        }
    }

    public function testPostRequest(): void
    {
        $m = new CurlMulti();
        $m->post("http://127.0.0.1:{$this->port}/?delay=0", ['a' => 'b'], [], 'p');
        $res = $m->execute(10);

        self::assertArrayHasKey('p', $res);
        self::assertNull($res['p']['error']);
        self::assertNotNull($res['p']['response']);
    }
}
