# geo.youngtuo.win monitor cron

`/api/cron/monitor` is the protected endpoint for Day 7 / Day 14 / Day 30 GEO monitoring. It runs a small Doubao sampling batch and generates a customer report from the current workspace state.

## Endpoint

```text
POST https://geo.youngtuo.win/api/cron/monitor
Authorization: Bearer <CRON_SECRET>
Content-Type: application/json
```

The endpoint also accepts `x-cron-secret: <CRON_SECRET>`. Keep `CRON_SECRET` only in server-side scheduler secrets. Do not put it in frontend code or public docs.

## Request body

```json
{
  "day": 7,
  "sampleLimit": 5
}
```

- `day`: monitoring milestone, normally `7`, `14`, or `30`.
- `sampleLimit`: optional number of questions to sample. Default is `5`.

## Expected result

```json
{
  "day": 7,
  "sampleLimit": 5,
  "samplesWritten": 5,
  "report": {
    "title": "Day 7 豆包 GEO 监测报告 2026-06-02",
    "slug": "doubao-...",
    "status": "ready"
  }
}
```

After a successful run, open:

```text
https://geo.youngtuo.win/reports/<slug>
```

The report page can be shared with a customer, printed to PDF, or downloaded as Markdown from:

```text
https://geo.youngtuo.win/api/reports/<slug>/markdown
```

## Cloudflare Cron Worker option

Use this when the domain is already behind Cloudflare and you want the schedule outside the WSL host.

1. Create a Worker named `geo-monitor-cron`.
2. Add a secret named `CRON_SECRET` with the same value as production `.env`.
3. Add three cron triggers:
   - `0 2 2 6 *` for Day 7 on 2026-06-02.
   - `0 2 9 6 *` for Day 14 on 2026-06-09.
   - `0 2 25 6 *` for Day 30 on 2026-06-25.
4. Use this Worker code:

```js
export default {
  async scheduled(event, env) {
    const dayByDate = {
      "2026-06-02": 7,
      "2026-06-09": 14,
      "2026-06-25": 30,
    };

    const today = new Date(event.scheduledTime).toISOString().slice(0, 10);
    const day = dayByDate[today];

    if (!day) {
      return;
    }

    const response = await fetch("https://geo.youngtuo.win/api/cron/monitor", {
      method: "POST",
      headers: {
        authorization: `Bearer ${env.CRON_SECRET}`,
        "content-type": "application/json",
      },
      body: JSON.stringify({ day, sampleLimit: 5 }),
    });

    if (!response.ok) {
      throw new Error(`geo monitor failed: ${response.status} ${await response.text()}`);
    }
  },
};
```

## Linux crontab option

Use this on the WSL or Linux host that already has production `.env`.

1. Save this script as `/home/qrrwi/dev/geoflow/scripts/run-monitor-cron.sh`.
2. `chmod +x /home/qrrwi/dev/geoflow/scripts/run-monitor-cron.sh`.
3. Add the crontab entries below with `crontab -e`.

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /home/qrrwi/dev/geoflow
set -a
source .env
set +a

day="${1:?day is required}"
sample_limit="${2:-5}"

curl -fsS \
  -X POST "https://geo.youngtuo.win/api/cron/monitor" \
  -H "Authorization: Bearer ${CRON_SECRET}" \
  -H "Content-Type: application/json" \
  --data "{\"day\":${day},\"sampleLimit\":${sample_limit}}"
```

```cron
0 2 2 6 * /home/qrrwi/dev/geoflow/scripts/run-monitor-cron.sh 7 5 >> /home/qrrwi/dev/geoflow/logs/monitor-cron.log 2>&1
0 2 9 6 * /home/qrrwi/dev/geoflow/scripts/run-monitor-cron.sh 14 5 >> /home/qrrwi/dev/geoflow/logs/monitor-cron.log 2>&1
0 2 25 6 * /home/qrrwi/dev/geoflow/scripts/run-monitor-cron.sh 30 5 >> /home/qrrwi/dev/geoflow/logs/monitor-cron.log 2>&1
```

## Windows Task Scheduler option

Use this on the Windows `ai` host when the production app is running inside WSL2 and Docker Desktop.

1. Copy `scripts/run-monitor-cron.sh` to `/home/qrrwi/dev/geoflow/scripts/run-monitor-cron.sh`.
2. Copy `scripts/run-monitor-cron-windows.cmd` to `C:\Users\qrrwi\run-geoflow-monitor.cmd`.
3. Create the three one-time tasks:

```bat
schtasks /Create /TN "GEOFlow Monitor Day 7" /SC ONCE /SD 2026/06/02 /ST 02:00 /TR "C:\Users\qrrwi\run-geoflow-monitor.cmd 7 5" /F
schtasks /Create /TN "GEOFlow Monitor Day 14" /SC ONCE /SD 2026/06/09 /ST 02:00 /TR "C:\Users\qrrwi\run-geoflow-monitor.cmd 14 5" /F
schtasks /Create /TN "GEOFlow Monitor Day 30" /SC ONCE /SD 2026/06/25 /ST 02:00 /TR "C:\Users\qrrwi\run-geoflow-monitor.cmd 30 5" /F
```

Current production uses this option on Windows `ai` over Tailscale IP `100.84.235.123`.

## Current production note

As of 2026-05-27, production has `CRON_SECRET` and a validated Volcengine Ark `DOUBAO_API_KEY`. Scheduled runs use real Doubao sampling unless the Ark quota, model, or upstream API is unavailable; in that case the run log should be checked before sharing the report.
