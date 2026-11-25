#!/usr/bin/env python3
"""GEOFlow 调度台账登记（幂等 upsert）。

说明：task_schedules 表 FK 绑定 tasks(内容生产任务)，运维级 cron 调度语义不符；
本表 platform_schedules 作为平台级调度登记台账，只加 DB 表，不改 Laravel 模型/核心代码。
执行通道仍为 cron.d / Laravel Schedule；本表记录元数据与观测状态，双轨对账。
"""
import psycopg2, datetime, sys

DSN = dict(dbname='geo_flow', user='geo_user', password='geo_password', host='127.0.0.1', port=5432)
DDL = """
CREATE TABLE IF NOT EXISTS platform_schedules (
  id bigserial PRIMARY KEY,
  code varchar(64) UNIQUE NOT NULL,
  name varchar(255) NOT NULL,
  cron_expr varchar(64) NOT NULL,
  command text NOT NULL,
  channel varchar(32) NOT NULL,
  owner_service varchar(64) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  last_run_at timestamp,
  last_status varchar(16) DEFAULT '',
  note text DEFAULT '',
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp DEFAULT CURRENT_TIMESTAMP
);
"""
NOW = datetime.datetime.now()
ROWS = [
 ('geoflow-artisan-schedule', 'Laravel schedule:run 分钟触发', '* * * * *',
  'cd /opt/GEOFlow && /usr/bin/php artisan schedule:run', 'cron.d', 'geo_flow', 'active',
  '驱动 Laravel Schedule 与内容任务轮询；cron.d/geoflow-schedule'),
 ('geoflow-recover-url-changes', 'URL 变更恢复定时 Job', 'everyMinute',
  'Schedule::job(RecoverUrlChanges) everyMinute onOneServer', 'laravel-schedule', 'geo_flow', 'active',
  '在 routes/console.php Schedule 注册；由 schedule:run 触发'),
 ('geoflow-visibility', 'AI 可见度采集', '0 */6 * * *',
  'bash /opt/GEOFlow/bin/collect-visibility.sh', 'cron.d', 'geo_flow', 'active',
  'cron.d/geoflow-visibility'),
 ('geoflow-visibility-reaper', '可见度过期记录回收', '*/10 * * * *',
  'bash /opt/GEOFlow/bin/reap-stale-visibility.sh', 'cron.d', 'geo_flow', 'active',
  'cron.d/geoflow-visibility-reaper'),
 ('geo-topic-bridge', '话题桥接推送', '30 8 * * *',
  'python3 /opt/geo-bridge/topic_bridge.py 3', 'cron.d', 'geo_flow', 'active',
  'cron.d/geo-topic-bridge'),
 ('geoflow-db-backup', '数据库日备份（geo_flow + seo_console）', '30 2 * * *',
  'pg_dump geo_flow / seo_console 压缩归档，保留 14 天', 'cron.d', 'geo_flow', 'active',
  'cron.d/geoflow-db-backup；内容生产任务调度见 tasks.next_run_at，不在本表重复登记'),
]
con = psycopg2.connect(**DSN); cur = con.cursor()
cur.execute(DDL)
for code, name, cron, cmd, channel, svc, status, note in ROWS:
    cur.execute("""
      INSERT INTO platform_schedules(code,name,cron_expr,command,channel,owner_service,status,note,updated_at)
      VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)
      ON CONFLICT(code) DO UPDATE SET
        name=EXCLUDED.name, cron_expr=EXCLUDED.cron_expr, command=EXCLUDED.command,
        channel=EXCLUDED.channel, owner_service=EXCLUDED.owner_service, status=EXCLUDED.status,
        note=EXCLUDED.note, updated_at=EXCLUDED.updated_at
    """, (code, name, cron, cmd, channel, svc, status, note, NOW))
con.commit()
cur.execute("SELECT code,channel,cron_expr,status FROM platform_schedules ORDER BY id")
for r in cur.fetchall(): print('  ', r)
cur.execute("SELECT count(*) FROM platform_schedules"); print('total=', cur.fetchone()[0])
con.close()
