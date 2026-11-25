#!/bin/bash
# Optimization queue workers hosted on 39 (local redis/pg, no tunnel)
cd /opt/GEOFlow || exit 1
PHP=/usr/bin/php8.5
LOG=/var/log/geoflow-workers
mkdir -p $LOG
start() {
  name=$1; queues=$2; timeout=$3
  $PHP artisan queue:work redis --queue=$queues --sleep=3 --tries=3 --max-time=3600 --timeout=$timeout --memory=256 >> $LOG/$name.log 2>&1 &
}
start opt1 ai-content-optimization 900
start optbulk ai-content-optimization-bulk 900
wait
