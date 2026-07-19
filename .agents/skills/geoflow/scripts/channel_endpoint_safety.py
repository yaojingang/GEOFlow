#!/usr/bin/env python3
"""Validate channel endpoints before a helper requests signed live capabilities."""

from __future__ import annotations

import ipaddress
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit


SCRIPT_INTERFACE = "internal-module"
SCRIPT_INTERFACE_REASON = "Shared endpoint and redirect-safety checks imported by channel report CLIs."


def validate_live_channel_endpoint(report: dict[str, Any]) -> str:
    channel = report.get("channel")
    endpoint = str(channel.get("endpoint_url") if isinstance(channel, dict) else "").strip()
    if not endpoint:
        raise SystemExit("Cached channel report does not expose endpoint_url; live remote inventory is blocked")
    if any(char.isspace() for char in endpoint):
        raise SystemExit("Channel endpoint_url must not contain whitespace")

    try:
        parsed = urlsplit(endpoint)
        _ = parsed.port
    except ValueError as exc:
        raise SystemExit(f"Invalid channel endpoint_url: {exc}") from exc
    if parsed.scheme not in {"http", "https"} or not parsed.hostname:
        raise SystemExit("Channel endpoint_url must be an http(s) URL with a hostname")
    if parsed.username is not None or parsed.password is not None:
        raise SystemExit("Channel endpoint_url must not contain credentials")
    if parsed.query or parsed.fragment:
        raise SystemExit("Channel endpoint_url must not contain a query string or fragment")

    hostname = parsed.hostname.rstrip(".").lower()
    is_loopback = hostname == "localhost" or hostname.endswith(".localhost")
    if not is_loopback:
        try:
            is_loopback = ipaddress.ip_address(hostname).is_loopback
        except ValueError:
            pass
    if parsed.scheme != "https" and not is_loopback:
        raise SystemExit("Signed live channel inventory requires HTTPS unless the endpoint host is loopback")
    return endpoint


def method_source(source: str, signature: str) -> str:
    start = source.find(signature)
    if start < 0:
        raise SystemExit(f"Cannot verify signed request safety; {signature} was not found")
    end = source.find("\n    private function ", start + 1)
    return source[start:] if end < 0 else source[start:end]


def require_signed_get_request_protection(workspace: Path) -> None:
    client_path = workspace / "app" / "Services" / "GeoFlow" / "DistributionHttpClient.php"
    if not client_path.is_file():
        raise SystemExit(f"Cannot verify signed request safety; missing GEOFlow HTTP client: {client_path}")
    source = client_path.read_text(encoding="utf-8", errors="strict")
    function_source = method_source(source, "private function signedGetJson")
    if "withoutRedirecting()" not in function_source and "'allow_redirects' => false" not in function_source:
        raise SystemExit(
            "Live remote inventory is blocked because signedGetJson does not explicitly disable redirects"
        )

    endpoint_index = function_source.find("$endpoint = $this->endpoint(")
    validation_index = function_source.find("$this->assertSafeSignedEndpoint($endpoint)")
    request_index = function_source.find("->get($endpoint)")
    if not (0 <= endpoint_index < validation_index < request_index):
        raise SystemExit(
            "Live remote inventory is blocked because signedGetJson does not validate the actual endpoint immediately before use"
        )

    helper_source = method_source(source, "private function assertSafeSignedEndpoint")
    required_markers = (
        "parse_url(",
        "PHP_URL_SCHEME",
        "PHP_URL_HOST",
        "https",
        "localhost",
        "127.0.0.1",
        "::1",
        "RuntimeException",
    )
    if any(marker not in helper_source for marker in required_markers):
        raise SystemExit(
            "Live remote inventory is blocked because assertSafeSignedEndpoint does not enforce HTTPS or explicit loopback HTTP"
        )


def require_signed_get_redirect_protection(workspace: Path) -> None:
    """Backward-compatible alias for callers using the earlier helper name."""
    require_signed_get_request_protection(workspace)
