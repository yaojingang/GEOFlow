#!/usr/bin/env bash
# AI 可见度采集自动轮询：教育优先，其次无人机；每次 3 个关键词
set -u
cd /opt/GEOFlow || exit 1
export PGPASSWORD=$(grep DB_PASSWORD /opt/GEOFlow/.env | cut -d= -f2)
CURSOR=/var/lib/geoflow/vis_cursor
mkdir -p /var/lib/geoflow
cur=$(cat $CURSOR 2>/dev/null || echo 0)
total=$(psql -tA -h 127.0.0.1 -U geo_user -d geo_flow -c "select count(*) from keywords;")
mapfile -t kws < <(psql -tA -h 127.0.0.1 -U geo_user -d geo_flow -c "select keyword from keywords order by (case when keyword ~ '教育|AI答疑|作文|K12|启蒙|科技特长' then 0 else 1 end), id limit 3 offset $((cur % total));")
for kw in "${kws[@]}"; do
  [ -z "$kw" ] && continue
  echo "[$(date '+%F %T')] collect: $kw"
  timeout 300 php artisan geoflow:ai-visibility:collect "$kw" 2>&1 | tail -2
done
echo $(( (cur + 3) % total )) > $CURSOR
