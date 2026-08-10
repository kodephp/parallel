#!/usr/bin/env python3
"""本地并发 HTTP 服务（仅供压测）：ThreadingHTTPServer 每连接一线程，
读取 ?delay= 毫秒并 sleep 后返回小 JSON，用于真实模拟「多用户同域名外部 API」并发。

用法：python3 benchmarks/_http_server.py [端口] [默认delay毫秒]
"""
import sys
import time
import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8899
DEFAULT_DELAY = int(sys.argv[2]) if len(sys.argv) > 2 else 20


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        qs = parse_qs(urlparse(self.path).query)
        delay = int(qs.get("delay", [DEFAULT_DELAY])[0])
        if delay > 0:
            time.sleep(delay / 1000.0)
        body = json.dumps({"ok": True, "ts": time.time_ns()}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):  # 静默
        pass


if __name__ == "__main__":
    server = ThreadingHTTPServer(("127.0.0.1", PORT), Handler)
    print(f"listening on http://127.0.0.1:{PORT} (delay={DEFAULT_DELAY}ms, threaded)", flush=True)
    server.serve_forever()
