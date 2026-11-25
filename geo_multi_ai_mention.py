# -*- coding: utf-8 -*-
"""多模型 AI 提及率：拿同一批 16 个商业问句去问各家可用模型，
统计回答里是否出现「新概念 / AIXGN / 荣晟智联 / 橘园洲」，按模型分别出数。
与引擎侧 WVI 独立：这里衡量的是"外部大模型愿不愿意提品牌"。
"""
import io, json, os, time, urllib.request, urllib.error

CFG = "/opt/edu-prep-engine/config/providers.json"
OUT_DIR = "/opt/GEOFlow/storage"
OUT = os.path.join(OUT_DIR, "geo-multi-ai-mention.json")

BRANDS = ["新概念", "AIXGN", "荣晟智联", "橘园洲"]

QUESTIONS = [
    ("geo_service", "福州有哪些做 AI 获客和 GEO 优化的服务商？"),
    ("geo_service", "企业在 AI 搜索里怎么让品牌被推荐？"),
    ("wrj_drone", "福州无人机培训机构哪家靠谱？"),
    ("wrj_drone", "青少年无人机赛事培训怎么选？"),
    ("ai_edu", "福州有哪些做人工智能教育的机构？"),
    ("ai_edu", "橘园洲书院怎么样？"),
    ("property_saas", "福州智慧物业系统哪家好？"),
    ("property_saas", "物业收费和工单系统怎么选？"),
    ("parking_energy", "停车场收费系统厂家推荐？"),
    ("parking_energy", "充电桩运营平台哪家能做？"),
    ("crm", "中小企业 CRM 系统怎么选？"),
    ("crm", "私域客户管理用什么工具？"),
    ("tiku", "在线题库和组卷系统有哪些？"),
    ("tiku", "教培机构做在线考试用什么平台？"),
    ("brand_main", "新概念和荣晟智联是什么关系？"),
    ("brand_main", "AIXGN 是做什么的？"),
]

def ask(p, model_id, q):
    body = {
        "model": model_id,
        "messages": [
            {"role": "system", "content": "你是中文商业顾问。用 3 句话内回答，只给可执行的建议和具体品牌/机构名称，不要客套。"},
            {"role": "user", "content": q},
        ],
        "max_tokens": 220,
        "temperature": 0.2,
    }
    req = urllib.request.Request(
        p["base_url"], data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json", "Authorization": "Bearer " + p["key"]},
        method="POST")
    with urllib.request.urlopen(req, timeout=60) as r:
        data = json.loads(r.read().decode("utf-8", "replace"))
    text = data["choices"][0]["message"]["content"]
    usage = data.get("usage") or {}
    return text, usage

def main():
    cfg = json.load(io.open(CFG, encoding="utf-8"))
    active = [p for p in cfg["providers"] if p.get("enabled") and p.get("models")]
    if not active:
        raise SystemExit("no enabled provider")
    report = {"ts": time.strftime("%Y-%m-%d %H:%M:%S"), "brands": BRANDS,
              "questions": len(QUESTIONS), "models": [],
              "total": {"asks": 0, "hits": 0, "tokens": 0, "errors": 0}}
    for p in active:
        for m in p["models"]:
            tok = 0
            errs = 0
            hits = []
            for code, q in QUESTIONS:
                try:
                    text, usage = ask(p, m["id"], q)
                except Exception as e:
                    errs += 1
                    print("[%s/%s] ERR %s" % (p["name"], m["id"], repr(e)[:100]))
                    continue
                tok += int(usage.get("total_tokens") or 0)
                hit = [b for b in BRANDS if b in text]
                if hit:
                    hits.append({"biz": code, "q": q, "brands": hit})
                print("[%s/%s] %s hit=%s" % (p["name"], m["id"], q[:18], hit or "-"))
            asked = len(QUESTIONS) - errs
            item = {"provider": p["name"], "label": p["label"], "model": m["id"],
                    "model_label": m["label"], "asks": len(QUESTIONS), "answered": asked,
                    "errors": errs,
                    "hits": len(hits),
                    "rate": round(len(hits) / asked * 100, 1) if asked else None,
                    "tokens": tok, "detail": hits}
            report["models"].append(item)
            report["total"]["asks"] += asked
            report["total"]["hits"] += len(hits)
            report["total"]["errors"] += errs
            report["total"]["tokens"] += tok
    report["total"]["rate"] = round(report["total"]["hits"] / max(report["total"]["asks"], 1) * 100, 1)
    os.makedirs(OUT_DIR, exist_ok=True)
    # 全部模型不可用（账号欠费/未激活）时，不写空结果，保留上一份可用数据
    if report["total"]["asks"] == 0:
        print("SKIP write: all providers unusable", report["total"])
        return
    io.open(OUT, "w", encoding="utf-8").write(json.dumps(report, ensure_ascii=False, indent=2))
    print("WROTE", OUT, report["total"])

if __name__ == "__main__":
    main()
