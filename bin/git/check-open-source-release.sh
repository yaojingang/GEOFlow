#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

cd "$PROJECT_ROOT"

FAILURES=0
WARNINGS=0

fail() {
  printf 'FAIL %s\n' "$1"
  FAILURES=$((FAILURES + 1))
}

warn() {
  printf 'WARN %s\n' "$1"
  WARNINGS=$((WARNINGS + 1))
}

check_tracked_path() {
  label=$1
  pattern=$2
  matches=$(git ls-files "$pattern")
  if [ -n "$matches" ]; then
    fail "$label"
    printf '%s\n' "$matches" | sed 's/^/  - /'
  fi
}

check_tracked_path_except() {
  label=$1
  pattern=$2
  exclude_regex=$3
  matches=$(git ls-files "$pattern" | grep -Ev "$exclude_regex" || true)
  if [ -n "$matches" ]; then
    fail "$label"
    printf '%s\n' "$matches" | sed 's/^/  - /'
  fi
}

check_tracked_path "tracked environment files must not be published" ".env"
check_tracked_path "tracked runtime uploads must not be published" "uploads/**"
check_tracked_path "tracked runtime databases must not be published" "data/db/**"
check_tracked_path_except "tracked database backups must not be published" "data/backups/**" '/\.htaccess$'
check_tracked_path_except "tracked runtime logs must not be published" "logs/**" '/\.htaccess$'
check_tracked_path "tracked scheduler logs must not be published" "bin/logs/**"
check_tracked_path "tracked docs snapshot repo must not be published" "docs/git/repo/**"
check_tracked_path "tracked local git state must not be published" "bin/git/state/**"
check_tracked_path "tracked local docs git state must not be published" "docs/git/state/**"
check_tracked_path "tracked login runtime state must not be published" "data/login_attempts.json"
check_tracked_path "tracked macOS junk files must not be published" ".DS_Store"
check_tracked_path "tracked macOS junk files must not be published" "**/.DS_Store"
check_tracked_path "tracked temporary export files must not be published" "tmp-*"
check_tracked_path "tracked archived secret backups must not be published" "docs/archived/backup_old/**"

secret_matches=$(
  git grep -nE '(^|[^A-Za-z0-9])sk-[A-Za-z0-9_-]{20,}' -- \
    ':!README.md' \
    ':!docs/project/API_V1_REFERENCE_DRAFT.md' \
    ':!docs/project/API_CLI_PHASE1_PLAN.md' \
    ':!docs/project/GITHUB_OPEN_SOURCE_RULES.md' \
    ':!docs/project/DOCKER.md' \
    ':!docs/deployment/**' \
    ':!docs/项目最新说明-*' \
    ':!docs/本地环境配置指南.md' \
    ':!docs/后台功能审查报告.md' || true
)

if [ -n "$secret_matches" ]; then
  fail "tracked files appear to contain real API keys"
  printf '%s\n' "$secret_matches" | sed 's/^/  - /'
fi

reference_secret_matches=$(
  git grep -nE '(^|[^A-Za-z0-9])(sk-[A-Za-z0-9_-]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|AKIA[0-9A-Z]{16}|Bearer[[:space:]]+[A-Za-z0-9._~+/=-]{20,}|eyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})|-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----|https?://[^/@[:space:]]+:[^/@[:space:]]+@' -- \
    'database/seeders/data/**' || true
)

if [ -n "$reference_secret_matches" ]; then
  fail "frontend reference content appears to contain credentials"
  printf '%s\n' "$reference_secret_matches" | sed 's/^/  - /'
fi

warning_patterns=$(
  git ls-files \
    "admin/legacy/**" \
    "docs/archived/**" \
    "docs/backups/**" \
    "*.bak" \
    "*-backup.php" \
    "tmp-*"
)

if [ -n "$warning_patterns" ]; then
  warn "legacy/backups are tracked; review whether they should stay in the first open-source release"
  printf '%s\n' "$warning_patterns" | sed 's/^/  - /'
fi

if [ "$FAILURES" -gt 0 ]; then
  printf '\nOpen-source release check failed: %s blocking issue(s), %s warning(s).\n' "$FAILURES" "$WARNINGS" >&2
  exit 1
fi

printf 'Open-source release check passed with %s warning(s).\n' "$WARNINGS"
