#!/usr/bin/env python3
"""سرور پیش‌نمایش با هدر no-store — مرورگر همیشه آخرین نسخه را می‌گیرد."""
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer

class Handler(SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Cache-Control", "no-store, must-revalidate")
        self.send_header("Expires", "0")
        super().end_headers()

if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8123), Handler).serve_forever()
