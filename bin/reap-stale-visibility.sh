#!/bin/bash
# 清理因进程中断而长期卡在 running 的采集 run（>30 分钟视为遗弃）
set -euo pipefail
cd /opt/GEOFlow
export PGPASSWORD=$(grep DB_PASSWORD /opt/GEOFlow/.env | cut -d= -f2)
n=$(psql -tA -h 127.0.0.1 -U geo_user -d geo_flow -c "update ai_visibility_runs set status='failed', error_message='stale_run_timeout', updated_at=now() where status='running' and created_at < now() - interval '30 minutes' returning id;" | wc -l)
[ "$n" -gt 0 ] && echo "[$(date "+%F %T")] reaped $n stale runs"
exit 0
