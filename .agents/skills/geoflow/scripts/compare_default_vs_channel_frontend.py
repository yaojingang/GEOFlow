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


def list_missing(default_values: list[str], channel_values: list[str]) -> list[str]:
    return [item for item in default_values if item not in set(channel_values)]


def compare(report: dict) -> dict:
    default_site = report.get("default_site") or {}
    channel = report.get("channel") or {}
    target_package = report.get("target_package") or {}
    remote_target = report.get("remote_target") or {}
    sync_preview = report.get("sync_preview") or {}
    sync_summary = channel.get("sync_summary") or {}

    default_modules = list(default_site.get("supported_modules") or [])
    channel_modules = list(channel.get("supported_modules") or [])
    target_modules = list(target_package.get("supported_modules") or [])
    remote_modules = list(remote_target.get("supported_modules") or [])

    findings = []
    findings.extend(report.get("differences") or [])
    if channel and not channel.get("supports_first_party_frontend"):
        findings.append({
            "severity": "info",
            "area": "channel_type",
            "message": "Channel is not a GeoFlow Agent target; GEOFlow module rendering is not guaranteed.",
        })
    if list_missing(default_modules, target_modules):
        findings.append({
            "severity": "warning",
            "area": "target_package_modules",
            "message": "Target package does not advertise every default-site module type.",
            "missing": list_missing(default_modules, target_modules),
        })
    if channel and list_missing(default_modules, channel_modules):
        findings.append({
            "severity": "warning",
            "area": "channel_modules",
            "message": "Channel does not advertise every default-site module type.",
            "missing": list_missing(default_modules, channel_modules),
        })
    if channel and channel.get("frontend_experience_mode") == "inherit_default":
        findings.append({
            "severity": "ok",
            "area": "experience_mode",
            "message": "Channel follows the default-site frontend experience at sync time.",
        })
    if remote_target and remote_target.get("status") != "ok":
        findings.append({
            "severity": "warning",
            "area": "remote_target",
            "message": remote_target.get("message") or "Remote frontend capabilities are not available.",
        })
    if remote_target.get("is_stale"):
        findings.append({
            "severity": "notice",
            "area": "remote_capabilities_cache",
            "message": "Remote capability cache is older than the configured freshness window; refresh before syncing if accuracy matters.",
        })
    if remote_target.get("status") == "ok" and list_missing(default_modules, remote_modules):
        findings.append({
            "severity": "warning",
            "area": "remote_modules",
            "message": "Remote target does not advertise every default-site module type.",
            "missing": list_missing(default_modules, remote_modules),
        })

    mode = channel.get("frontend_experience_mode")
    if mode == "inherit_default":
        mode_recommendation = "Use inherit_default when the channel should track the default site at every sync."
    elif mode == "snapshot_default":
        mode_recommendation = "Use snapshot_default when this channel starts from the default site but should drift independently."
    elif mode == "custom":
        mode_recommendation = "Use custom when the channel has its own homepage modules, carousel, and style tokens."
    else:
        mode_recommendation = "Select a GeoFlow Agent channel to receive mode guidance."

    preview_warnings = list(sync_preview.get("warnings") or [])
    requires_confirmation = bool(sync_preview.get("requires_confirmation"))
    if requires_confirmation:
        recommended_action = "Open the sync preview, refresh capabilities if possible, then confirm sync only after reviewing warnings."
    elif remote_target.get("status") == "not_checked":
        recommended_action = "Refresh remote capabilities before syncing."
    elif remote_target.get("status") == "unsupported_or_not_found":
        recommended_action = "Download the latest GeoFlow Agent target package and overwrite the remote site package."
    elif remote_target.get("is_stale"):
        recommended_action = "Refresh the cached remote capabilities, then sync if the preview remains acceptable."
    else:
        recommended_action = "Proceed with preview-first sync or continue editing the channel frontend experience."

    return {
        "channel_id": channel.get("id"),
        "channel_type": channel.get("type"),
        "frontend_experience_mode": channel.get("frontend_experience_mode"),
        "mode_recommendation": mode_recommendation,
        "recommended_action": recommended_action,
        "default_module_count": len(default_modules),
        "target_module_count": len(target_modules),
        "channel_module_count": len(channel_modules),
        "remote_status": remote_target.get("status") or "not_checked",
        "remote_source": remote_target.get("source") or report.get("remote_source") or "cache",
        "remote_checked_at": remote_target.get("checked_at") or "",
        "remote_cache_stale": bool(remote_target.get("is_stale")),
        "remote_capability_version": remote_target.get("capability_version") or "",
        "remote_package_version": remote_target.get("package_version") or "",
        "remote_module_count": len(remote_modules),
        "sync_requires_confirmation": requires_confirmation,
        "sync_warning_codes": [warning.get("code") for warning in preview_warnings if warning.get("code")],
        "sync_summary": {
            "active_theme": sync_summary.get("active_theme") or "",
            "front_mode": sync_summary.get("front_mode") or "",
            "homepage_modules_count": sync_summary.get("homepage_modules_count") or 0,
            "home_carousel_slides_count": sync_summary.get("home_carousel_slides_count") or 0,
            "article_text_ads_count": sync_summary.get("article_text_ads_count") or 0,
            "homepage_style_keys": sync_summary.get("homepage_style_keys") or [],
        },
        "findings": findings,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Compare GEOFlow default frontend and channel frontend capability report.")
    parser.add_argument("--workspace", help="GEOFlow workspace path")
    parser.add_argument("--channel", help="Distribution channel id for live artisan inventory")
    parser.add_argument("--report", help="Existing geoflow:frontend-experience JSON report")
    parser.add_argument("--live-remote", action="store_true", help="Pass --live-remote to geoflow:frontend-experience without persisting cache")
    args = parser.parse_args()

    if args.report:
        report = load_report(Path(args.report).resolve())
    elif args.workspace and args.channel:
        report = run_artisan_report(Path(args.workspace).resolve(), args.channel, args.live_remote)
    else:
        raise SystemExit("Provide --report or both --workspace and --channel.")

    print(json.dumps(compare(report), ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
