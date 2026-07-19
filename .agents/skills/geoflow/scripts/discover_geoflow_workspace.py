#!/usr/bin/env python3
"""Discover GEOFlow code surfaces without booting the application."""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Iterable


SCHEMA_VERSION = "geoflow-workspace-capabilities/v1"


def relative_files(root: Path, pattern: str) -> list[str]:
    return sorted(str(path.relative_to(root)) for path in root.glob(pattern) if path.is_file())


def existing(root: Path, paths: Iterable[str]) -> list[str]:
    return [path for path in paths if (root / path).exists()]


def capability(root: Path, *paths: str) -> dict:
    evidence = existing(root, paths)
    return {"available": bool(evidence), "evidence": evidence}


def directory_count(root: Path, path: str) -> int:
    target = root / path
    if not target.is_dir():
        return 0
    return sum(1 for item in target.iterdir() if item.is_dir())


def build_snapshot(root: Path) -> dict:
    controllers = relative_files(root, "app/Http/Controllers/**/*.php")
    models = relative_files(root, "app/Models/*.php")
    services = relative_files(root, "app/Services/**/*.php")
    commands = relative_files(root, "app/Console/Commands/*.php")
    migrations = relative_files(root, "database/migrations/*.php")
    tests = relative_files(root, "tests/**/*.php")

    framework = {
        "laravel": (root / "artisan").is_file(),
        "composer": (root / "composer.json").is_file(),
        "local_cli": (root / "bin/geoflow").is_file(),
        "legacy_root_php": any((root / name).is_file() for name in ("index.php", "article.php", "category.php", "archive.php")),
    }

    capabilities = {
        "api_v1": capability(root, "routes/api.php", "app/Http/Controllers/Api/V1"),
        "admin_web": capability(root, "resources/views/admin", "app/Http/Controllers/Admin"),
        "public_site": capability(root, "app/Http/Controllers/Site/HomeController.php", "resources/views/site"),
        "lead_capture": capability(root, "app/Http/Controllers/Site/LeadFormController.php", "app/Http/Controllers/Admin/LeadFormController.php", "app/Http/Controllers/Admin/LeadController.php"),
        "tasks_jobs_articles": capability(root, "app/Http/Controllers/Admin/TaskController.php", "app/Http/Controllers/Api/V1/TaskController.php", "app/Services/GeoFlow/JobQueueService.php", "app/Http/Controllers/Admin/ArticleController.php"),
        "materials": capability(root, "app/Http/Controllers/Api/V1/MaterialController.php", "app/Services/GeoFlow/MaterialLibraryService.php", "resources/views/admin/materials"),
        "enterprise_knowledge": capability(root, "app/Http/Controllers/Admin/EnterpriseKnowledgeController.php", "app/Services/GeoFlow/EnterpriseKnowledgeDraftService.php"),
        "analytics": capability(root, "app/Http/Controllers/Admin/AnalyticsController.php", "app/Services/Admin/Analytics"),
        "ai_visibility": capability(root, "app/Services/GeoFlow/AiVisibility", "app/Models/AiVisibilityRun.php", "app/Http/Controllers/Admin/AiSourceProviderController.php"),
        "distribution": capability(root, "app/Http/Controllers/Admin/DistributionController.php", "app/Services/GeoFlow/DistributionOrchestrator.php", "app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php"),
        "channel_frontend": capability(root, "app/Services/GeoFlow/FrontendExperienceInspector.php", "app/Services/GeoFlow/DistributionTargetSitePackageBuilder.php"),
        "url_import": capability(root, "app/Http/Controllers/Admin/UrlImportController.php", "app/Services/GeoFlow/UrlImportProcessingService.php"),
        "system_updates": capability(root, "app/Http/Controllers/Admin/SystemUpdateController.php", "app/Services/Admin/SystemUpdateApplyService.php"),
        "theme_catalog": capability(root, "app/Support/Site/SiteThemeCatalog.php", "resources/views/theme"),
        "theme_replication": capability(root, "app/Http/Controllers/Admin/SiteThemeReplicationController.php", "app/Services/Admin/SiteThemeReplication"),
        "theme_editor": capability(root, "app/Http/Controllers/Admin/SiteThemeEditorController.php", "app/Services/Admin/SiteThemeEditorService.php"),
        "homepage_builder": capability(root, "app/Support/Site/HomepageModuleBuilder.php", "resources/views/site/partials/homepage-modules.blade.php"),
        "queue_horizon": capability(root, "config/horizon.php", "app/Services/GeoFlow/HorizonMetricsAdapter.php", "app/Services/GeoFlow/WorkerExecutionService.php"),
    }

    warnings = []
    if not framework["laravel"] and not framework["local_cli"] and not framework["legacy_root_php"]:
        warnings.append("No Laravel artisan file, local GEOFlow CLI, or legacy root PHP frontend was detected.")
    if framework["laravel"] and not (root / "routes/web.php").is_file():
        warnings.append("Laravel was detected without routes/web.php.")

    return {
        "schema_version": SCHEMA_VERSION,
        "workspace": str(root),
        "framework": framework,
        "counts": {
            "controllers": len(controllers),
            "models": len(models),
            "services": len(services),
            "commands": len(commands),
            "migrations": len(migrations),
            "tests": len(tests),
            "themes": directory_count(root, "resources/views/theme"),
            "admin_view_groups": directory_count(root, "resources/views/admin"),
        },
        "routes": existing(root, ("routes/web.php", "routes/api.php", "routes/console.php", "routes/channels.php")),
        "capabilities": capabilities,
        "inventory": {
            "controllers": controllers,
            "models": models,
            "services": services,
            "commands": commands,
        },
        "warnings": warnings,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description="Discover GEOFlow workspace capability evidence.")
    parser.add_argument("workspace", help="Path to a GEOFlow source workspace")
    parser.add_argument("--output", help="Optional JSON output path; stdout is used when omitted")
    parser.add_argument("--compact", action="store_true", help="Emit compact JSON")
    args = parser.parse_args()

    root = Path(args.workspace).expanduser().resolve()
    if not root.is_dir():
        raise SystemExit(f"Workspace directory does not exist: {root}")

    snapshot = build_snapshot(root)
    indent = None if args.compact else 2
    rendered = json.dumps(snapshot, ensure_ascii=False, indent=indent) + "\n"

    if args.output:
        output = Path(args.output).expanduser().resolve()
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(rendered, encoding="utf-8")
        print(output)
        return

    print(rendered, end="")


if __name__ == "__main__":
    main()
