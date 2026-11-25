#!/bin/bash
# 把 30 个 GEO 主题关键词逐个采样（doubao 搜索提供方），写日志
export PGPASSWORD=geo_password
cd /opt/GEOFlow || exit 1
n=0
psql -h 127.0.0.1 -U geo_user -d geo_flow -tA -c "select keyword from ai_visibility_topic_keywords order by id" | while read -r kw; do
  [ -z "$kw" ] && continue
  n=$((n+1))
  echo "[$(date +%H:%M:%S)] collect $kw"
  php8.5 artisan geoflow:ai-visibility:collect "$kw" 2>&1 | tail -2
  sleep 2
done
echo "[done]"
