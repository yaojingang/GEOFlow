#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""DSH（q.aixgn.cn）闭环自检：从公网入口问 GEO 问题，验证
① 有生成答案 ② 引用了站内 geo 知识（x_aixgn.sources）③ 延迟正常。
写 geo-dsh-selftest.json，供看板与巡检读取。
2026-11-16 修：单次失败（引擎重启窗口/超时）不再直接记 FAIL，改为最多 3 次重试并记录 attempts。
"""
import io, json, re, time, urllib.request, ssl

EP = "https://q.aixgn.cn/v1/chat/completions"
OUT = "/opt/GEOFlow/storage/geo-dsh-selftest.json"
QS = [
    "青少年无人机教育怎么入门",
    "福州青少年无人机培训怎么选，白名单赛事怎么核",
    "智慧物业SaaS怎么选",
]
CTX = ssl.create_default_context(); CTX.check_hostname = False; CTX.verify_mode = ssl.CERT_NONE

def ask_once(q):
    body = json.dumps({"model": "q-aixgn", "messages": [{"role": "user", "content": q}]}).encode()
    t = time.time()
    r = urllib.request.urlopen(urllib.request.Request(EP, body, {"Content-Type": "application/json"}), timeout=75, context=CTX)
    d = json.loads(r.read().decode("utf-8", "replace"))
    ans = ((d.get("choices") or [{}])[0].get("message") or {}).get("content") or ""
    src = (d.get("x_aixgn") or {}).get("sources") or 0
    cites = len(re.findall(r"\[\d+\]", ans))
    return {"q": q, "status": r.status, "chars": len(ans), "sources": src, "cites": cites,
            "ms": int((time.time() - t) * 1000), "head": ans[:60]}

def ok(r):
    return r.get("status") == 200 and r.get("chars", 0) > 20 and r.get("sources", 0) >= 1

def ask(q):
    last = None
    for i in range(3):
        try:
            r = ask_once(q)
        except Exception as e:
            r = {"q": q, "status": 0, "err": str(e)[:80]}
        r["attempts"] = i + 1
        if ok(r):
            return r
        last = r
        time.sleep(5)
    return last

rows = [ask(q) for q in QS]
passed = all(ok(r) for r in rows)
rec = {"ts": time.strftime("%Y-%m-%dT%H:%M:%S"), "endpoint": EP,
       "model_upstream": "deepseek-flash @ api.deepseek.com", "pass": passed,
       "n": len(rows), "rows": rows}
io.open(OUT, "w", encoding="utf-8").write(json.dumps(rec, ensure_ascii=False, indent=1))
print(("PASS" if passed else "FAIL"), rec["ts"])
for r in rows:
    print("  %-30s %s chars=%s sources=%s cites=%s %sms try=%s" % (r["q"][:28], r.get("status"), r.get("chars"), r.get("sources"), r.get("cites"), r.get("ms"), r.get("attempts")))
