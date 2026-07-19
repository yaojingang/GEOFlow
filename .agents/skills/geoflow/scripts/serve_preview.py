#!/usr/bin/env python3
# Copyright © 2026 姚金刚. All rights reserved.
# Project: geoflow
# Created by: 姚金刚
# Date: 2026-05-16
# X: https://x.com/yaojingang

import argparse
from functools import partial
import http.server
import socketserver
from pathlib import Path
from urllib.parse import unquote, urlsplit


PREVIEW_RELATIVE_PATH = "examples/qiaomu-editorial-20260418/index.html"
PREVIEW_ROOT = Path(__file__).resolve().parents[1] / "examples" / "qiaomu-editorial-20260418"
ALLOWED_PREVIEW_PATHS = frozenset({
    "/index.html",
    "/article.html",
    "/category.html",
    "/archive.html",
    "/assets/app.js",
    "/assets/theme.css",
})


def preview_file_for_request(request_target: str) -> Path | None:
    request_path = unquote(urlsplit(request_target).path)
    if request_path == "/":
        request_path = "/index.html"
    if request_path not in ALLOWED_PREVIEW_PATHS:
        return None
    preview_root = PREVIEW_ROOT.resolve()
    candidate = (preview_root / request_path.removeprefix("/")).resolve()
    if not candidate.is_relative_to(preview_root) or not candidate.is_file():
        return None
    return candidate


class PreviewRequestHandler(http.server.SimpleHTTPRequestHandler):
    def send_head(self):
        candidate = preview_file_for_request(self.path)
        if candidate is None:
            self.send_error(404, "Preview file not found")
            return None
        self.path = "/" + candidate.relative_to(PREVIEW_ROOT.resolve()).as_posix()
        return super().send_head()

    def list_directory(self, path):
        self.send_error(404, "Directory listing is disabled")
        return None


def main():
    parser = argparse.ArgumentParser(description="Serve geoflow preview files.")
    parser.add_argument("--port", type=int, default=45731)
    args = parser.parse_args()

    preview_root = PREVIEW_ROOT.resolve()
    for relative_path in ALLOWED_PREVIEW_PATHS:
        candidate = preview_root / relative_path.removeprefix("/")
        if candidate.is_symlink() or not candidate.is_file() or not candidate.resolve().is_relative_to(preview_root):
            raise SystemExit(f"Unsafe or missing bundled preview file: {relative_path}")

    handler = partial(PreviewRequestHandler, directory=str(preview_root))
    with socketserver.TCPServer(("127.0.0.1", args.port), handler) as httpd:
        print(f"Serving bundled preview: {preview_root}")
        print(f"Preview URL: http://127.0.0.1:{args.port}/index.html")
        httpd.serve_forever()


if __name__ == "__main__":
    main()
