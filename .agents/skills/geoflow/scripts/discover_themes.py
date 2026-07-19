#!/usr/bin/env python3
# Copyright © 2026 姚金刚. All rights reserved.
# Project: geoflow
# Created by: 姚金刚
# Date: 2026-05-16
# X: https://x.com/yaojingang

import argparse
import json
import re
from pathlib import Path
from typing import Optional


def read_text(path: Path) -> str:
    if not path.is_file():
        return ""
    return path.read_text(encoding="utf-8", errors="ignore")


def load_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def view_name(relative_path: str) -> str:
    if relative_path.endswith(".blade.php"):
        relative_path = relative_path[:-10]
    return relative_path.replace("/", ".")


def derive_preview_routes(theme_id: str, manifest: dict, framework: str) -> list[str]:
    routes = manifest.get("preview_routes")
    if isinstance(routes, list) and routes:
        return [str(item) for item in routes]

    sample_routes = manifest.get("sample_routes")
    if isinstance(sample_routes, dict) and sample_routes:
        return [str(value) for value in sample_routes.values()]

    if framework == "laravel":
        return ["/", "/category/{slug}", "/article/{slug}", "/archive"]

    return [
        f"/preview/{theme_id}/",
        f"/preview/{theme_id}/category",
        f"/preview/{theme_id}/article",
        f"/preview/{theme_id}/archive",
    ]


def public_asset_record(workspace: Path, theme_id: str) -> dict:
    public_root = workspace / "public" / "themes" / theme_id
    return {
        "theme_css": (public_root / "theme.css").is_file(),
        "theme_js": (public_root / "theme.js").is_file(),
        "root": str(public_root) if public_root.is_dir() else "",
    }


def php_const_array(source: str, const_name: str) -> list[str]:
    match = re.search(rf"public const {re.escape(const_name)}\s*=\s*\[(.*?)\];", source, re.S)
    if not match:
        return []
    return re.findall(r"'([^']+)'", match.group(1))


def php_const_int(source: str, const_name: str) -> Optional[int]:
    match = re.search(rf"public const {re.escape(const_name)}\s*=\s*(\d+)\s*;", source)
    return int(match.group(1)) if match else None


def home_template_signals(theme_dir: Path) -> dict:
    text = read_text(theme_dir / "home.blade.php")
    return {
        "has_home_template": bool(text),
        "uses_homepage_carousel_slides": "homepageCarouselSlides" in text,
        "uses_homepage_modules": "homepageModules" in text or "homepage_modules" in text,
        "uses_homepage_style": "homepageStyle" in text or "homepage_style" in text,
        "uses_show_homepage_modules": "showHomepageModules" in text,
        "includes_homepage_modules_partial": "homepage-modules" in text,
        "uses_hot_articles": "hotArticles" in text,
        "uses_featured_articles": "featuredArticles" in text,
        "uses_latest_articles": "$articles" in text,
        "guards_default_home_state": "$search" in text and "$category" in text,
    }


def theme_record(workspace: Path, theme_dir: Path, framework: str) -> dict:
    theme_id = theme_dir.name
    manifest = load_json(theme_dir / "manifest.json")

    if framework == "laravel":
        blade_files = sorted(
            path.relative_to(theme_dir).as_posix()
            for path in theme_dir.rglob("*.blade.php")
            if ".theme-backups" not in path.parts
        )
        editable_files = list(blade_files)
    else:
        templates_dir = theme_dir / "templates"
        template_files = sorted(path.relative_to(theme_dir).as_posix() for path in templates_dir.glob("*.php")) if templates_dir.is_dir() else []
        editable_files = list(template_files)
        blade_files = []

    for relative in ("assets/theme.css", "manifest.json", "tokens.json", "mapping.json"):
        if (theme_dir / relative).is_file() and relative not in editable_files:
            editable_files.append(relative)

    for relative in ("edit-session.json", "change-plan.md", "preview-notes.md"):
        if (theme_dir / relative).is_file() and relative not in editable_files:
            editable_files.append(relative)

    session_state = str(manifest.get("session_state", "")).strip()
    is_preview_session = session_state == "preview" or theme_id.startswith("preview-") or theme_id.endswith("-preview")

    return {
        "id": theme_id,
        "name": manifest.get("name", theme_id),
        "description": manifest.get("description", ""),
        "version": manifest.get("version", ""),
        "base_theme_id": manifest.get("base_theme_id", ""),
        "mode": manifest.get("mode", ""),
        "session_state": session_state,
        "is_preview_session": is_preview_session,
        "preview_routes": derive_preview_routes(theme_id, manifest, framework),
        "templates": [view_name(item) for item in blade_files] if framework == "laravel" else [Path(item).stem for item in editable_files if item.startswith("templates/")],
        "editable_files": editable_files,
        "public_assets": public_asset_record(workspace, theme_id) if framework == "laravel" else {},
        "home_template_signals": home_template_signals(theme_dir) if framework == "laravel" else {},
    }


def detect_theme_editor(workspace: Path) -> dict:
    controller = workspace / "app" / "Http" / "Controllers" / "Admin" / "SiteThemeEditorController.php"
    service = workspace / "app" / "Services" / "Admin" / "SiteThemeEditorService.php"
    routes = read_text(workspace / "routes" / "web.php")
    return {
        "controller": controller.is_file(),
        "service": service.is_file(),
        "routes_reference_theme_editor": "theme-editor" in routes or "SiteThemeEditorController" in routes,
        "pages": ["home", "category", "article"] if controller.is_file() and service.is_file() else [],
    }


def detect_homepage_module_builder(workspace: Path) -> dict:
    builder_path = workspace / "app" / "Support" / "Site" / "HomepageModuleBuilder.php"
    builder = read_text(builder_path)
    admin_controller = read_text(workspace / "app" / "Http" / "Controllers" / "Admin" / "SiteSettingsController.php")
    routes = read_text(workspace / "routes" / "web.php")
    site_home = read_text(workspace / "resources" / "views" / "site" / "home.blade.php")
    partial_path = workspace / "resources" / "views" / "site" / "partials" / "homepage-modules.blade.php"
    partial = read_text(partial_path)

    module_types = php_const_array(builder, "TYPES")
    layouts = php_const_array(builder, "LAYOUTS")
    article_sources = php_const_array(builder, "ARTICLE_SOURCES")
    presets = php_const_array(builder, "PRESETS")
    preset_modes = php_const_array(builder, "PRESET_MODES")

    return {
        "builder_present": builder_path.is_file(),
        "builder_path": str(builder_path) if builder_path.is_file() else "",
        "partial_present": partial_path.is_file(),
        "module_types": module_types,
        "layouts": layouts,
        "article_sources": article_sources,
        "style_fields": [
            "accent_color",
            "background_color",
            "surface_color",
            "text_color",
            "muted_color",
            "container_width",
            "section_spacing",
            "radius",
        ] if builder else [],
        "module_style_fields": [
            "accent_color",
            "surface_color",
            "text_color",
            "muted_color",
            "alignment",
        ] if builder else [],
        "presets": presets,
        "preset_modes": preset_modes,
        "max_modules": php_const_int(builder, "MAX_MODULES"),
        "settings_keys": {
            "homepage_modules": "homepage_modules" in admin_controller or "homepage_modules" in builder,
            "homepage_style": "homepage_style" in admin_controller or "homepage_style" in builder,
        },
        "admin_routes": {
            "update": "homepage-modules" in routes and "updateHomepageModules" in admin_controller,
            "preset": "homepage-modules/preset" in routes and "applyHomepageModulePreset" in admin_controller,
            "import": "homepage-modules/import" in routes and "importHomepageModuleDesign" in admin_controller,
        },
        "supports_design_json_import": "normalizeDesignPayload" in builder and "importHomepageModuleDesign" in admin_controller,
        "supports_alias_mapping": "mapModuleAliases" in builder and "mapStyleAliases" in builder,
        "sanitizes_custom_html": "sanitizeCustomHtml" in builder,
        "default_home_rendering": {
            "site_home_includes_partial": "homepage-modules" in site_home,
            "partial_filters_enabled_modules": "enabled" in partial and "homepageModules" in partial,
            "partial_uses_homepage_style": "homepageStyle" in partial,
            "partial_uses_show_homepage_modules": "showHomepageModules" in partial,
        },
        "notes": [
            "When supported, prefer a reviewed homepage-design.json payload for homepage modules and style tokens.",
            "Do not submit admin import or preset routes until the operator confirms the exact payload and mode.",
        ] if builder else [],
    }


def detect_homepage_contract(workspace: Path) -> dict:
    controller = read_text(workspace / "app" / "Http" / "Controllers" / "Site" / "HomeController.php")
    site_home = read_text(workspace / "resources" / "views" / "site" / "home.blade.php")
    partial = read_text(workspace / "resources" / "views" / "site" / "partials" / "homepage-modules.blade.php")
    variables = {
        "siteTitle": "siteTitle" in controller,
        "siteSubtitle": "siteSubtitle" in controller,
        "siteDescription": "siteDescription" in controller,
        "homepageCarouselSlides": "homepageCarouselSlides" in controller,
        "homepageModules": "homepageModules" in controller,
        "homepageStyle": "homepageStyle" in controller,
        "showHomepageModules": "showHomepageModules" in controller,
        "featuredArticles": "featuredArticles" in controller,
        "hotArticles": "hotArticles" in controller,
        "articles": "'articles'" in controller or '"articles"' in controller,
        "cardSummaries": "cardSummaries" in controller,
    }
    safe_modules = []
    if variables["homepageCarouselSlides"]:
        safe_modules.extend(["home.hero_carousel", "home.visual_band"])
    if variables["hotArticles"]:
        safe_modules.extend(["home.hot_articles", "home.momentum_rail"])
    if variables["featuredArticles"]:
        safe_modules.extend(["home.featured_cases", "home.featured_resources"])
    if variables["articles"]:
        safe_modules.extend(["home.latest_resources", "home.metric_band", "home.chart_lite"])
    if variables["siteDescription"]:
        safe_modules.extend(["home.text_value_block", "home.cta_band"])
    if variables["homepageModules"] and variables["homepageStyle"]:
        safe_modules.extend(["home.builder.hero", "home.builder.rich_text", "home.builder.image_band", "home.builder.metric_band", "home.builder.chart_band", "home.builder.feature_grid", "home.builder.article_collection", "home.builder.cta_band", "home.builder.lead_form", "home.builder.custom_html"])

    return {
        "home_controller_present": bool(controller),
        "site_home_present": bool(site_home),
        "homepage_modules_partial_present": bool(partial),
        "variables": variables,
        "default_home_guard_detected": "$search === ''" in site_home and "! $category" in site_home,
        "homepage_enrichment_ready": variables["articles"] and (
            variables["homepageCarouselSlides"] or variables["hotArticles"] or variables["featuredArticles"]
        ),
        "homepage_builder_ready": variables["homepageModules"] and variables["homepageStyle"] and variables["showHomepageModules"] and bool(partial),
        "safe_homepage_modules": sorted(set(safe_modules)),
        "notes": [
            "Use homepage enrichment only from current view data or explicit static theme copy.",
            "Use homepage builder JSON when HomepageModuleBuilder and import routes are available.",
            "Keep search/category/category-missing states focused unless the request explicitly expands them.",
        ],
    }


def detect_channel_frontend_contract(workspace: Path) -> dict:
    channel_model = read_text(workspace / "app" / "Models" / "DistributionChannel.php")
    distribution_controller = read_text(workspace / "app" / "Http" / "Controllers" / "Admin" / "DistributionController.php")
    edit_view = read_text(workspace / "resources" / "views" / "admin" / "distribution" / "edit.blade.php")
    target_package = read_text(workspace / "app" / "Services" / "GeoFlow" / "DistributionTargetSitePackageBuilder.php")
    inspector = workspace / "app" / "Services" / "GeoFlow" / "FrontendExperienceInspector.php"
    command = workspace / "app" / "Console" / "Commands" / "FrontendExperienceInspectCommand.php"

    return {
        "distribution_channel_model_present": bool(channel_model),
        "admin_distribution_controller_present": bool(distribution_controller),
        "frontend_experience_modes": php_const_array(channel_model, "FRONTEND_EXPERIENCE_MODES"),
        "channel_settings_fields": {
            "homepage_style": "homepage_style" in channel_model and "homepage_style_json" in distribution_controller,
            "homepage_modules": "homepage_modules" in channel_model and "homepage_modules_json" in distribution_controller,
            "home_carousel_slides": "home_carousel_slides" in channel_model and "home_carousel_slides_json" in distribution_controller,
            "frontend_experience_mode": "frontendExperienceMode" in channel_model,
            "article_text_ads": "article_text_ads" in channel_model,
        },
        "admin_ui": {
            "frontend_experience_section": "前台体验" in edit_view or "frontend_experience_mode" in edit_view,
            "json_import_fields": all(field in edit_view for field in [
                "homepage_style_json",
                "homepage_modules_json",
                "home_carousel_slides_json",
            ]),
        },
        "target_package": {
            "homepage_renderer": "function renderHomepageModules" in target_package,
            "capability_endpoint": "/geoflow-agent/v1/frontend-capabilities" in target_package,
            "supports_homepage_style": "'homepage_style'" in target_package,
            "supports_homepage_modules": "'homepage_modules'" in target_package,
            "supports_home_carousel_slides": "'home_carousel_slides'" in target_package,
        },
        "capability_inventory": {
            "service_present": inspector.is_file(),
            "artisan_command_present": command.is_file(),
            "command": "php artisan geoflow:frontend-experience {channel?} --json" if command.is_file() else "",
        },
        "notes": [
            "GeoFlow Agent is the first-class channel frontend renderer.",
            "WordPress REST and Generic API channels should be treated as external distribution targets.",
            "Use channel JSON settings and signed sync instead of editing remote PHP templates.",
        ] if channel_model else [],
    }


def detect_workspace(workspace: Path) -> dict:
    laravel_root = workspace / "resources" / "views" / "theme"
    legacy_root = workspace / "themes"
    laravel_signals = {
        "artisan": (workspace / "artisan").is_file(),
        "routes_web": (workspace / "routes" / "web.php").is_file(),
        "site_views": (workspace / "resources" / "views" / "site").is_dir(),
        "theme_views": laravel_root.is_dir(),
        "theme_resolver": (workspace / "app" / "Support" / "Site" / "SiteThemeViewResolver.php").is_file(),
    }
    legacy_signals = {
        "themes_root_exists": legacy_root.is_dir(),
        "theme_preview_php": (workspace / "includes" / "theme_preview.php").is_file(),
        "theme_preview_entry": (workspace / "theme-preview.php").is_file(),
        "admin_theme_settings": (workspace / "admin" / "site-settings.php").is_file(),
    }

    if laravel_signals["theme_views"] or (laravel_signals["artisan"] and laravel_signals["site_views"]):
        return {
            "framework": "laravel",
            "themes_root": laravel_root,
            "theme_system_detected": laravel_signals["artisan"] and laravel_signals["site_views"] and laravel_signals["theme_views"],
            "theme_system_signals": laravel_signals,
            "preview_support": "admin_activation_or_static_preview",
            "theme_editor": detect_theme_editor(workspace),
            "homepage_module_builder": detect_homepage_module_builder(workspace),
            "homepage_contract": detect_homepage_contract(workspace),
            "channel_frontend_contract": detect_channel_frontend_contract(workspace),
        }

    return {
        "framework": "legacy_php",
        "themes_root": legacy_root,
        "theme_system_detected": all(legacy_signals.values()),
        "theme_system_signals": legacy_signals,
        "preview_support": "legacy_preview_routes",
        "theme_editor": {},
        "homepage_module_builder": {},
        "homepage_contract": {},
        "channel_frontend_contract": {},
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Discover themes in a GEOFlow workspace.")
    parser.add_argument("workspace", help="Path to the GEOFlow workspace")
    args = parser.parse_args()

    workspace = Path(args.workspace).resolve()
    detected = detect_workspace(workspace)
    themes_root = detected["themes_root"]

    themes = []
    if themes_root.is_dir():
        for child in sorted(themes_root.iterdir()):
            if child.is_dir():
                themes.append(theme_record(workspace, child, str(detected["framework"])))

    report = {
        "workspace": str(workspace),
        "framework": detected["framework"],
        "themes_root": str(themes_root),
        "theme_system_detected": detected["theme_system_detected"],
        "theme_system_signals": detected["theme_system_signals"],
        "preview_support": detected["preview_support"],
        "theme_editor": detected["theme_editor"],
        "homepage_module_builder": detected.get("homepage_module_builder", {}),
        "homepage_contract": detected.get("homepage_contract", {}),
        "channel_frontend_contract": detected.get("channel_frontend_contract", {}),
        "theme_count": len(themes),
        "themes": themes,
    }
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
