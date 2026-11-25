#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""geo_ai_scan.py — 用引擎 DeepSeek 网关（带联网搜索）真实提问 30 个 GEO 关键词，
测量品牌在 AI 答案中的可见度（提及/引用域名/竞品），输出 JSON+Markdown 报告。"""
import json, os, subprocess, sys, time, urllib.request, re

GW = "http://127.0.0.1:8903/chat"
OUT_JSON = "/opt/GEOFlow/storage/geo-ai-visibility.json"
OUT_MD = "/opt/GEOFlow/storage/geo-ai-visibility.md"
ENV = dict(os.environ, PGPASSWORD="geo_password")


def psql(sql):
    r = subprocess.run(["psql", "-h", "127.0.0.1", "-U", "geo_user", "-d", "geo_flow",
                        "-tA", "-F", "\t", "-c", sql],
                       capture_output=True, text=True, env=ENV)
    if r.returncode != 0:
        print("psql error:", r.stderr[:200])
        return []
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l.strip()]


def ask(q, timeout=150):
    body = json.dumps({"messages": [{"role": "user", "content": q}]}, ensure_ascii=False).encode()
    req = urllib.request.Request(GW, data=body, headers={"Content-Type": "application/json"})
    t0 = time.time()
    with urllib.request.urlopen(req, timeout=timeout) as r:
        d = json.loads(r.read().decode("utf-8", "replace"))
    return d, round((time.time() - t0) * 1000)


def main():
    topics = {}
    for row in psql("select id, name, brand_aliases from ai_visibility_topics order by id"):
        if len(row) < 3:
            continue
        try:
            aliases = json.loads(row[2]) if row[2].strip().startswith("[") else []
        except Exception:
            aliases = []
        topics[int(row[0])] = {"name": row[1], "aliases": aliases, "rows": []}

    kws = psql("select ai_visibility_topic_id, keyword from ai_visibility_topic_keywords order by id")
    if not kws:
        print("没有关键词")
        return

    for tid, kw in kws:
        tid = int(tid)
        if tid not in topics:
            continue
        try:
            d, ms = ask(kw)
        except Exception as e:
            print(f"[err] {kw}: {e}")
            topics[tid]["rows"].append({"keyword": kw, "ok": False, "error": str(e)[:120]})
            continue
        text = d.get("text") or ""
        cites = d.get("cites") or []
        hit = None
        for a in topics[tid]["aliases"]:
            if a and a.lower() in text.lower():
                hit = a
                break
        doms = []
        for c in cites:
            u = c.get("url") or ""
            m = re.match(r"https?://([^/]+)", u)
            if m:
                doms.append(m.group(1).lower())
        topics[tid]["rows"].append({
            "keyword": kw, "ok": True, "model": d.get("model"), "latency_ms": ms,
            "answer_len": len(text), "brand": hit,
            "cite_count": len(cites),
            "domains": sorted(set(doms))[:8],
            "answer_head": re.sub(r"\s+", " ", text)[:260],
        })
        print(f"[ok] {kw} -> 品牌={'提及:' + hit if hit else '未提及'} cites={len(cites)} {ms}ms")
        time.sleep(2)

    out = {"generated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
           "gateway": GW, "model": "deepseek-flash", "topics": []}
    md = ["# GEO AI 答案可见度实测报告", "", f"生成时间：{out['generated_at']}　模型：deepseek-flash（联网搜索）", ""]
    for tid, t in topics.items():
        ok = [r for r in t["rows"] if r.get("ok")]
        hit = [r for r in ok if r.get("brand")]
        rate = round(len(hit) / len(ok) * 100, 1) if ok else None
        out["topics"].append({"id": tid, "name": t["name"], "asked": len(ok),
                              "mentioned": len(hit), "rate": rate, "rows": t["rows"]})
        md.append(f"## {t['name']}")
        md.append("")
        md.append(f"提问 {len(ok)} 个关键词，品牌被 AI 提及 {len(hit)} 个，提及率 {rate}%")
        md.append("")
        md.append("| 关键词 | 品牌提及 | 引用数 | AI 答案摘要 |")
        md.append("|---|---|---|---|")
        for r in t["rows"]:
            if not r.get("ok"):
                md.append(f"| {r['keyword']} | 失败 | - | {r.get('error','')} |")
                continue
            md.append(f"| {r['keyword']} | {r.get('brand') or '未提及'} | {r.get('cite_count')} | {r.get('answer_head','')[:120]} |")
        md.append("")

    json.dump(out, open(OUT_JSON, "w", encoding="utf-8"), ensure_ascii=False, indent=2)
    hist = {"generated_at": out["generated_at"],
            "summary": [{"topic": t["name"], "asked": t["asked"], "mentioned": t["mentioned"], "rate": t["rate"]}
                        for t in out["topics"]]}
    with open("/opt/GEOFlow/storage/geo-ai-history.jsonl", "a", encoding="utf-8") as f:
        f.write(json.dumps(hist, ensure_ascii=False) + "\n")
    open(OUT_MD, "w", encoding="utf-8").write("\n".join(md))
    print("报告：", OUT_MD)


if __name__ == "__main__":
    main()
