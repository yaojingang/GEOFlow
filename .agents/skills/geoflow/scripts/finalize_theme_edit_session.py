#!/usr/bin/env python3
# Copyright © 2026 姚金刚. All rights reserved.
# Project: geoflow
# Created by: 姚金刚
# Date: 2026-05-16
# X: https://x.com/yaojingang

import argparse
import hashlib
import json
import re
import shutil
import tempfile
from datetime import datetime
from pathlib import Path
from typing import Optional
from uuid import uuid4


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


def persistent_external_backup_root(workspace: Path, requested_parent: Path) -> Path:
    requested_input = requested_parent.expanduser().absolute()
    if requested_input.is_symlink():
        raise SystemExit(f"Legacy backup parent must not be a symbolic link: {requested_input}")
    backup_parent = requested_input.resolve()
    workspace_root = workspace.resolve()
    try:
        backup_parent.relative_to(workspace_root)
    except ValueError:
        pass
    else:
        raise SystemExit("Legacy backup parent must be outside the web workspace")

    temp_root = Path(tempfile.gettempdir()).resolve()
    try:
        backup_parent.relative_to(temp_root)
    except ValueError:
        pass
    else:
        raise SystemExit("Legacy backup parent must be persistent and outside the system temporary directory")
    if backup_parent == Path(backup_parent.anchor):
        raise SystemExit("Legacy backup parent must not be a filesystem root")

    backup_parent.mkdir(parents=True, exist_ok=True)
    if backup_parent.is_symlink() or not backup_parent.is_dir():
        raise SystemExit(f"Legacy backup parent is unsafe: {backup_parent}")

    workspace_slug = re.sub(r"[^a-z0-9_-]+", "-", workspace_root.name.lower()).strip("-") or "workspace"
    workspace_digest = hashlib.sha256(str(workspace_root).encode("utf-8")).hexdigest()[:8]
    backup_root = backup_parent / f"geoflow-theme-backups-{workspace_slug[:48]}-{workspace_digest}"
    if backup_root.is_symlink():
        raise SystemExit(f"Legacy backup directory must not be a symbolic link: {backup_root}")
    backup_root.mkdir(parents=True, exist_ok=True, mode=0o700)
    if not backup_root.is_dir():
        raise SystemExit(f"Legacy backup directory is unsafe: {backup_root}")
    backup_root.chmod(0o700)
    return backup_root


def acquire_finalize_lock(workspace: Path, laravel: bool) -> Path:
    relative_root = (
        Path("storage/app/private/geoflow-theme-locks")
        if laravel
        else Path(".geoflow-theme-locks")
    )
    lock_root = bounded_workspace_path(workspace, workspace / relative_root, "Theme finalize lock directory")
    lock_root.mkdir(parents=True, exist_ok=True, mode=0o700)
    if lock_root.is_symlink() or not lock_root.is_dir():
        raise SystemExit(f"Theme finalize lock directory is unsafe: {lock_root}")
    lock_root.chmod(0o700)
    lock_path = lock_root / "finalize.lock"
    try:
        lock_path.mkdir(mode=0o700)
    except FileExistsError as exc:
        raise SystemExit(
            f"Another theme finalizer may be running. Inspect the exclusive lock before retrying: {lock_path}"
        ) from exc
    return lock_path


def release_finalize_lock(lock_path: Path) -> None:
    lock_path.rmdir()


def reject_symlinks(root: Path, label: str) -> None:
    if root.is_symlink():
        raise SystemExit(f"{label} must not be a symbolic link: {root}")
    for path in root.rglob("*"):
        if path.is_symlink():
            raise SystemExit(f"{label} contains a symbolic link: {path}")
        if not path.is_dir() and not path.is_file():
            raise SystemExit(f"{label} contains an unsupported filesystem entry: {path}")


class DirectoryRollbackError(RuntimeError):
    """Raised when a directory transaction cannot fully restore live paths."""


def path_exists(path: Path) -> bool:
    return path.exists() or path.is_symlink()


def unique_theme_path(themes_root: Path, theme_id: str, operation: str) -> Path:
    prefix_length = 100 - len(operation) - 16
    if prefix_length < 1:
        raise SystemExit(f"Internal operation name is too long: {operation}")
    prefix = theme_id[:prefix_length]
    for _ in range(10):
        internal_id = f"{prefix}__{operation}__{uuid4().hex[:12]}"
        candidate = bounded_theme_path(themes_root, internal_id, f"{operation} path")
        if not path_exists(candidate):
            return candidate
    raise SystemExit(f"Could not allocate a unique {operation} path for theme: {theme_id}")


def remove_created_tree(path: Path) -> None:
    if not path_exists(path):
        return
    if path.is_symlink():
        raise DirectoryRollbackError(f"Refusing to remove symbolic-link transaction path: {path}")
    shutil.rmtree(path)


def commit_staged_directories(replacements: list[tuple[Path, Path, Path]]) -> list[str]:
    """Commit staged directories as one rollback-capable transaction."""

    states = []
    for staged, live, rollback in replacements:
        if staged.parent != live.parent or rollback.parent != live.parent:
            raise SystemExit("Staged, live, and rollback directories must share a parent")
        if not staged.is_dir() or staged.is_symlink():
            raise SystemExit(f"Staged directory is missing or unsafe: {staged}")
        if live.is_symlink():
            raise SystemExit(f"Live directory must not be a symbolic link: {live}")
        if path_exists(live) and not live.is_dir():
            raise SystemExit(f"Live path must be a directory: {live}")
        if path_exists(rollback):
            raise SystemExit(f"Rollback directory already exists: {rollback}")
        states.append({
            "staged": staged,
            "live": live,
            "rollback": rollback,
            "old_moved": False,
            "staged_moved": False,
        })

    try:
        for state in states:
            live = state["live"]
            if live.is_dir():
                live.rename(state["rollback"])
                state["old_moved"] = True
            state["staged"].rename(live)
            state["staged_moved"] = True
    except BaseException as exc:
        rollback_errors = []
        for state in reversed(states):
            try:
                live = state["live"]
                if state["staged_moved"] and live.is_dir():
                    live.rename(state["staged"])
                if state["old_moved"] and state["rollback"].is_dir():
                    if path_exists(live):
                        raise OSError(f"Live path still exists during rollback: {live}")
                    state["rollback"].rename(live)
            except BaseException as rollback_exc:
                rollback_errors.append(f"{state['live']}: {rollback_exc}")
        if rollback_errors:
            raise DirectoryRollbackError(
                "Directory transaction failed and rollback is incomplete: " + "; ".join(rollback_errors)
            ) from exc
        raise

    cleanup_pending = []
    for state in states:
        rollback = state["rollback"]
        if not path_exists(rollback):
            continue
        try:
            remove_created_tree(rollback)
        except OSError:
            cleanup_pending.append(str(rollback))
    return cleanup_pending


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


def load_required_json(path: Path, label: str) -> dict:
    if not path.is_file():
        raise SystemExit(f"{label} is required: {path}")
    return load_json(path)


def validate_session_identity(session: dict, preview_theme_id: str, base_theme_id: Optional[str] = None) -> None:
    recorded_preview = str(session.get("preview_theme_id") or "").strip()
    if recorded_preview and recorded_preview != preview_theme_id:
        raise SystemExit(
            f"Edit session preview_theme_id mismatch: expected {preview_theme_id}, found {recorded_preview}"
        )
    recorded_base = str(session.get("base_theme_id") or "").strip()
    if base_theme_id and recorded_base and recorded_base != base_theme_id:
        raise SystemExit(
            f"Edit session base_theme_id mismatch: expected {base_theme_id}, found {recorded_base}"
        )


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sanitize_theme_id(value: str) -> str:
    value = value.strip().lower()
    value = re.sub(r"[^a-z0-9_-]+", "-", value)
    value = re.sub(r"-{2,}", "-", value).strip("-")
    value = value[:100].rstrip("-_")
    return value or "theme"


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


def _publish_as_new_unlocked(workspace: Path, themes_root: Path, preview_theme_id: str, new_theme_id: Optional[str], new_name: Optional[str]) -> dict:
    preview_theme_id = require_theme_id(preview_theme_id, "Preview theme id")
    preview_dir = bounded_theme_path(themes_root, preview_theme_id, "Preview theme id")
    reject_symlinks(preview_dir, "Preview theme")
    load_required_json(preview_dir / "manifest.json", "Preview theme manifest")
    preview_session = load_json(preview_dir / "edit-session.json")
    validate_session_identity(preview_session, preview_theme_id)
    target_theme_id = sanitize_theme_id(new_theme_id or preview_theme_id)
    target_dir = bounded_theme_path(themes_root, target_theme_id, "Target theme id")
    if target_dir.exists() and target_theme_id != preview_theme_id:
        raise SystemExit(f"Target theme already exists: {target_dir}")

    public_themes_root = bounded_workspace_path(workspace, workspace / "public" / "themes", "Public themes directory")
    preview_public_dir = bounded_theme_path(public_themes_root, preview_theme_id, "Preview public theme id")
    target_public_dir = bounded_theme_path(public_themes_root, target_theme_id, "Target public theme id")
    if target_theme_id != preview_theme_id and target_public_dir.exists():
        raise SystemExit(f"Target public assets already exist: {target_public_dir}")
    has_public_assets = preview_public_dir.is_dir()
    if has_public_assets:
        reject_symlinks(preview_public_dir, "Preview public theme")
    theme_stage = unique_theme_path(themes_root, target_theme_id, "publish_stage")
    theme_rollback = unique_theme_path(themes_root, target_theme_id, "publish_rollback")
    public_stage = None
    public_rollback = None
    created_stages = [theme_stage]

    try:
        shutil.copytree(preview_dir, theme_stage, symlinks=True)
        replace_text_in_files(theme_stage, preview_theme_id, target_theme_id)

        manifest_path = theme_stage / "manifest.json"
        manifest = recursive_replace(load_json(manifest_path), preview_theme_id, target_theme_id)
        manifest["session_state"] = "published"
        manifest["mode"] = "theme"
        if new_name:
            manifest["name"] = new_name
        write_json(manifest_path, manifest)

        session_path = theme_stage / "edit-session.json"
        session_payload = recursive_replace(load_json(session_path), preview_theme_id, target_theme_id)
        if session_payload:
            session_payload["session_state"] = "published"
            write_json(session_path, session_payload)

        replacements = [(theme_stage, target_dir, theme_rollback)]
        if has_public_assets:
            public_stage = unique_theme_path(public_themes_root, target_theme_id, "publish_stage")
            public_rollback = unique_theme_path(public_themes_root, target_theme_id, "publish_rollback")
            created_stages.append(public_stage)
            shutil.copytree(preview_public_dir, public_stage, symlinks=True)
            replace_text_in_files(public_stage, preview_theme_id, target_theme_id)
            replacements.append((public_stage, target_public_dir, public_rollback))

        cleanup_pending = commit_staged_directories(replacements)
    except DirectoryRollbackError:
        raise
    except BaseException:
        for path in reversed(created_stages):
            remove_created_tree(path)
        raise

    if target_theme_id != preview_theme_id:
        for source in (preview_public_dir, preview_dir):
            if not path_exists(source):
                continue
            try:
                remove_created_tree(source)
            except OSError:
                cleanup_pending.append(str(source))

    public_assets_path = str(target_public_dir) if has_public_assets else ""

    return {
        "mode": "publish_as_new_theme",
        "theme_id": target_theme_id,
        "theme_path": str(target_dir),
        "public_assets_path": public_assets_path,
        "cleanup_pending": cleanup_pending,
        "next_step": "Review the new theme in admin/site-settings and activate it only after approval.",
    }


def publish_as_new(
    workspace: Path,
    themes_root: Path,
    preview_theme_id: str,
    new_theme_id: Optional[str],
    new_name: Optional[str],
) -> dict:
    laravel_root = bounded_workspace_path(
        workspace,
        workspace / "resources" / "views" / "theme",
        "Laravel themes directory",
    )
    finalize_lock = acquire_finalize_lock(workspace, themes_root.resolve() == laravel_root.resolve())
    preserve_lock = False
    try:
        return _publish_as_new_unlocked(workspace, themes_root, preview_theme_id, new_theme_id, new_name)
    except DirectoryRollbackError:
        preserve_lock = True
        raise
    finally:
        if not preserve_lock:
            release_finalize_lock(finalize_lock)


def _replace_base_unlocked(
    themes_root: Path,
    workspace: Path,
    preview_theme_id: str,
    base_theme_id: Optional[str],
    new_name: Optional[str],
    confirm_live_risk: bool,
    backup_root: Optional[Path] = None,
) -> dict:
    if not confirm_live_risk:
        raise SystemExit("replace_base_theme requires --confirm-live-risk")

    preview_theme_id = require_theme_id(preview_theme_id, "Preview theme id")
    preview_dir = bounded_theme_path(themes_root, preview_theme_id, "Preview theme id")
    reject_symlinks(preview_dir, "Preview theme")
    load_required_json(preview_dir / "manifest.json", "Preview theme manifest")
    session_payload = load_json(preview_dir / "edit-session.json")
    resolved_base = base_theme_id or session_payload.get("base_theme_id")
    if not resolved_base:
        raise SystemExit("Base theme id is required for replace_base_theme")

    resolved_base = require_theme_id(str(resolved_base), "Base theme id")
    validate_session_identity(session_payload, preview_theme_id, resolved_base)
    base_dir = bounded_theme_path(themes_root, resolved_base, "Base theme id")
    if not base_dir.is_dir():
        raise SystemExit(f"Base theme not found: {base_dir}")
    reject_symlinks(base_dir, "Base theme")

    laravel_themes_root = bounded_workspace_path(
        workspace,
        workspace / "resources" / "views" / "theme",
        "Laravel themes directory",
    )
    if themes_root.resolve() == laravel_themes_root.resolve():
        backups_root = bounded_workspace_path(
            workspace,
            workspace / "storage" / "app" / "private" / "geoflow-theme-backups",
            "Theme backups directory",
        )
        backups_root.mkdir(parents=True, exist_ok=True, mode=0o700)
        backups_root.chmod(0o700)
    else:
        if backup_root is None:
            raise SystemExit("Legacy replace_base_theme requires --backup-root outside the web workspace")
        backups_root = persistent_external_backup_root(workspace, backup_root)
    timestamp = datetime.now().strftime("%Y%m%d%H%M%S")
    backup_suffix = f"{timestamp}-{uuid4().hex[:8]}"
    backup_dir = backups_root / f"{resolved_base}-{backup_suffix}"
    shutil.copytree(base_dir, backup_dir, symlinks=True)
    try:
        reject_symlinks(backup_dir, "Theme backup")
    except BaseException:
        remove_created_tree(backup_dir)
        raise

    public_themes_root = bounded_workspace_path(workspace, workspace / "public" / "themes", "Public themes directory")
    base_public_dir = bounded_theme_path(public_themes_root, resolved_base, "Base public theme id")
    preview_public_dir = bounded_theme_path(public_themes_root, preview_theme_id, "Preview public theme id")
    public_backup_dir = backups_root / f"{resolved_base}-public-{backup_suffix}"
    if base_public_dir.is_dir():
        reject_symlinks(base_public_dir, "Base public theme")
        shutil.copytree(base_public_dir, public_backup_dir, symlinks=True)
        try:
            reject_symlinks(public_backup_dir, "Public theme backup")
        except BaseException:
            remove_created_tree(public_backup_dir)
            raise

    theme_stage = unique_theme_path(themes_root, resolved_base, "replace_stage")
    theme_rollback = unique_theme_path(themes_root, resolved_base, "replace_rollback")
    public_stage = None
    created_stages = [theme_stage]

    try:
        shutil.copytree(preview_dir, theme_stage, symlinks=True)
        replace_text_in_files(theme_stage, preview_theme_id, resolved_base)

        manifest_path = theme_stage / "manifest.json"
        manifest = recursive_replace(load_json(manifest_path), preview_theme_id, resolved_base)
        manifest["session_state"] = "replaced"
        manifest["mode"] = "theme"
        manifest["base_theme_id"] = resolved_base
        manifest["target_theme_id"] = resolved_base
        if new_name:
            manifest["name"] = new_name
        write_json(manifest_path, manifest)

        session_path = theme_stage / "edit-session.json"
        session_payload = recursive_replace(load_json(session_path), preview_theme_id, resolved_base)
        if session_payload:
            session_payload["session_state"] = "replaced"
            write_json(session_path, session_payload)

        replacements = [(theme_stage, base_dir, theme_rollback)]
        if preview_public_dir.is_dir():
            reject_symlinks(preview_public_dir, "Preview public theme")
            public_stage = unique_theme_path(public_themes_root, resolved_base, "replace_stage")
            public_rollback = unique_theme_path(public_themes_root, resolved_base, "replace_rollback")
            created_stages.append(public_stage)
            shutil.copytree(preview_public_dir, public_stage, symlinks=True)
            replace_text_in_files(public_stage, preview_theme_id, resolved_base)
            replacements.append((public_stage, base_public_dir, public_rollback))

        cleanup_pending = commit_staged_directories(replacements)
    except DirectoryRollbackError:
        raise
    except BaseException:
        for path in reversed(created_stages):
            remove_created_tree(path)
        raise

    public_assets_path = str(base_public_dir) if preview_public_dir.is_dir() else ""

    return {
        "mode": "replace_base_theme",
        "replaced_theme_id": resolved_base,
        "backup_path": str(backup_dir),
        "public_backup_path": str(public_backup_dir) if public_backup_dir.exists() else "",
        "public_assets_path": public_assets_path,
        "cleanup_pending": cleanup_pending,
        "warning": "If the replaced theme is active, the live site may reflect the change immediately.",
    }


def replace_base(
    themes_root: Path,
    workspace: Path,
    preview_theme_id: str,
    base_theme_id: Optional[str],
    new_name: Optional[str],
    confirm_live_risk: bool,
    backup_root: Optional[Path] = None,
) -> dict:
    laravel_root = bounded_workspace_path(
        workspace,
        workspace / "resources" / "views" / "theme",
        "Laravel themes directory",
    )
    finalize_lock = acquire_finalize_lock(workspace, themes_root.resolve() == laravel_root.resolve())
    preserve_lock = False
    try:
        return _replace_base_unlocked(
            themes_root,
            workspace,
            preview_theme_id,
            base_theme_id,
            new_name,
            confirm_live_risk,
            backup_root,
        )
    except DirectoryRollbackError:
        preserve_lock = True
        raise
    finally:
        if not preserve_lock:
            release_finalize_lock(finalize_lock)


def main() -> None:
    parser = argparse.ArgumentParser(description="Finalize a GEOFlow preview theme edit session.")
    parser.add_argument("workspace", help="Path to the GEOFlow workspace")
    parser.add_argument("--preview-theme", required=True, help="Preview theme id")
    parser.add_argument("--mode", required=True, choices=["publish_as_new_theme", "replace_base_theme"])
    parser.add_argument("--base-theme", help="Base theme id; used by replace_base_theme")
    parser.add_argument("--new-theme-id", help="Stable theme id for publish_as_new_theme")
    parser.add_argument("--new-name", help="Theme display name after finalization")
    parser.add_argument("--backup-root", help="Persistent backup parent outside a legacy PHP web workspace")
    parser.add_argument("--confirm-live-risk", action="store_true", help="Required when replacing a base theme")
    args = parser.parse_args()

    workspace = Path(args.workspace).resolve()
    laravel_themes_root = bounded_workspace_path(
        workspace,
        workspace / "resources" / "views" / "theme",
        "Laravel themes directory",
    )
    legacy_themes_root = bounded_workspace_path(workspace, workspace / "themes", "Legacy themes directory")
    themes_root = laravel_themes_root if laravel_themes_root.is_dir() else legacy_themes_root
    preview_theme_id = require_theme_id(args.preview_theme, "Preview theme id")
    preview_dir = bounded_theme_path(themes_root, preview_theme_id, "Preview theme id")
    if not preview_dir.is_dir():
        raise SystemExit(f"Preview theme not found: {preview_dir}")

    if args.mode == "publish_as_new_theme":
        result = publish_as_new(workspace, themes_root, preview_theme_id, args.new_theme_id, args.new_name)
    else:
        result = replace_base(
            themes_root,
            workspace,
            preview_theme_id,
            args.base_theme,
            args.new_name,
            args.confirm_live_risk,
            Path(args.backup_root) if args.backup_root else None,
        )

    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
