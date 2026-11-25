#!/bin/bash
# 2026-11-16 一轮收敛：39 内存仅 3.6G，10 worker 会顶爆 swap。
# 2026-11-19 二轮收敛：2 核/3.6G 长期 swap 62%、load 12+；实测 9 条队列积压全为 0，
#   5 worker 合并为 3 —— 同族队列共用一个 worker，覆盖不减少，常驻内存降约 50M。
cd /opt/GEOFlow || exit 1
PHP=/usr/bin/php
LOG=/var/log/geoflow-workers
mkdir -p $LOG
start() {
  name=$1; queues=$2; timeout=$3
  $PHP artisan queue:work redis --queue=$queues --sleep=3 --tries=3 --max-time=3600 --timeout=$timeout --memory=256 >> $LOG/$name.log 2>&1 &
}
start quality 'ai-quality,ai-quality-backfill' 250
start opt 'ai-content-optimization,ai-content-optimization-bulk' 900
start main 'knowledge,system-updates,geoflow,distribution,theme-replication,default' 930
wait
