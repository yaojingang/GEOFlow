#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$SCRIPT_DIR/../.." && pwd)

if [ "${1:-}" = "" ]; then
  echo "Usage: sh bin/git/prepare-open-source-release.sh /absolute/path/to/public-repo" >&2
  exit 1
fi

case "$1" in
  /*) ;;
  *)
    echo "Target repository path must be absolute." >&2
    exit 1
    ;;
esac

if [ ! -d "$1" ] || [ ! -e "$1/.git" ]; then
  echo "Target must be an existing Git worktree." >&2
  exit 1
fi

TARGET_ROOT=$(CDPATH= cd -- "$1" && pwd)

case "$TARGET_ROOT" in
  "$PROJECT_ROOT"|"$PROJECT_ROOT"/*)
    echo "Refusing to sync into the private source repository." >&2
    exit 1
    ;;
esac

TARGET_ORIGIN=$(git -C "$TARGET_ROOT" remote get-url origin 2>/dev/null || true)
case "$TARGET_ORIGIN" in
  https://github.com/yaojingang/GEOFlow|https://github.com/yaojingang/GEOFlow.git|git@github.com:yaojingang/GEOFlow.git|ssh://git@github.com/yaojingang/GEOFlow.git) ;;
  *)
    echo "Refusing to sync into a repository that is not the official GEOFlow public remote." >&2
    exit 1
    ;;
esac

if ! git -C "$PROJECT_ROOT" diff --quiet -- \
  || ! git -C "$PROJECT_ROOT" diff --cached --quiet -- \
  || [ -n "$(git -C "$PROJECT_ROOT" ls-files --others --exclude-standard)" ]; then
  echo "Refusing to sync while the release source has local changes." >&2
  exit 1
fi

if ! git -C "$TARGET_ROOT" diff --quiet -- \
  || ! git -C "$TARGET_ROOT" diff --cached --quiet -- \
  || [ -n "$(git -C "$TARGET_ROOT" ls-files --others --exclude-standard)" ]; then
  echo "Refusing to sync into a target with local changes." >&2
  exit 1
fi

rsync -a --delete \
  --exclude='.git' \
  --include='.env.example' \
  --include='.env.prod.example' \
  --exclude='.env*' \
  --exclude='.phpunit.result.cache' \
  --exclude='vendor/***' \
  --exclude='node_modules/***' \
  --exclude='public/build/***' \
  --include='storage/' \
  --include='storage/**/' \
  --include='storage/**/.gitignore' \
  --exclude='storage/***' \
  --include='bootstrap/cache/.gitignore' \
  --exclude='bootstrap/cache/***' \
  --exclude='.DS_Store' \
  --exclude='uploads/***' \
  --exclude='data/db/***' \
  --exclude='data/backups/***' \
  --exclude='logs/***' \
  --exclude='bin/logs/***' \
  --exclude='docs/git/repo/***' \
  --exclude='bin/git/state/***' \
  --exclude='docs/git/state/***' \
  --exclude='data/login_attempts.json' \
  --exclude='docs/archived/***' \
  --exclude='docs/backups/***' \
  --exclude='admin/legacy/***' \
  --exclude='*.bak' \
  --exclude='*-backup.php' \
  --exclude='tmp-*' \
  "$PROJECT_ROOT/" "$TARGET_ROOT/"

echo "Open-source release workspace refreshed:"
echo "  source: $PROJECT_ROOT"
echo "  target: $TARGET_ROOT"
echo
echo "Next steps:"
echo "  1. cd \"$TARGET_ROOT\""
echo "  2. git status"
echo "  3. git add -A && git commit -m 'sync(open-source): YYYY-MM-DD release sync'"
echo "  4. git push -u origin HEAD"
echo "  5. Open a pull request into main"
