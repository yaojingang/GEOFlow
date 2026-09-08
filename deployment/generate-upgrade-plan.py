#!/usr/bin/env python3
"""Refresh the reviewed migration inventory; new or changed files remain offline."""
import argparse
import hashlib
import json
import re
import sys
from pathlib import Path


KINDS = {"migrate", "retrieval_backfill", "managed_images", "security_audit", "system_knowledge", "cache_warmup"}


def unique_object(pairs: list[tuple]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"Duplicate upgrade plan field: {key}")
        result[key] = value
    return result


def exact_fields(value, keys: set[str], label: str) -> None:
    if type(value) is not dict or set(value) != keys:
        raise ValueError(f"{label} must contain exactly its required fields")


def validate_plan(plan: dict) -> None:
    exact_fields(plan, {"schema_version", "strategy", "allowed_sources", "migrations", "compatibility", "steps"}, "Upgrade plan")
    if type(plan["schema_version"]) is not int or plan["schema_version"] != 1 or plan["strategy"] not in ("maintenance", "online"):
        raise ValueError("Upgrade plan schema or strategy is unsupported")
    sources = plan["allowed_sources"]
    if type(sources) is not list or any(type(source) is not int or source < 1 or source > 2**63 - 1 for source in sources) or len(sources) != len(set(sources)):
        raise ValueError("Upgrade source sequences must be unique positive application integers")
    compatible = plan["compatibility"]
    exact_fields(compatible, {"schema", "queue", "cache", "storage"}, "Compatibility")
    if any(type(value) is not bool for value in compatible.values()):
        raise ValueError("Compatibility fields must be booleans")
    if type(plan["migrations"]) is not list or type(plan["steps"]) is not list:
        raise ValueError("Migrations and steps must be arrays")
    names = set()
    for migration in plan["migrations"]:
        exact_fields(migration, {"name", "sha256", "online"}, "Migration")
        if type(migration["name"]) is not str or not re.fullmatch(r"[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+", migration["name"]) or migration["name"] in names:
            raise ValueError("Migration identity is invalid or duplicated")
        if type(migration["sha256"]) is not str or not re.fullmatch(r"[a-f0-9]{64}", migration["sha256"]) or type(migration["online"]) is not bool:
            raise ValueError("Migration hash or online flag is invalid")
        names.add(migration["name"])
    if not 1 <= len(plan["steps"]) <= 32:
        raise ValueError("Upgrade plan requires between one and 32 steps")
    ids, kinds = set(), set()
    for step in plan["steps"]:
        exact_fields(step, {"id", "kind", "phase", "timeout_seconds", "online"}, "Step")
        if type(step["id"]) is not str or not re.fullmatch(r"[a-z][a-z0-9_-]{0,63}", step["id"]) or step["id"] in ids:
            raise ValueError("Step identity is invalid or duplicated")
        if type(step["kind"]) is not str or step["kind"] not in KINDS or step["kind"] in kinds or step["phase"] != "apply":
            raise ValueError("Step kind or phase is invalid")
        if type(step["timeout_seconds"]) is not int or not 1 <= step["timeout_seconds"] <= 7200 or type(step["online"]) is not bool:
            raise ValueError("Step timeout or online flag is invalid")
        ids.add(step["id"])
        kinds.add(step["kind"])
    if plan["steps"][0]["kind"] != "migrate":
        raise ValueError("Upgrade plan must begin with migration")
    if plan["strategy"] == "online" and (not sources or not all(compatible.values()) or not all(step["online"] for step in plan["steps"])):
        raise ValueError("Online upgrade requires reviewed sources, compatibility and online steps")


def inventory(root: Path, previous: dict) -> list[dict]:
    known = {entry["name"]: entry for entry in previous.get("migrations", [])}
    result = []
    for path in sorted((root / "database/migrations").glob("*.php")):
        if path.is_symlink() or not path.is_file():
            raise ValueError(f"Migration must be a regular file: {path.name}")
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        old = known.get(path.stem, {})
        result.append({
            "name": path.stem,
            "sha256": digest,
            "online": old.get("online") is True and old.get("sha256") == digest,
        })
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--write", action="store_true", help="Explicitly refresh the inventory")
    mode.add_argument("--check", action="store_true", help="Fail if the inventory is stale; never write")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    path = root / "deployment/upgrade-plan.json"
    if args.check and not path.is_file():
        raise ValueError("Upgrade plan is missing")
    if path.exists() and path.stat().st_size > 1048576:
        raise ValueError("Upgrade plan exceeds the size limit")
    plan = json.loads(path.read_text(), object_pairs_hook=unique_object) if path.exists() else {
        "schema_version": 1,
        "strategy": "maintenance",
        "allowed_sources": [],
        "migrations": [],
        "compatibility": {key: False for key in ("schema", "queue", "cache", "storage")},
        "steps": [
            {"id": kind, "kind": kind, "phase": "apply", "timeout_seconds": timeout, "online": False}
            for kind, timeout in [
                ("migrate", 3600), ("managed_images", 3600), ("system_knowledge", 600),
                ("retrieval_backfill", 3600), ("security_audit", 600), ("cache_warmup", 600),
            ]
        ],
    }
    validate_plan(plan)
    migrations = inventory(root, plan)
    if args.check:
        if plan["migrations"] != migrations:
            print("Upgrade migration inventory is stale. Review changes and run with --write.")
            return 1
        print(f"Upgrade migration inventory verified: {len(migrations)} files.")
        return 0
    plan["migrations"] = migrations
    path.write_text(json.dumps(plan, indent=2, ensure_ascii=False) + "\n")
    print(f"Wrote {len(migrations)} migration hashes. Online approval requires explicit review.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exception:
        print(f"Upgrade plan validation failed: {exception}", file=sys.stderr)
        raise SystemExit(1)
