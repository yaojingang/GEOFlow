#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""线上可用性探针（v2）：不读环境代理，公网失败时回落到本机 Host 头复验，
避免 cron 环境下 hairpin / 代理导致假 DOWN。"""
import io, json, time, urllib.request, ssl

PUB = [
    "https://geo.aixgn.cn/uav-education-guide.html",
    "https://geo.aixgn.cn/uav-education.html",
    "https://geo.aixgn.cn/solutions/drone.html",
    "https://geo.aixgn.cn/entity/brand.html",
    "https://geo.aixgn.cn/sitemap.xml",
    "https://geo.aixgn.cn/robots.txt",
    "https://geo.aixgn.cn/aixgngeokey2026.txt",
    "https://geo.aixgn.cn/seogeo/admin",
    "https://geo.aixgn.cn/seogeo/b/wrj_drone",
    "https://geo.aixgn.cn/status.html",
]
OUT = "/opt/GEOFlow/storage/geo-uptime.jsonl"
LATEST = "/opt/GEOFlow/storage/geo-uptime-latest.json"
CTX = ssl.create_default_context(); CTX.check_hostname = False; CTX.verify_mode = ssl.CERT_NONE
OPENER = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=CTX))

def grab(u, host=None):
    req = urllib.request.Request(u, headers={"User-Agent": "geo-uptime/2.0", **({"Host": host} if host else {})})
    r = OPENER.open(req, timeout=15)
    body = r.read(4096)
    title = ""
    if b"<title>" in body:
        title = body.split(b"<title>", 1)[1].split(b"</title>", 1)[0].decode("utf-8", "replace").strip()
    return r.status, len(body), title

def probe(u):
    t = time.time()
    host = u.split("://", 1)[1].split("/", 1)[0]
    try:
        st, n, ti = grab(u)
        return {"url": u, "status": st, "bytes_head": n, "title": ti, "via": "public", "ms": int((time.time()-t)*1000)}
    except Exception as e1:
        code = getattr(e1, "code", 0)
        if isinstance(code, int) and code in (401, 403, 429):
            return {"url": u, "status": code, "bytes_head": 0, "title": "", "via": "public", "ms": int((time.time()-t)*1000), "note": "protected"}
        try:
            st, n, ti = grab("https://127.0.0.1" + u[u.index("/", 8):], host=host)
            return {"url": u, "status": st, "bytes_head": n, "title": ti, "via": "loopback", "ms": int((time.time()-t)*1000)}
        except Exception as e2:
            code2 = getattr(e2, "code", 0)
            if isinstance(code2, int) and code2 in (401, 403, 429):
                return {"url": u, "status": code2, "bytes_head": 0, "title": "", "via": "loopback", "ms": int((time.time()-t)*1000), "note": "protected"}
            return {"url": u, "status": 0, "err": str(e1)[:60] + " / " + str(e2)[:60], "ms": int((time.time()-t)*1000)}

rows = [probe(u) for u in PUB]
ok = sum(1 for r in rows if r["status"] in (200, 204, 401, 403))
rec = {"ts": time.strftime("%Y-%m-%dT%H:%M:%S"), "ok": ok, "n": len(rows), "rows": rows}
with io.open(OUT, "a", encoding="utf-8") as f:
    f.write(json.dumps(rec, ensure_ascii=False) + "\n")
io.open(LATEST, "w", encoding="utf-8").write(json.dumps(rec, ensure_ascii=False, indent=1))
print("UPTIME %d/%d %s" % (ok, len(rows), rec["ts"]))
for r in rows:
    if r["status"] not in (200, 204, 401, 403):
        print("  DOWN", r["url"], r.get("err"))
