#!/usr/bin/env python3
import argparse
import json
import re
from pathlib import Path


DEFAULT_SITE_MODULE_TYPES = [
    "hero",
    "rich_text",
    "image_band",
    "metric_band",
    "chart_band",
    "feature_grid",
    "article_collection",
    "cta_band",
    "lead_form",
    "custom_html",
]
TARGET_PACKAGE_MODULE_TYPES = [
    "hero",
    "rich_text",
    "image_band",
    "metric_band",
    "chart_band",
    "feature_grid",
    "article_collection",
    "cta_band",
    "custom_html",
]


def read_text(path: Path) -> str:
    if not path.is_file():
        return ""
    return path.read_text(encoding="utf-8", errors="ignore")


def php_const_array(source: str, const_name: str) -> list[str]:
    match = re.search(rf"public const {re.escape(const_name)}\s*=\s*\[(.*?)\];", source, re.S)
    if not match:
        return []
    return re.findall(r"'([^']+)'", match.group(1))


def php_function_return_array(source: str, function_name: str) -> list[str]:
    match = re.search(rf"function\s+{re.escape(function_name)}\s*\([^)]*\)\s*:\s*array\s*\{{(.*?)\}}", source, re.S)
    if not match:
        return []
    return re.findall(r"'([^']+)'", match.group(1))


def php_array_string_value(source: str, key: str) -> str:
    match = re.search(
        rf"['\"]{re.escape(key)}['\"]\s*=>\s*['\"]([^'\"]+)['\"]",
        source,
    )
    return match.group(1) if match else ""


def default_site_surface(workspace: Path) -> dict:
    builder = read_text(workspace / "app" / "Support" / "Site" / "HomepageModuleBuilder.php")
    home_controller = read_text(workspace / "app" / "Http" / "Controllers" / "Site" / "HomeController.php")
    site_settings_controller = read_text(workspace / "app" / "Http" / "Controllers" / "Admin" / "SiteSettingsController.php")
    modules_partial = workspace / "resources" / "views" / "site" / "partials" / "homepage-modules.blade.php"
    themes_root = workspace / "resources" / "views" / "theme"

    module_types = php_const_array(builder, "TYPES") or DEFAULT_SITE_MODULE_TYPES
    return {
        "surface": "default_site",
        "available": bool(home_controller),
        "theme_count": len([item for item in themes_root.iterdir() if item.is_dir()]) if themes_root.is_dir() else 0,
        "default_site_supported_modules": module_types,
        "homepage_builder": {
            "builder_present": bool(builder),
            "partial_present": modules_partial.is_file(),
            "supports_import": "importHomepageModuleDesign" in site_settings_controller,
            "supports_preset": "applyHomepageModulePreset" in site_settings_controller,
            "supported_modules": module_types,
            "supports_lead_form": "lead_form" in module_types and "lead_form_slug" in builder,
            "style_contract": "homepage_style" in builder and "normalizeStyle" in builder,
        },
        "homepage_data": {
            "home_carousel_slides": "homepageCarouselSlides" in home_controller,
            "featured_articles": "featuredArticles" in home_controller,
            "hot_articles": "hotArticles" in home_controller,
            "latest_articles": "'articles'" in home_controller or '"articles"' in home_controller,
        },
    }


def channel_site_surface(workspace: Path) -> dict:
    channel_model = read_text(workspace / "app" / "Models" / "DistributionChannel.php")
    controller = read_text(workspace / "app" / "Http" / "Controllers" / "Admin" / "DistributionController.php")
    edit_view = read_text(workspace / "resources" / "views" / "admin" / "distribution" / "edit.blade.php")
    index_view = read_text(workspace / "resources" / "views" / "admin" / "distribution" / "index.blade.php")
    show_view = read_text(workspace / "resources" / "views" / "admin" / "distribution" / "show.blade.php")
    preview_view = read_text(workspace / "resources" / "views" / "admin" / "distribution" / "sync-preview.blade.php")
    routes = read_text(workspace / "routes" / "web.php")
    payload_builder = read_text(workspace / "app" / "Services" / "GeoFlow" / "DistributionPayloadBuilder.php")
    inspector = read_text(workspace / "app" / "Services" / "GeoFlow" / "FrontendExperienceInspector.php")
    http_client = read_text(workspace / "app" / "Services" / "GeoFlow" / "DistributionHttpClient.php")
    command = read_text(workspace / "app" / "Console" / "Commands" / "FrontendExperienceInspectCommand.php")

    return {
        "surface": "channel_site",
        "available": bool(channel_model and controller),
        "first_class_channel_type": "geoflow_agent",
        "experience_modes": php_const_array(channel_model, "FRONTEND_EXPERIENCE_MODES") or [
            "custom",
            "inherit_default",
            "snapshot_default",
        ],
        "settings_contract": {
            "homepage_style": "homepage_style" in channel_model and "homepage_style_json" in controller,
            "homepage_modules": "homepage_modules" in channel_model and "homepage_modules_json" in controller,
            "home_carousel_slides": "home_carousel_slides" in channel_model and "home_carousel_slides_json" in controller,
            "frontend_experience_mode": "frontendExperienceMode" in channel_model,
            "article_text_ads": "article_text_ads" in channel_model,
            "frontend_capabilities_cache": "FRONTEND_CAPABILITIES_CACHE_KEY" in channel_model and "frontendCapabilitiesCache" in channel_model,
        },
        "admin_ui": {
            "frontend_experience_section": "前台体验" in edit_view or "frontend_experience_mode" in edit_view,
            "frontend_experience_summary": "同步前差异摘要" in edit_view and "远端能力状态" in edit_view,
            "selected_sync_preview": "模块" in index_view and "channelSyncSummaries" in index_view,
            "sync_preview_page": "前台体验同步预览" in preview_view and "frontend_sync_confirmed" in preview_view,
            "capability_cache_status_on_detail": "远端能力缓存" in show_view,
            "json_import_fields": all(field in edit_view for field in [
                "homepage_style_json",
                "homepage_modules_json",
                "home_carousel_slides_json",
            ]),
        },
        "admin_routes": {
            "refresh_frontend_capabilities": "frontend-capabilities/refresh" in routes,
            "single_sync_preview": "sync-settings/preview" in routes,
            "all_sync_preview": "sync-settings-all/preview" in routes,
            "selected_sync_preview": "sync-settings-selected/preview" in routes,
        },
        "inventory_contract": {
            "artisan_json_report": "geoflow:frontend-experience" in command,
            "artisan_live_remote": "--live-remote" in command,
            "sync_summary": "syncSummary" in inspector,
            "sync_preview": "syncPreview" in inspector and "requiresSyncConfirmation" in inspector,
            "remote_target": "remoteTargetSurface" in inspector and "remote_target" in inspector,
            "remote_cache": "cachedRemoteTargetSurface" in inspector and "refreshRemoteCapabilities" in inspector,
            "frontend_capabilities_client": "frontendCapabilities" in http_client and "/geoflow-agent/v1/frontend-capabilities" in http_client,
        },
        "article_flags_in_payload": "is_featured" in payload_builder and "is_hot" in payload_builder,
        "external_channel_note": "WordPress REST" in edit_view and "Generic API" in edit_view,
    }


def target_package_surface(workspace: Path) -> dict:
    package_builder = read_text(workspace / "app" / "Services" / "GeoFlow" / "DistributionTargetSitePackageBuilder.php")
    builder = read_text(workspace / "app" / "Support" / "Site" / "HomepageModuleBuilder.php")
    default_modules = php_const_array(builder, "TYPES") or DEFAULT_SITE_MODULE_TYPES
    target_modules = php_function_return_array(package_builder, "frontendSupportedModules") or TARGET_PACKAGE_MODULE_TYPES
    return {
        "surface": "geoflow_agent_target_package",
        "available": bool(package_builder),
        "capability_endpoint": "/geoflow-agent/v1/frontend-capabilities" in package_builder,
        "capability_version": php_array_string_value(package_builder, "capability_version"),
        "current_settings_summary": "'current_settings' => [" in package_builder and "'homepage_modules_count'" in package_builder,
        "homepage_renderer": "function renderHomepageModules" in package_builder,
        "initial_static_renderer": "initialHomepageModulesHtml" in package_builder and "initialStaticIndex" in package_builder,
        "runtime_renderer": "function renderHomepageModule" in package_builder and "function renderHomepageModules" in package_builder,
        "settings_contract": {
            "homepage_style": "'homepage_style'" in package_builder,
            "homepage_modules": "'homepage_modules'" in package_builder,
            "home_carousel_slides": "'home_carousel_slides'" in package_builder,
            "frontend_experience_mode": "'frontend_experience_mode'" in package_builder,
        },
        "target_package_supported_modules": target_modules,
        "supported_modules": target_modules,
        "unsupported_default_site_modules": sorted(set(default_modules) - set(target_modules)),
        "lead_form_sync_policy": (
            "supported"
            if "lead_form" in target_modules
            else "downgrade_to_cta_or_upgrade_target_package"
        ),
        "supported_routes": [
            "/",
            "/article/{slug}",
            "/llms.txt",
            "/sitemap.txt",
            "/geoflow-agent/v1/health",
            "/geoflow-agent/v1/site-settings",
            "/geoflow-agent/v1/frontend-capabilities",
        ],
    }


def system_inventory(workspace: Path) -> dict:
    command = workspace / "app" / "Console" / "Commands" / "FrontendExperienceInspectCommand.php"
    inspector = workspace / "app" / "Services" / "GeoFlow" / "FrontendExperienceInspector.php"
    return {
        "artisan_command": "geoflow:frontend-experience" if command.is_file() else "",
        "inspector_service": str(inspector) if inspector.is_file() else "",
        "can_run_live_inventory": (workspace / "artisan").is_file() and command.is_file(),
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Discover default and channel frontend experience surfaces in GEOFlow.")
    parser.add_argument("workspace", help="Path to the GEOFlow workspace")
    args = parser.parse_args()

    workspace = Path(args.workspace).resolve()
    report = {
        "workspace": str(workspace),
        "system_inventory": system_inventory(workspace),
        "default_site": default_site_surface(workspace),
        "channel_site": channel_site_surface(workspace),
        "target_package": target_package_surface(workspace),
    }
    report["capability_split"] = {
        "default_site_supported_modules": report["default_site"].get("default_site_supported_modules", []),
        "target_package_supported_modules": report["target_package"].get("target_package_supported_modules", []),
        "unsupported_default_site_modules_on_target_package": report["target_package"].get("unsupported_default_site_modules", []),
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
