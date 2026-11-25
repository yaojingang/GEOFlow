# -*- coding: utf-8 -*-
"""GEOFlow 轻量可见度扫描：只调 /q/search（V1 检索），不调用大模型，0 token。

口径：
- answered：检索层能否为这句话找到足够匹配的条目（top1 >= 0.55）
- own_cited：命中里含自有知识条目（id 以 geo- 开头）
- brand：沿用上一次大模型全量扫描的同词结果（无则记 0）
"""
import io, json, os, sys, time, urllib.request
from collections import defaultdict

ENGINE = "http://127.0.0.1:3021/q/search"
STATE = "/opt/GEOFlow/storage/geo-harness-visibility.json"
OUT = "/opt/GEOFlow/storage/geo-harness-visibility-lite.json"
TSV = sys.argv[1] if len(sys.argv) > 1 else "storage/geo_kw_all.tsv"
LIMIT = 6
THRESH = 0.55


def search(kw):
    body = json.dumps({"q": kw, "mode": "v1", "limit": LIMIT}).encode()
    req = urllib.request.Request(ENGINE, data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.load(r)


def search_retry(kw, tries=3):
    last = None
    for i in range(tries):
        try:
            d = search(kw)
            if d.get("hits"):
                return d
            last = d
        except Exception as e:
            last = {"ok": False, "err": str(e), "hits": []}
        time.sleep(0.4 * (i + 1))
    return last or {"hits": []}


def main():
    rows = []
    for line in io.open(TSV, encoding="utf-8"):
        parts = line.rstrip("\n").split("\t")
        if len(parts) < 3:
            continue
        rows.append((parts[0], parts[1], parts[2]))
    brand_prev = {}
    try:
        prev = json.load(io.open(STATE, encoding="utf-8"))
        for r in prev.get("results") or []:
            brand_prev[(r.get("topic"), r.get("kw"))] = bool(r.get("brand_mention"))
    except Exception:
        pass

    results, agg = [], defaultdict(lambda: {"n": 0, "ok": 0, "brand": 0, "own": 0})
    t0 = time.time()
    for topic, kh, kw in rows:
        err = None
        try:
            d = search_retry(kw)
            hits = d.get("hits") or []
            top = max([h.get("score") or 0 for h in hits] or [0])
            own_geo = any(str(h.get("id") or "").startswith("geo-") for h in hits)
            # 有自有条目被检索到 = 引擎能据此给出带引用的答案；没有自有条目时要求分数够高
            ok = own_geo or top >= THRESH
            own = own_geo
            head = (hits[0].get("text") if hits else "") or ""
            if not hits:
                err = d.get("err") or "empty_hits"
        except Exception as e:
            ok, own, top, head, err = False, False, 0, "", str(e)
        bm = brand_prev.get((topic, kw), False)
        results.append({"topic": topic, "kw": kw, "state": "ok" if ok else "no_hit",
                        "top_score": round(top, 4), "answer_chars": len(head),
                        "answer_head": head[:80], "brand_mention": bm,
                        "own_page_cited": own, "sources": len(hits), "err": err})
        a = agg[topic]
        a["n"] += 1; a["ok"] += 1 if ok else 0
        a["brand"] += 1 if bm else 0; a["own"] += 1 if own else 0
        print("%-40s %s top=%.3f own=%d" % (kw, "ok" if ok else "no_hit", top, 1 if own else 0))

    total = {"n": len(results),
             "answered": sum(1 for r in results if r["state"] == "ok"),
             "brand": sum(1 for r in results if r["brand_mention"]),
             "own_cited": sum(1 for r in results if r["own_page_cited"])}
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    out = {"ts": ts, "engine": "q.aixgn.cn /q/search (V1 检索层，无模型调用，0 token)",
           "mode": "lite", "total": total, "by_topic": dict(agg), "results": results,
           "note": "brand_mention 沿用最近一次大模型全量扫描（geo-harness-visibility.json）"}
    io.open(OUT, "w", encoding="utf-8").write(json.dumps(out, ensure_ascii=False, indent=1))
    print("LITE_TOTAL", json.dumps(total, ensure_ascii=False), "in %.1fs" % (time.time() - t0))
    print("SAVED", OUT)


if __name__ == "__main__":
    main()
