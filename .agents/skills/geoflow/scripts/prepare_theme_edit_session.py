#!/usr/bin/env python3
# Copyright © 2026 姚金刚. All rights reserved.
# Project: geoflow
# Created by: 姚金刚
# Date: 2026-05-16
# X: https://x.com/yaojingang

import argparse
import json
import re
import shutil
from datetime import datetime
from pathlib import Path


THEME_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$")


def bounded_workspace_path(workspace: Path, candidate: Path, label: str) -> Path:
    workspace_input = workspace.expanduser().absolute()
    workspace_root = workspace.resolve()
    candidate_input = candidate.expanduser().absolute()
    relative = None
    for root in (workspace_input, workspace_root):
        try:
            relative = candidate_input.relative_to(root)
            break
        except ValueError:
            continue
    if relative is None:
        raise SystemExit(f"{label} must be inside the workspace")

    normalized_candidate = workspace_root / relative

    current = workspace_root
    for part in relative.parts:
        current = current / part
        if current.is_symlink():
            raise SystemExit(f"{label} must not contain symbolic-link path components: {current}")

    try:
        normalized_candidate.resolve().relative_to(workspace_root)
    except ValueError as exc:
        raise SystemExit(f"{label} resolves outside the workspace") from exc
    return normalized_candidate


def require_theme_id(value: str, label: str) -> str:
    theme_id = value.strip()
    if not THEME_ID_PATTERN.fullmatch(theme_id):
        raise SystemExit(f"{label} must be 1-100 letters, numbers, underscores, or hyphens")
    return theme_id


def bounded_theme_path(themes_root: Path, theme_id: str, label: str) -> Path:
    validated_id = require_theme_id(theme_id, label)
    root = themes_root.resolve()
    candidate = themes_root / validated_id
    try:
        candidate.resolve().relative_to(root)
    except ValueError as exc:
        raise SystemExit(f"{label} resolves outside the themes directory") from exc
    return candidate


def reject_symlinks(root: Path, label: str) -> None:
    if root.is_symlink():
        raise SystemExit(f"{label} must not be a symbolic link: {root}")
    for path in root.rglob("*"):
        if path.is_symlink():
            raise SystemExit(f"{label} contains a symbolic link: {path}")
        if not path.is_dir() and not path.is_file():
            raise SystemExit(f"{label} contains an unsupported filesystem entry: {path}")


def load_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise SystemExit(f"Invalid JSON object: {path}") from exc
    if not isinstance(payload, dict):
        raise SystemExit(f"JSON file must contain an object: {path}")
    return payload


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sanitize_theme_id(value: str) -> str:
    value = value.strip().lower()
    value = re.sub(r"[^a-z0-9_-]+", "-", value)
    value = re.sub(r"-{2,}", "-", value).strip("-")
    value = value[:100].rstrip("-_")
    return value or "theme-edit"


def recursive_replace(value, old: str, new: str):
    if isinstance(value, str):
        return value.replace(old, new)
    if isinstance(value, list):
        return [recursive_replace(item, old, new) for item in value]
    if isinstance(value, dict):
        return {key: recursive_replace(item, old, new) for key, item in value.items()}
    return value


def replace_text_in_files(root: Path, old: str, new: str) -> None:
    if old == new:
        return

    reject_symlinks(root, "Theme tree")
    text_suffixes = {".php", ".css", ".js", ".json", ".md", ".txt"}
    for path in root.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in text_suffixes:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        if old in text:
            path.write_text(text.replace(old, new), encoding="utf-8")


def build_preview_routes(theme_id: str) -> list[str]:
    return [
        "/",
        "/category/{slug}",
        "/article/{slug}",
        "/archive",
    ]


def main() -> None:
    parser = argparse.ArgumentParser(description="Create a preview edit session for an existing GEOFlow theme.")
    parser.add_argument("workspace", help="Path to the GEOFlow workspace")
    parser.add_argument("--base-theme", required=True, help="Existing theme id to fork")
    parser.add_argument("--new-theme-id", help="Preview theme id; auto-generated when omitted")
    parser.add_argument("--new-name", help="Preview theme display name")
    parser.add_argument("--change-request", default="", help="Short summary of requested frontend changes")
    args = parser.parse_args()

    workspace = Path(args.workspace).resolve()
    laravel_themes_root = bounded_workspace_path(
        workspace,
        workspace / "resources" / "views" / "theme",
        "Laravel themes directory",
    )
    legacy_themes_root = bounded_workspace_path(workspace, workspace / "themes", "Legacy themes directory")
    themes_root = laravel_themes_root if laravel_themes_root.is_dir() else legacy_themes_root
    framework = "laravel" if themes_root == laravel_themes_root else "legacy_php"
    base_theme_id = require_theme_id(args.base_theme, "Base theme id")
    base_dir = bounded_theme_path(themes_root, base_theme_id, "Base theme id")
    if not base_dir.is_dir():
        raise SystemExit(f"Base theme not found: {base_dir}")
    reject_symlinks(base_dir, "Base theme")
    base_manifest = load_json(base_dir / "manifest.json")

    timestamp = datetime.now().strftime("%Y%m%d%H%M%S")
    auto_preview_id = f"{base_theme_id[:70]}-edit-{timestamp}"
    preview_theme_id = sanitize_theme_id(args.new_theme_id or auto_preview_id)
    preview_dir = bounded_theme_path(themes_root, preview_theme_id, "Preview theme id")
    if preview_dir.exists():
        raise SystemExit(f"Preview theme already exists: {preview_dir}")

    base_public_assets = None
    preview_public_assets = None
    if framework == "laravel":
        public_themes_root = bounded_workspace_path(workspace, workspace / "public" / "themes", "Public themes directory")
        base_public_assets = bounded_theme_path(public_themes_root, base_theme_id, "Base public theme id")
        preview_public_assets = bounded_theme_path(public_themes_root, preview_theme_id, "Preview public theme id")
        if preview_public_assets.exists():
            raise SystemExit(f"Preview public assets already exist: {preview_public_assets}")
        if base_public_assets.is_dir():
            reject_symlinks(base_public_assets, "Base public theme")

    shutil.copytree(base_dir, preview_dir, symlinks=True)
    replace_text_in_files(preview_dir, base_theme_id, preview_theme_id)

    public_assets_copied = False
    public_assets_dir = ""
    if framework == "laravel" and base_public_assets is not None and preview_public_assets is not None:
        if base_public_assets.is_dir():
            try:
                shutil.copytree(base_public_assets, preview_public_assets, symlinks=True)
                replace_text_in_files(preview_public_assets, base_theme_id, preview_theme_id)
            except BaseException:
                if preview_public_assets.exists() and not preview_public_assets.is_symlink():
                    shutil.rmtree(preview_public_assets)
                if preview_dir.exists() and not preview_dir.is_symlink():
                    shutil.rmtree(preview_dir)
                raise
            public_assets_copied = True
            public_assets_dir = str(preview_public_assets)

    manifest_path = preview_dir / "manifest.json"
    manifest = load_json(manifest_path)
    preview_name = args.new_name or f"{base_manifest.get('name', base_theme_id)} Preview Edit"
    manifest = recursive_replace(manifest, base_theme_id, preview_theme_id)
    manifest["name"] = preview_name
    manifest["base_theme_id"] = base_theme_id
    manifest["target_theme_id"] = base_theme_id
    manifest["mode"] = "edit_theme"
    manifest["session_state"] = "preview"
    manifest["created_at"] = datetime.now().astimezone().isoformat(timespec="seconds")
    manifest["preview_routes"] = build_preview_routes(preview_theme_id)
    notes = manifest.get("notes")
    if not isinstance(notes, list):
        notes = []
    notes.extend([
        f"Preview edit session forked from {base_theme_id}.",
        "Do not activate before review.",
    ])
    if args.change_request.strip():
        notes.append(f"Requested changes: {args.change_request.strip()}")
    manifest["notes"] = notes
    write_json(manifest_path, manifest)

    editable_files = []
    if framework == "laravel":
        for path in sorted(preview_dir.rglob("*.blade.php")):
            editable_files.append(path.relative_to(preview_dir).as_posix())
    else:
        for path in sorted((preview_dir / "templates").glob("*.php")):
            editable_files.append(path.relative_to(preview_dir).as_posix())
    for relative in ("assets/theme.css", "manifest.json", "tokens.json", "mapping.json"):
        if (preview_dir / relative).is_file():
            editable_files.append(relative)
    if public_assets_copied:
        for relative in ("theme.css", "theme.js"):
            public_relative = f"public/themes/{preview_theme_id}/{relative}"
            if (workspace / public_relative).is_file():
                editable_files.append(public_relative)

    session_payload = {
        "base_theme_id": base_theme_id,
        "preview_theme_id": preview_theme_id,
        "framework": framework,
        "created_at": datetime.now().astimezone().isoformat(timespec="seconds"),
        "session_state": "preview",
        "change_request": args.change_request.strip(),
        "preview_routes": build_preview_routes(preview_theme_id),
        "preview_note": "Laravel GEOFlow does not expose isolated /preview/{theme} routes by default; use static previews or activate the preview theme only after operator confirmation.",
        "public_assets_dir": public_assets_dir,
        "public_assets_copied": public_assets_copied,
        "editable_files": editable_files,
        "finalize_options": [
            "publish_as_new_theme",
            "replace_base_theme",
            "activate_after_confirmation",
        ],
        "warnings": [
            "Preview the fork before any live replacement.",
            "Do not change business logic or data contracts in this session.",
        ],
    }
    write_json(preview_dir / "edit-session.json", session_payload)

    change_plan = preview_dir / "change-plan.md"
    change_plan.write_text(
        "\n".join([
            "# Theme Edit Session",
            "",
            f"- Base theme: `{base_theme_id}`",
            f"- Preview theme: `{preview_theme_id}`",
            f"- Framework: `{framework}`",
            f"- Requested changes: {args.change_request.strip() or 'TBD'}",
            "- Finalize options: `publish_as_new_theme` | `replace_base_theme` | `activate_after_confirmation`",
            "",
            "## Preview Checklist",
            "",
            "- check home/category/article/archive preview routes",
            "- for Laravel GEOFlow, confirm whether preview is static or temporarily activated through Site Settings",
            "- verify layout, typography, spacing, and module hierarchy",
            "- confirm GEOFlow data placeholders still render correctly",
        ]) + "\n",
        encoding="utf-8",
    )

    preview_notes = preview_dir / "preview-notes.md"
    if not preview_notes.exists():
        preview_notes.write_text("# Preview Notes\n\n- pending review\n", encoding="utf-8")

    result = {
        "workspace": str(workspace),
        "base_theme_id": base_theme_id,
        "preview_theme_id": preview_theme_id,
        "framework": framework,
        "preview_theme_path": str(preview_dir),
        "public_assets_path": public_assets_dir,
        "preview_routes": build_preview_routes(preview_theme_id),
        "preview_support": "admin_activation_or_static_preview" if framework == "laravel" else "legacy_preview_routes",
        "editable_files": editable_files,
    }
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
