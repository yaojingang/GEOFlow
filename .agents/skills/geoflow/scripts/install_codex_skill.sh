#!/usr/bin/env bash

set -euo pipefail
umask 077

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
source_root="$(cd "$script_dir/.." && pwd -P)"
skills_root="${GEOFLOW_CODEX_SKILLS_ROOT:-$HOME/.codex/skills}"
backup_root="${GEOFLOW_SKILL_BACKUP_ROOT:-$HOME/.codex/skill-backups}"
timestamp="$(date +%Y%m%d-%H%M%S)"

mkdir -p "$skills_root" "$backup_root"
backup_dir="$(mktemp -d "$backup_root/geoflow-$timestamp.XXXXXX")"
stage_dir="$(mktemp -d "$skills_root/.geoflow.stage.XXXXXX")"

cleanup_stage() {
  python3 - "$stage_dir" "$skills_root" <<'PY'
import pathlib
import shutil
import sys

stage = pathlib.Path(sys.argv[1])
skills_root = pathlib.Path(sys.argv[2]).resolve()
if stage.parent.resolve() != skills_root or not stage.name.startswith(".geoflow.stage."):
    raise SystemExit("Refusing to clean an unexpected staging path")
if stage.exists():
    shutil.rmtree(stage)
PY
}
trap cleanup_stage EXIT

python3 - "$source_root" "$stage_dir" <<'PY'
import json
import pathlib
import shutil
import sys

source = pathlib.Path(sys.argv[1]).resolve()
stage = pathlib.Path(sys.argv[2]).resolve()
contract_path = source / "evals/expected_artifacts.json"
contract = json.loads(contract_path.read_text(encoding="utf-8"))
expected = contract.get("required_package_files", [])
if not isinstance(expected, list) or not expected:
    raise SystemExit("Package artifact contract is empty")

for raw_path in expected:
    relative = pathlib.PurePosixPath(str(raw_path))
    if relative.is_absolute() or ".." in relative.parts:
        raise SystemExit(f"Unsafe artifact path: {raw_path}")
    source_file = source.joinpath(*relative.parts)
    resolved_source_file = source_file.resolve()
    if source_file.is_symlink() or not source_file.is_file() or not resolved_source_file.is_relative_to(source):
        raise SystemExit(f"Missing or unsafe package artifact: {raw_path}")
    cursor = source_file.parent
    while cursor != source:
        if cursor.is_symlink():
            raise SystemExit(f"Symlinked package directory is not allowed: {raw_path}")
        cursor = cursor.parent
    destination = stage.joinpath(*relative.parts)
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source_file, destination)

actual = sorted(
    path.relative_to(stage).as_posix()
    for path in stage.rglob("*")
    if path.is_file()
)
if actual != sorted(str(path) for path in expected):
    raise SystemExit("Staged package does not match expected_artifacts.json")
PY

PYTHONDONTWRITEBYTECODE=1 python3 -B "$stage_dir/scripts/discover_geoflow_workspace.py" --help >/dev/null
bash -n "$stage_dir/scripts/geoflow_preflight.sh"
bash -n "$stage_dir/scripts/install_codex_skill.sh"

moved_names=()
for skill_name in geoflow yao-geoflow-cli yao-geoflow-design yao-geoflow-template; do
  installed_path="$skills_root/$skill_name"
  if [[ -e "$installed_path" || -L "$installed_path" ]]; then
    if ! mv "$installed_path" "$backup_dir/$skill_name"; then
      for moved_name in "${moved_names[@]}"; do
        mv "$backup_dir/$moved_name" "$skills_root/$moved_name"
      done
      exit 1
    fi
    moved_names+=("$skill_name")
  fi
done

if ! mv "$stage_dir" "$skills_root/geoflow"; then
  for skill_name in "${moved_names[@]}"; do
    mv "$backup_dir/$skill_name" "$skills_root/$skill_name"
  done
  exit 1
fi
trap - EXIT

echo "Installed GEOFlow skill: $skills_root/geoflow"
echo "Previous skill backups: $backup_dir"
echo "Restart Codex to reload the skill catalog."
