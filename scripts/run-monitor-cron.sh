#!/usr/bin/env bash
set -euo pipefail

cd /home/qrrwi/dev/geoflow

if [[ -f .env ]]; then
  set -a
  # shellcheck disable=SC1091
  source .env
  set +a
fi

day="${1:?day is required}"
sample_limit="${2:-5}"
endpoint="${GEO_MONITOR_ENDPOINT:-https://geo.youngtuo.win/api/cron/monitor}"
log_dir="${GEO_MONITOR_LOG_DIR:-/home/qrrwi/dev/geoflow/logs}"

mkdir -p "$log_dir"

if [[ -z "${CRON_SECRET:-}" ]]; then
  echo "$(date -Is) CRON_SECRET is missing" | tee -a "$log_dir/monitor-cron.log" >&2
  exit 1
fi

if [[ "${GEO_MONITOR_DRY_RUN:-}" == "1" ]]; then
  echo "$(date -Is) dry-run day=$day sampleLimit=$sample_limit endpoint=$endpoint" | tee -a "$log_dir/monitor-cron.log"
  exit 0
fi

echo "$(date -Is) running day=$day sampleLimit=$sample_limit endpoint=$endpoint" >> "$log_dir/monitor-cron.log"

curl -fsS \
  -X POST "$endpoint" \
  -H "Authorization: Bearer ${CRON_SECRET}" \
  -H "Content-Type: application/json" \
  --data "{\"day\":${day},\"sampleLimit\":${sample_limit}}" \
  >> "$log_dir/monitor-cron.log"

printf "\n" >> "$log_dir/monitor-cron.log"
