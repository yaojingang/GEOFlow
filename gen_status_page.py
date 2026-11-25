#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""生成对外状态页 /status.html：数据全部来自线上实时文件，由 cron 定时重建。
不是手工写死的页面——时间戳、PASS/FAIL、延迟都随探测变化。"""
import io, json, os, time

ROOT = "/opt/site/geo/public"
OUT = os.path.join(ROOT, "status.html")
G = "/opt/GEOFlow/storage"

def j(name, dflt=None):
    p = os.path.join(G, name)
    try:
        return json.loads(io.open(p, encoding="utf-8").read())
    except Exception:
        return dflt

dsh = j("geo-dsh-selftest.json", {})
up = j("geo-uptime-latest.json", {})
vis = j("geo-harness-visibility.json", {})
lite = j("geo-harness-visibility-lite.json", {})
multi = j("geo-multi-ai-mention.json", {})

vis_t = (vis.get("total") or {})
lite_t = (lite.get("total") or {})
dsh_rows = (dsh.get("rows") or [])
up_rows = (up.get("rows") or [])

def esc(s):
    return (s or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")

def wvi_live():
    """WVI 由 seo-console 计算，这里直接读它的渲染结果，避免两套口径漂移。"""
    try:
        import re, urllib.request
        html = urllib.request.urlopen("http://10.99.0.4:8910/seogeo/admin", timeout=15).read().decode("utf-8", "replace")
        m = re.search(r'加权可见度指数 WVI</span><b>([0-9.]+)', html)
        return m.group(1) if m else "—"
    except Exception:
        return "—"

dsh_cells = "".join(
    "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
        esc(r.get("q")), r.get("status", "—"), r.get("chars", "—"), r.get("sources", "—"), r.get("ms", "—"))
    for r in dsh_rows) or "<tr><td colspan=5>暂无记录</td></tr>"

up_cells = "".join(
    "<tr><td>%s</td><td class=\"%s\">%s</td><td>%s</td><td>%s</td></tr>" % (
        esc(r.get("url")), "ok" if r.get("status") in (200, 204, 401, 403) else "bad", r.get("status"), r.get("bytes_head", "—"), esc(r.get("title", "")[:46]))
    for r in up_rows) or "<tr><td colspan=4>暂无记录</td></tr>"

html = """<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>线上运行状态 · 新概念 GEO</title>
<style>
:root{--bg:#0d1117;--panel:#161b22;--line:#26303d;--tx:#e6edf3;--dim:#8b97a6;--ok:#3fb950;--bad:#f85149;--accent:#58a6ff}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font:15px/1.65 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
.wrap{max-width:1080px;margin:0 auto;padding:34px 22px 60px}
h1{font-size:24px;margin:0 0 6px;letter-spacing:.2px}
.sub{color:var(--dim);font-size:13px;margin:0 0 22px}
.cards{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:26px}
.card{flex:1 1 190px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.lbl{display:block;color:var(--dim);font-size:12px;margin-bottom:6px}
b{font-size:24px;font-variant-numeric:tabular-nums}
h2{font-size:16px;margin:26px 0 10px;padding-left:10px;border-left:3px solid var(--accent)}
table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden;font-size:13px}
th,td{padding:9px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
th{color:var(--dim);font-weight:500;font-size:12px}
td.ok{color:var(--ok)} td.bad{color:var(--bad)}
tr:last-child td{border-bottom:0}
.foot{color:var(--dim);font-size:12px;margin-top:26px;line-height:1.9}
code{background:#0b1117;border:1px solid var(--line);border-radius:5px;padding:1px 6px;color:#c9d1d9}
</style></head><body><div class="wrap">
<h1>线上运行状态</h1>
<p class="sub">本页由服务器 cron 定时重建，数据取线上文件，本次生成：__TS__</p>

<div class="cards">
<div class="card"><span class="lbl">DSH 问答链路（q.aixgn.cn）</span><b>__DSH__</b></div>
<div class="card"><span class="lbl">对外地址可用</span><b>__UPOK__/__UPN__</b></div>
<div class="card"><span class="lbl">引擎侧可见度 WVI</span><b>__WVI__</b></div>
<div class="card"><span class="lbl">日检可答（0 token）</span><b>__LITE__</b></div>
<div class="card"><span class="lbl">多模型 AI 提及率</span><b>__MULTI__</b></div>
</div>

<h2>DSH 自检（每小时）</h2>
<table><thead><tr><th>提问</th><th>HTTP</th><th>答案字数</th><th>引用源</th><th>延迟 ms</th></tr></thead><tbody>__DSHROWS__</tbody></table>

<h2>对外地址探测（每 30 分钟）</h2>
<table><thead><tr><th>URL</th><th>状态</th><th>取到字节</th><th>页面标题</th></tr></thead><tbody>__UPROWS__</tbody></table>

<p class="foot">
数据文件：<code>/opt/GEOFlow/storage/geo-dsh-selftest.json</code> · <code>geo-uptime-latest.json</code> · <code>geo-harness-visibility.json</code> · <code>geo-harness-visibility-lite.json</code><br>
口径：WVI 取自每周全量扫描（大模型问答），日检取检索层 <code>/q/search</code> 不调用大模型。<br>
本页 <code>noindex</code>，仅供核对，不参与 SEO 收录。
</p>
</div></body></html>"""

repl = {
    "__TS__": time.strftime("%Y-%m-%d %H:%M:%S"),
    "__DSH__": ("PASS %d/%d" % (sum(1 for r in dsh_rows if r.get("status") == 200), len(dsh_rows))) if dsh_rows else "—",
    "__UPOK__": str(up.get("ok", "—")), "__UPN__": str(up.get("n", "—")),
    "__WVI__": wvi_live(),
    "__LITE__": ("%s/%s" % (lite_t.get("answered"), lite_t.get("n"))) if lite_t else "—",
    "__MULTI__": ("%s/%s" % (multi.get("total", {}).get("hits"), multi.get("total", {}).get("asks"))) if (multi.get("total") or {}).get("asks") else "—",
    "__DSHROWS__": dsh_cells, "__UPROWS__": up_cells,
}
for k, v in repl.items():
    html = html.replace(k, str(v))
io.open(OUT, "w", encoding="utf-8").write(html)
print("STATUS_PAGE", OUT, len(html), "bytes", time.strftime("%H:%M:%S"))
