#!/bin/bash
set -euo pipefail
export PGPASSWORD=geo_password
LOG=/opt/GEOFlow/storage/logs/match-scan.log
mkdir -p "$(dirname "$LOG")"
exec >>"$LOG" 2>&1
echo "[$(date '+%F %T')] match scan start"
psql -h 127.0.0.1 -p 5432 -U geo_user -d geo_flow -v ON_ERROR_STOP=1 -f /opt/GEOFlow/database/sql/match_scan.sql
echo "[$(date '+%F %T')] match scan done"
