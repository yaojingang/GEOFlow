#!/usr/bin/env python3
import argparse
import json
import subprocess
from pathlib import Path

from channel_endpoint_safety import require_signed_get_request_protection, validate_live_channel_endpoint


def load_report(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def run_artisan_report(workspace: Path, channel: str, live_remote: bool = False) -> dict:
    cmd = ["php", "artisan", "geoflow:frontend-experience", channel, "--json"]
    if live_remote:
        cached = subprocess.run(cmd, cwd=workspace, check=True, text=True, capture_output=True)
        validate_live_channel_endpoint(json.loads(cached.stdout))
        require_signed_get_request_protection(workspace)
        cmd.append("--live-remote")
    completed = subprocess.run(cmd, cwd=workspace, check=True, text=True, capture_output=True)
    return json.loads(completed.stdout)


def recommendation(remote: dict, preview: dict) -> str:
    status = remote.get("status") or "not_checked"
    warnings = preview.get("warnings") or []
    warning_codes = {item.get("code") for item in warnings if item.get("code")}

    if status == "unsupported_or_not_found":
        return "download_new_package"
    if status in {"missing_secret", "not_checked"}:
        return "refresh_capabilities"
    if status == "unavailable":
        return "confirm_sync_if_settings_must_continue"
    if remote.get("is_stale"):
        return "refresh_capabilities"
    if preview.get("requires_confirmation"):
        if "missing_modules" in warning_codes:
            return "download_new_package_or_confirm_sync"
        return "review_preview_then_confirm_sync"
    return "safe_to_confirm_after_preview"


def build(report: dict) -> dict:
    channel = report.get("channel") or {}
    remote = report.get("remote_target") or {}
    preview = report.get("sync_preview") or {}
    summary = preview.get("summary") or channel.get("sync_summary") or {}
    warnings = preview.get("warnings") or []

    return {
        "channel_id": channel.get("id"),
        "channel_name": channel.get("name"),
        "channel_type": channel.get("type"),
        "remote_source": remote.get("source") or report.get("remote_source") or "cache",
        "remote_status": remote.get("status") or "not_checked",
        "remote_checked_at": remote.get("checked_at") or "",
        "remote_cache_stale": bool(remote.get("is_stale")),
        "remote_package_version": remote.get("package_version") or "",
        "remote_capability_version": remote.get("capability_version") or "",
        "remote_supported_modules_count": len(remote.get("supported_modules") or []),
        "remote_supported_routes_count": len(remote.get("supported_routes") or []),
        "sync_requires_confirmation": bool(preview.get("requires_confirmation")),
        "sync_warning_codes": [warning.get("code") for warning in warnings if warning.get("code")],
        "sync_warnings": warnings,
        "sync_summary": {
            "frontend_experience_mode": summary.get("frontend_experience_mode") or "",
            "active_theme": summary.get("active_theme") or "",
            "front_mode": summary.get("front_mode") or "",
            "homepage_modules_count": summary.get("homepage_modules_count") or 0,
            "homepage_module_types": summary.get("homepage_module_types") or [],
            "home_carousel_slides_count": summary.get("home_carousel_slides_count") or 0,
            "home_carousel_slide_titles": summary.get("home_carousel_slide_titles") or [],
            "homepage_style_keys": summary.get("homepage_style_keys") or [],
            "article_text_ads_count": summary.get("article_text_ads_count") or 0,
        },
        "recommended_action": recommendation(remote, preview),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Build a GEOFlow channel frontend sync preview report.")
    parser.add_argument("--workspace", help="GEOFlow workspace path")
    parser.add_argument("--channel", help="Distribution channel id for artisan report")
    parser.add_argument("--report", help="Existing geoflow:frontend-experience JSON report")
    parser.add_argument("--live-remote", action="store_true", help="Use --live-remote for a non-persistent live remote read")
    args = parser.parse_args()

    if args.report:
        report = load_report(Path(args.report).resolve())
    elif args.workspace and args.channel:
        report = run_artisan_report(Path(args.workspace).resolve(), args.channel, args.live_remote)
    else:
        raise SystemExit("Provide --report or both --workspace and --channel.")

    print(json.dumps(build(report), ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
