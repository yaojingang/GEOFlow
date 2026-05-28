#!/usr/bin/env bash
set -euo pipefail

REMOTE_HOST="${REMOTE_HOST:-qrrwi@ai}"
REMOTE_DIR="${REMOTE_DIR:-/home/qrrwi/dev/geoflow}"

echo "[deploy] checking SSH"
ssh -o ConnectTimeout=8 "$REMOTE_HOST" "hostname"

echo "[deploy] syncing GEO lesson stage files"
COPYFILE_DISABLE=1 tar -cf - \
  prisma/schema.prisma \
  src/lib/geo-lesson-service.ts \
  src/lib/agent-runtime/prompt-assembler.ts \
  src/lib/agent-runtime/workspace-tools.ts \
  src/lib/agent-runtime/planner.ts \
  src/lib/agent-runtime/loop.ts \
  src/lib/agent.ts \
  app/api/agent/route.ts \
  app/api/v1/lessons/route.ts \
  package.json \
  package-lock.json \
  | ssh "$REMOTE_HOST" "wsl -d Ubuntu -- tar xf - -C '$REMOTE_DIR'"

echo "[deploy] applying database changes"
cat <<'SQL' | ssh "$REMOTE_HOST" "wsl -d Ubuntu -- docker exec -i geo-youngtuo-postgres psql -U geo_youngtuo -d geo_youngtuo -v ON_ERROR_STOP=1"
CREATE TABLE IF NOT EXISTS "GeoLesson" (
  "id" TEXT PRIMARY KEY,
  "workspaceId" TEXT NOT NULL REFERENCES "Workspace"("id") ON DELETE CASCADE ON UPDATE CASCADE,
  "title" TEXT NOT NULL,
  "tactic" TEXT NOT NULL,
  "scenario" TEXT NOT NULL,
  "outcome" TEXT NOT NULL,
  "verificationStatus" TEXT NOT NULL DEFAULT 'unverified',
  "confidence" INTEGER NOT NULL DEFAULT 60,
  "evidenceUrl" TEXT,
  "reportId" TEXT,
  "workedCount" INTEGER NOT NULL DEFAULT 0,
  "partialCount" INTEGER NOT NULL DEFAULT 0,
  "didNotWorkCount" INTEGER NOT NULL DEFAULT 0,
  "notes" TEXT,
  "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "GeoLesson_workspaceId_verificationStatus_idx" ON "GeoLesson"("workspaceId", "verificationStatus");
CREATE INDEX IF NOT EXISTS "GeoLesson_workspaceId_createdAt_idx" ON "GeoLesson"("workspaceId", "createdAt");
SQL

echo "[deploy] rebuilding and restarting containers"
ssh "$REMOTE_HOST" "wsl -d Ubuntu --cd '$REMOTE_DIR' -- docker compose build web"
ssh "$REMOTE_HOST" "wsl -d Ubuntu --cd '$REMOTE_DIR' -- docker compose up -d web worker"

echo "[deploy] smoke testing app from remote host"
ssh "$REMOTE_HOST" "wsl -d Ubuntu -- curl -fsS 'http://127.0.0.1:18080/api/agent' >/dev/null"
ssh "$REMOTE_HOST" "wsl -d Ubuntu -- curl -fsS 'http://127.0.0.1:18080/api/workspace/state' >/dev/null"

echo "[deploy] done"
