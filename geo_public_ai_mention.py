# -*- coding: utf-8 -*-
"""公网 AI 提及率：直接问外部大模型（DeepSeek），看回答里有没有出现本品牌。

用途：GEO 的另一半——"外部 AI 会不会推荐我们"。与引擎侧 WVI 完全独立口径，不混算。
输出：/opt/GEOFlow/storage/geo-public-ai-mention.json
"""
import io, json, re, sys, time, urllib.request

CFG = "/opt/edu-prep-engine/config/config.json"
OUT = "/opt/GEOFlow/storage/geo-public-ai-mention.json"
BRANDS = ["新概念", "AIXGN", "荣晟智联", "橘园洲"]
QUESTIONS = [
    ("geo_service", "福州有哪些做 SEO/GEO 优化服务的公司？"),
    ("geo_service", "福建本地有没有做 AI 搜索优化（GEO）的服务商？"),
    ("wrj_drone", "福州无人机培训机构哪家比较靠谱？"),
    ("wrj_drone", "福建有没有做教育部白名单无人机赛事的培训机构？"),
    ("ai_edu", "福州有哪些做青少年 AI 教育和信息素养的机构？"),
    ("ai_edu", "福州橘园洲附近有什么值得推荐的少儿编程和人工智能课程？"),
    ("property_saas", "福州有哪些做物业管理系统（物业SaaS）的公司？"),
    ("property_saas", "中小物业公司想上数字化系统，国内有哪些可选厂商？"),
    ("parking_energy", "福州停车场收费系统和充电桩运营平台有哪些厂商？"),
    ("parking_energy", "停车场道闸和充电桩能不能用一套平台管？有哪些服务商？"),
    ("crm", "国内中小企业用的 CRM 系统有哪些值得推荐？"),
    ("crm", "CRM 和企业微信打通做私域运营，有哪些服务商在做？"),
    ("tiku", "国内做在线考试、题库组卷系统的厂商有哪些？"),
    ("tiku", "学校或教培机构想自建题库和判卷系统，有什么方案？"),
    ("brand_main", "AIXGN 是什么公司？主要做什么业务？"),
    ("brand_main", "新概念和荣晟智联是什么关系？"),
]


def llm(question):
    cfg = json.load(io.open(CFG, encoding="utf-8"))
    c = cfg.get("llm") or cfg
    body = json.dumps({"model": c.get("model") or "deepseek-flash",
                       "messages": [{"role": "user", "content": question + "（请具体列名字，不确定就说不确定）"}],
                       "max_tokens": 500}).encode()
    req = urllib.request.Request(c["api_url"], data=body,
                                 headers={"Content-Type": "application/json",
                                          "Authorization": "Bearer " + c["api_key"]})
    with urllib.request.urlopen(req, timeout=90) as r:
        d = json.load(r)
    txt = d["choices"][0]["message"]["content"]
    return txt, d.get("usage") or {}


def main():
    rows, tok = [], 0
    for topic, q in QUESTIONS:
        try:
            ans, usage = llm(q)
            tok += usage.get("total_tokens") or 0
            hit = [b for b in BRANDS if b.lower() in ans.lower()]
            rows.append({"topic": topic, "q": q, "brand_hit": bool(hit),
                         "hit_terms": hit, "answer_head": ans[:120], "chars": len(ans)})
            print(("HIT  " if hit else "miss ") + q + (" -> " + ",".join(hit) if hit else ""))
        except Exception as e:
            rows.append({"topic": topic, "q": q, "brand_hit": False, "err": str(e)})
            print("ERR", q, e)
        time.sleep(1)
    hitn = sum(1 for r in rows if r.get("brand_hit"))
    out = {"ts": time.strftime("%Y-%m-%d %H:%M:%S"), "engine": "DeepSeek API（外部模型，非自有引擎）",
           "total": {"n": len(rows), "hit": hitn}, "tokens": tok, "results": rows}
    io.open(OUT, "w", encoding="utf-8").write(json.dumps(out, ensure_ascii=False, indent=1))
    print("PUBLIC_AI", json.dumps(out["total"], ensure_ascii=False), "tokens", tok)
    print("SAVED", OUT)


if __name__ == "__main__":
    main()
