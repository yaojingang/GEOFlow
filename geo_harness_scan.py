# -*- coding: utf-8 -*-
"""Q·AIXGN 知识引擎（Harness 侧）品牌可见度扫描
对 GEOFlow 的 30 个主题关键词逐条向 https://q.aixgn.cn/v1 提问（走本地回环），
记录：是否给出答案、引用了哪些条目、是否提到品牌别名、是否引用了我们自己的 GEO 页面。
输出 storage/geo-harness-visibility.json / .md，并追加 geo-harness-history.jsonl。
"""
import concurrent.futures as cf
import io, json, os, re, sys, time, urllib.request

ENGINE = os.environ.get("Q_V1_URL", "http://127.0.0.1:3021/v1/chat/completions")
OUT_DIR = "/opt/GEOFlow/storage"
WORKERS = int(os.environ.get("SCAN_WORKERS", "1"))
RETRIES = int(os.environ.get("SCAN_RETRIES", "2"))
ALIASES = ["新概念", "AIXGN", "aixgn", "荣晟智联", "WRJ", "wrj", "橘园洲", "SEOGEO", "GEOFlow"]
OUR_HOSTS = ("geo.aixgn.cn", "wrj.aixgn.cn", "j.aixgn.cn")


def load_keywords(path):
    rows = []
    for line in io.open(path, encoding="utf-8"):
        line = line.strip()
        if not line:
            continue
        parts = line.split("\t")
        if len(parts) >= 3:
            rows.append({"topic": parts[0], "hash": parts[1], "kw": parts[2]})
    return rows


def ask(kw):
    body = json.dumps({"model": "q-aixgn", "messages": [{"role": "user", "content": kw}]}).encode()
    req = urllib.request.Request(ENGINE, data=body, headers={"Content-Type": "application/json"})
    t0 = time.time()
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            d = json.loads(r.read().decode())
    except Exception as e:
        return {"kw": kw, "state": "error", "error": "%s: %s" % (type(e).__name__, str(e)[:80]),
                "latency_ms": int((time.time() - t0) * 1000)}
    ch = (d.get("choices") or [{}])[0].get("message") or {}
    ans = ch.get("content") or ""
    srcs = ch.get("sources") or []
    titled = [s.get("title") for s in srcs]
    hit_alias = [a for a in ALIASES if a in ans or a in " ".join([t or "" for t in titled])]
    own = [s.get("title") for s in srcs if (s.get("id") or "").startswith("geo-")]
    return {"kw": kw, "state": "ok" if ans else "no_hit", "answer_chars": len(ans),
            "answer_head": ans[:200], "sources": len(srcs), "source_titles": titled[:5],
            "brand_mention": bool(hit_alias), "brand_tokens": hit_alias,
            "own_page_cited": bool(own), "own_pages": own[:3],
            "latency_ms": int((time.time() - t0) * 1000)}


def ask_retry(kw):
    """空命中/超时多为引擎抖动，重试 RETRIES 次，仍失败才记 error。"""
    got = ask(kw)
    tries = 0
    while tries < RETRIES and got.get("state") != "ok":
        time.sleep(0.8 * (tries + 1))
        tries += 1
        got = ask(kw)
    got["tries"] = tries + 1
    return got


def main():
    kf = sys.argv[1] if len(sys.argv) > 1 else "/tmp/geo_kw.tsv"
    rows = load_keywords(kf)
    if not rows:
        raise SystemExit("no keywords")
    t0 = time.time()
    results = [None] * len(rows)
    with cf.ThreadPoolExecutor(max_workers=WORKERS) as ex:
        futs = {ex.submit(ask_retry, r["kw"]): i for i, r in enumerate(rows)}
        done = 0
        for fu in cf.as_completed(futs):
            i = futs[fu]
            got = fu.result()
            got.update({"topic": rows[i]["topic"], "keyword_hash": rows[i]["hash"]})
            results[i] = got
            done += 1
            brand = 1 if got.get("brand_mention") else 0
            own = 1 if got.get("own_page_cited") else 0
            print("[%d/%d] %-40s %-7s src=%s brand=%s own=%s %sms" % (
                done, len(rows), rows[i]["kw"][:38], got["state"], got.get("sources", 0),
                brand, own, got.get("latency_ms", 0)), flush=True)
    print("ELAPSED %.1fs workers=%d" % (time.time() - t0, WORKERS), flush=True)
    agg = {}
    for x in results:
        t = x["topic"]
        a = agg.setdefault(t, {"n": 0, "ok": 0, "brand": 0, "own": 0})
        a["n"] += 1
        a["ok"] += 1 if x["state"] == "ok" else 0
        a["brand"] += 1 if x.get("brand_mention") else 0
        a["own"] += 1 if x.get("own_page_cited") else 0
    total = {"n": len(results),
             "answered": sum(1 for x in results if x["state"] == "ok"),
             "errors": sum(1 for x in results if x["state"] == "error"),
             "brand": sum(1 for x in results if x.get("brand_mention")),
             "own_cited": sum(1 for x in results if x.get("own_page_cited")),
             "workers": WORKERS}
    ts = time.strftime("%Y-%m-%d %H:%M:%S")
    out = {"ts": ts, "engine": "q.aixgn.cn/v1 (Q·AIXGN 知识引擎)", "total": total, "by_topic": agg, "results": results}
    os.makedirs(OUT_DIR, exist_ok=True)
    # 全量轮次里异常率过高 = 引擎侧故障，本轮结果不可信：另存 failed 文件，不污染看板
    bad_ratio = total["n"] and total["errors"] / total["n"] > 0.2
    partial = total["n"] < 100 and os.environ.get("ALLOW_PARTIAL") != "1"
    if bad_ratio:
        fn = "geo-harness-visibility-failed.json"
    elif partial:
        fn = "geo-harness-visibility-partial.json"
    else:
        fn = "geo-harness-visibility.json"
    io.open(os.path.join(OUT_DIR, fn), "w", encoding="utf-8").write(
        json.dumps(out, ensure_ascii=False, indent=2))
    if fn == "geo-harness-visibility.json":
        with io.open(os.path.join(OUT_DIR, "geo-harness-history.jsonl"), "a", encoding="utf-8") as f:
            f.write(json.dumps({"ts": ts, "total": total, "by_topic": agg}, ensure_ascii=False) + "\n")
    md = ["# Q·AIXGN 知识引擎品牌可见度（%s）" % ts, "",
          "- 扫描问句：%d 条；有答案：%d；提到品牌：%d；引用自有页面：%d" %
          (total["n"], total["answered"], total["brand"], total["own_cited"]), "",
          "| 主题 | 问句 | 有答案 | 提到品牌 | 引用自有页面 |", "|---|---|---|---|---|"]
    for t, a in agg.items():
        md.append("| %s | %d | %d | %d | %d |" % (t, a["n"], a["ok"], a["brand"], a["own"]))
    md.append("")
    md.append("## 逐条")
    md.append("| 关键词 | 状态 | 引用条数 | 品牌 | 自有页 | 延迟 | 首条引用 |")
    md.append("|---|---|---|---|---|---|---|")
    for x in results:
        md.append("| %s | %s | %s | %s | %s | %s | %s |" % (
            x["kw"], x["state"], x.get("sources", 0),
            "Y" if x.get("brand_mention") else "-", "Y" if x.get("own_page_cited") else "-",
            "%sms" % x.get("latency_ms", 0), (x.get("source_titles") or [""])[0]))
    io.open(os.path.join(OUT_DIR, "geo-harness-visibility.md"), "w", encoding="utf-8").write("\n".join(md))
    print("TOTAL", json.dumps(total, ensure_ascii=False))


if __name__ == "__main__":
    main()
