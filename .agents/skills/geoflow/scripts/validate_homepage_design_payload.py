#!/usr/bin/env python3
import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any


MODULE_TYPES = {
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
}
ARTICLE_SOURCES = {"featured", "hot", "latest"}
LAYOUTS = {"single", "split", "grid", "compact"}
ALIGNMENTS = {"left", "center"}
STYLE_FIELDS = {
    "accent_color",
    "background_color",
    "surface_color",
    "text_color",
    "muted_color",
    "container_width",
    "section_spacing",
    "radius",
}
MODULE_ALIASES = {
    "type": ["kind", "module_type", "section_type", "block_type"],
    "layout": ["variant", "display", "template"],
    "data_source": ["source", "article_source", "feed"],
    "title": ["headline", "heading", "name"],
    "subtitle": ["eyebrow", "label", "kicker", "tagline"],
    "body": ["copy", "description", "content", "text"],
    "image_url": ["image", "imageUrl", "media_url", "media", "cover", "cover_url"],
    "link_text": ["cta_label", "button_text", "linkLabel", "action_text"],
    "link_url": ["cta_url", "button_url", "url", "href", "action_url"],
    "lead_form_slug": ["form_slug", "lead_form", "form", "conversion_form"],
    "limit": ["count", "article_limit", "items"],
    "sort_order": ["order", "position", "sort"],
    "custom_html": ["html", "markup"],
    "accent_color": ["accent", "primary", "primary_color", "brand_color"],
    "surface_color": ["surface", "card_background", "module_background"],
    "text_color": ["text_color", "foreground", "font_color"],
    "muted_color": ["muted", "secondary_text", "subtle_text"],
    "alignment": ["align", "text_align"],
    "enabled": ["is_enabled", "active"],
}


def load_payload(path: str | None) -> Any:
    raw = sys.stdin.read() if path in (None, "-") else Path(path).read_text(encoding="utf-8")
    return json.loads(raw)


def is_hex_color(value: str) -> bool:
    return re.fullmatch(r"#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})", value.strip()) is not None


def is_safe_url(value: str) -> bool:
    value = value.strip()
    if value == "":
        return True
    if value.startswith("//"):
        return False
    if value.startswith("/"):
        return True
    if re.match(r"^https?://", value, re.I):
        return True
    if re.match(r"^[a-z][a-z0-9+.-]*:", value, re.I):
        return False
    return True


def extract(payload: Any) -> tuple[dict[str, Any], list[Any]]:
    if isinstance(payload, list):
        return {}, payload
    if not isinstance(payload, dict):
        return {}, []
    style = payload.get("style") or payload.get("homepage_style") or payload.get("style_tokens") or payload.get("tokens") or {}
    modules = payload.get("modules") or payload.get("homepage_modules") or payload.get("sections") or payload.get("blocks") or []
    return style if isinstance(style, dict) else {}, modules if isinstance(modules, list) else []


def first_present(source: dict[str, Any], key: str) -> Any:
    if key in source and source[key] not in ("", None):
        return source[key]
    for alias in MODULE_ALIASES.get(key, []):
        if alias in source and source[alias] not in ("", None):
            return source[alias]
    return None


def map_module_type(value: Any) -> str:
    normalized = str(value or "rich_text").strip().lower().replace("-", "_").replace(" ", "_")
    aliases = {
        "hero_section": "hero",
        "banner": "hero",
        "jumbotron": "hero",
        "text": "rich_text",
        "richtext": "rich_text",
        "markdown": "rich_text",
        "copy_block": "rich_text",
        "content_block": "rich_text",
        "image": "image_band",
        "media_band": "image_band",
        "image_block": "image_band",
        "visual_band": "image_band",
        "metric": "metric_band",
        "metrics": "metric_band",
        "stats": "metric_band",
        "stat_band": "metric_band",
        "numbers": "metric_band",
        "chart": "chart_band",
        "charts": "chart_band",
        "bar_chart": "chart_band",
        "data_viz": "chart_band",
        "dataviz": "chart_band",
        "visualization": "chart_band",
        "feature": "feature_grid",
        "features": "feature_grid",
        "cards": "feature_grid",
        "feature_cards": "feature_grid",
        "article": "article_collection",
        "articles": "article_collection",
        "post_list": "article_collection",
        "feed": "article_collection",
        "collection": "article_collection",
        "article_list": "article_collection",
        "cta": "cta_band",
        "call_to_action": "cta_band",
        "conversion": "cta_band",
        "form": "lead_form",
        "lead": "lead_form",
        "conversion_form": "lead_form",
        "contact_form": "lead_form",
        "html": "custom_html",
        "custom": "custom_html",
        "raw_html": "custom_html",
    }
    return aliases.get(normalized, normalized)


def lead_form_slug(module: dict[str, Any]) -> str:
    settings = module.get("settings")
    candidates = [
        first_present(module, "lead_form_slug"),
    ]
    if isinstance(settings, dict):
        candidates.extend([
            settings.get("lead_form_slug"),
            settings.get("form_slug"),
            settings.get("lead_form"),
            settings.get("form"),
            settings.get("conversion_form"),
        ])
    for value in candidates:
        normalized = str(value or "").strip()
        if normalized:
            return normalized
    return ""


def validate(payload: Any) -> dict[str, Any]:
    errors: list[str] = []
    warnings: list[str] = []
    style, modules = extract(payload)

    for key, value in style.items():
        if key not in STYLE_FIELDS:
            warnings.append(f"Unknown style field: {key}")
        if key.endswith("_color") and str(value).strip() and not is_hex_color(str(value)):
            errors.append(f"Invalid color in style.{key}: {value}")

    if len(modules) > 30:
        errors.append("Too many modules; GEOFlow supports at most 30 homepage modules.")

    for index, module in enumerate(modules, start=1):
        if not isinstance(module, dict):
            errors.append(f"Module {index} is not an object.")
            continue

        module_type = map_module_type(first_present(module, "type"))
        if module_type not in MODULE_TYPES:
            errors.append(f"Module {index} has unsupported type: {module_type}")

        layout = str(first_present(module, "layout") or "single")
        if layout not in LAYOUTS:
            errors.append(f"Module {index} has unsupported layout: {layout}")

        data_source = str(first_present(module, "data_source") or "latest")
        if data_source not in ARTICLE_SOURCES:
            errors.append(f"Module {index} has unsupported data_source: {data_source}")

        alignment = str(first_present(module, "alignment") or "left")
        if alignment not in ALIGNMENTS:
            errors.append(f"Module {index} has unsupported alignment: {alignment}")

        for field in ("accent_color", "surface_color", "text_color", "muted_color"):
            value = str(first_present(module, field) or "").strip()
            if value and not is_hex_color(value):
                errors.append(f"Module {index} has invalid {field}: {value}")

        for field in ("image_url", "link_url"):
            value = str(first_present(module, field) or "").strip()
            if value and not is_safe_url(value):
                errors.append(f"Module {index} has unsafe {field}: {value}")

        if module_type == "article_collection" and data_source not in ARTICLE_SOURCES:
            errors.append(f"Module {index} article_collection must use featured, hot, or latest.")

        if module_type == "lead_form" and lead_form_slug(module) == "":
            errors.append(
                f"Module {index} lead_form requires lead_form_slug or settings.lead_form_slug for an existing active lead form."
            )

    return {
        "ok": errors == [],
        "errors": errors,
        "warnings": warnings,
        "style_fields": sorted(style.keys()),
        "module_count": len(modules),
        "module_types": [map_module_type(first_present(module, "type")) for module in modules if isinstance(module, dict)],
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Validate GEOFlow homepage-design JSON payload.")
    parser.add_argument("payload", nargs="?", help="Payload JSON path, or omit/read '-' for stdin")
    args = parser.parse_args()

    try:
        payload = load_payload(args.payload)
    except json.JSONDecodeError as exc:
        print(json.dumps({"ok": False, "errors": [f"Invalid JSON: {exc}"]}, ensure_ascii=False, indent=2))
        raise SystemExit(1)

    result = validate(payload)
    print(json.dumps(result, ensure_ascii=False, indent=2))
    raise SystemExit(0 if result["ok"] else 1)


if __name__ == "__main__":
    main()
