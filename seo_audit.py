# -*- coding: utf-8 -*-
"""SEO/GEO 站点体检：抓取主要站点页面，输出结构化体检结果 + Markdown 报告。
不修改任何站点内容，只读。"""
import io, json, os, re, ssl, time, urllib.request, urllib.error

SITES = {
    "geo": {"base": "https://geo.aixgn.cn",
            "pages": ["/", "/entity/index.html", "/entity/wrj.html", "/entity/j.html", "/entity/geo.html",
                      "/seo-content/facts/drone-school-checklist.html",
                      "/seo-content/facts/whitelist-competition-check.html",
                      "/seo-content/facts/what-is-geo.html",
                      "/seo-content/facts/drone-license-cost.html",
                      "/seo-content/facts/drone-license-timeline.html",
                      "/seo-content/facts/drone-vs-fixed-wing.html",
                      "/seo-content/facts/property-saas-selection.html", "/seo-content/facts/parking-charging-selection.html", "/seo-content/facts/crm-selection.html", "/seo-content/facts/tiku-system-selection.html", "/seo-content/facts/property-fee-ledger.html", "/seo-content/facts/property-workorder-loop.html", "/seo-content/facts/property-saas-vs-spreadsheet.html", "/seo-content/facts/parking-billing-rules.html", "/seo-content/facts/parking-unattended-faq.html", "/seo-content/facts/charging-ocpp-guide.html", "/seo-content/facts/crm-lead-attribution.html", "/seo-content/facts/crm-wecom-private.html", "/seo-content/facts/crm-free-vs-paid.html", "/seo-content/facts/tiku-paper-strategy.html", "/entity/brand.html", "/seo-content/facts/tiku-grading.html", "/seo-content/facts/tiku-analytics.html", "/solutions/property.html", "/solutions/parking.html", "/solutions/charging.html",
                      "/uav-education.html", "/uav-education-guide.html", "/solutions/education.html", "/solutions/drone.html", "/solutions/geo.html", "/solutions/crm.html", "/solutions/tiku.html",
                      "/robots.txt", "/sitemap.xml"],
            "aliases": ["新概念", "AIXGN", "SEOGEO", "GEOFlow"]},
    "j": {"base": "https://j.aixgn.cn", "pages": ["/", "/robots.txt", "/sitemap.xml"],
          "aliases": ["橘园洲", "新概念", "AIXGN"]},
    "wrj": {"base": "https://wrj.aixgn.cn", "pages": ["/", "/robots.txt", "/sitemap.xml"],
            "aliases": ["WRJ", "新概念", "AIXGN"]},
    "blog": {"base": "https://blog.aixgn.cn", "pages": ["/", "/sitemap.xml", "/rss.xml"],
             "aliases": ["AIXGN", "新概念", "SEOGEO"]},
}

UA = "Mozilla/5.0 (compatible; SEOAudit/1.0; +https://geo.aixgn.cn)"
CTX = ssl.create_default_context()
CTX.check_hostname = False
CTX.verify_mode = ssl.CERT_NONE


def get(url, timeout=25):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    t0 = time.time()
    try:
        r = urllib.request.urlopen(req, timeout=timeout, context=CTX)
        body = r.read()
        return {"status": r.getcode(), "bytes": len(body), "ttfb_ms": int((time.time() - t0) * 1000),
                "final_url": r.geturl(), "ctype": r.headers.get("Content-Type", ""), "body": body}
    except urllib.error.HTTPError as e:
        return {"status": e.code, "bytes": 0, "ttfb_ms": int((time.time() - t0) * 1000),
                "final_url": url, "ctype": "", "body": b""}
    except Exception as e:
        return {"status": 0, "error": "%s: %s" % (type(e).__name__, str(e)[:70]),
                "ttfb_ms": int((time.time() - t0) * 1000), "final_url": url, "ctype": "", "body": b""}


def tag(html, pattern, flags=re.I | re.S):
    m = re.search(pattern, html, flags)
    return (m.group(1) or "").strip() if m else ""


def analyze(url, res, aliases):
    info = {"url": url, "status": res.get("status"), "ttfb_ms": res.get("ttfb_ms"),
            "bytes": res.get("bytes"), "final_url": res.get("final_url"), "ctype": res.get("ctype")}
    if res.get("error"):
        info["error"] = res["error"]
        return info
    body = res.get("body") or b""
    ctype = res.get("ctype") or ""
    if "xml" in ctype or url.endswith(".xml") or url.endswith("robots.txt"):
        txt = body.decode("utf-8", "ignore")
        if url.endswith("robots.txt"):
            info["robots_lines"] = len([l for l in txt.splitlines() if l.strip() and not l.startswith("#")])
            info["ai_bots"] = len(re.findall(r"(GPTBot|ClaudeBot|PerplexityBot|Bytespider|Google-Extended|"
                                             r"Applebot|CCBot|OAI-SearchBot|Bingbot|Baiduspider|YisouSpider)",
                                             txt, re.I))
            info["sitemap_declared"] = "Sitemap:" in txt
        else:
            info["sitemap_urls"] = len(re.findall(r"<loc>", txt))
            info["rss_items"] = len(re.findall(r"<item", txt))
            info["sitemap_bad"] = "sitemapindex" in txt
        return info
    html = body.decode("utf-8", "ignore")
    info["title"] = tag(html, r"<title[^>]*>(.*?)</title>")
    info["title_len"] = len(info["title"])
    info["desc"] = tag(html, r'<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)')
    if not info["desc"]:
        info["desc"] = tag(html, r'<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\']')
    info["desc_len"] = len(info["desc"])
    info["canonical"] = tag(html, r'<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)')
    info["robots_meta"] = tag(html, r'<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)')
    info["h1"] = len(re.findall(r"<h1\b", html, re.I))
    info["h2"] = len(re.findall(r"<h2\b", html, re.I))
    info["og"] = len(re.findall(r'property=["\']og:', html))
    info["twitter"] = len(re.findall(r'name=["\']twitter:', html))
    info["jsonld"] = re.findall(r'"@type"\s*:\s*"([A-Za-z]+)"', html)
    info["ld_types"] = sorted(set(info["jsonld"]))
    info["has_ld"] = len(re.findall(r'application/ld\+json', html))
    imgs = re.findall(r"<img\b[^>]*>", html, re.I)
    info["img"] = len(imgs)
    info["img_no_alt"] = len([i for i in imgs if 'alt="' not in i and "alt='" not in i])
    links = re.findall(r'<a\b[^>]+href=["\']([^"\']+)', html, re.I)
    info["links"] = len(links)
    info["links_internal"] = len([l for l in links if l.startswith("/") or "aixgn.cn" in l])
    text = re.sub(r"<script.*?</script>|<style.*?</style>", " ", html, flags=re.I | re.S)
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    info["text_chars"] = len(text)
    info["brand_mentions"] = sum(text.count(a) for a in aliases)
    info["html_lang"] = tag(html, r'<html[^>]+lang=["\']([^"\']+)')
    info["viewport"] = bool(re.search(r'name=["\']viewport["\']', html, re.I))
    return info


def main():
    outdir = "/opt/site-seo/audit-" + time.strftime("%Y%m%d-%H%M")
    os.makedirs(outdir, exist_ok=True)
    report = {"ts": time.strftime("%Y-%m-%d %H:%M:%S"), "sites": {}}
    for key, cfg in SITES.items():
        rows = []
        for p in cfg["pages"]:
            url = cfg["base"] + p
            rows.append(analyze(url, get(url), cfg["aliases"]))
            print("%-6s %-62s %s" % (key, p[:60], rows[-1].get("status")), flush=True)
        report["sites"][key] = {"base": cfg["base"], "pages": rows}
    io.open(os.path.join(outdir, "seo-audit.json"), "w", encoding="utf-8").write(
        json.dumps(report, ensure_ascii=False, indent=2))

    md = ["# SEO/GEO 站点体检报告（%s）" % report["ts"], ""]
    issues = []
    for key, site in report["sites"].items():
        md.append("## %s（%s）" % (key, site["base"]))
        md.append("")
        md.append("| 页面 | 状态 | 标题字数 | 描述字数 | canonical | H1 | JSON-LD | 图片/缺alt | 正文 |")
        md.append("|---|---|---|---|---|---|---|---|---|")
        for r in site["pages"]:
            if r.get("status") is None or r.get("error"):
                md.append("| %s | %s | - | - | - | - | - | - | - |" % (r["url"].replace(site["base"], ""), r.get("error", "fail")))
                issues.append((key, r["url"], "抓取失败：%s" % r.get("error", r.get("status"))))
                continue
            if "sitemap_urls" in r:
                md.append("| /sitemap.xml | %s | - | - | - | - | - | - | %s 条 URL |" % (r["status"], r["sitemap_urls"]))
                continue
            if "robots_lines" in r:
                md.append("| /robots.txt | %s | - | - | - | - | - | - | %s 行 / AI 抓取方 %s / Sitemap %s |"
                          % (r["status"], r["robots_lines"], r["ai_bots"], "有" if r["sitemap_declared"] else "无"))
                continue
            path = r["url"].replace(site["base"], "") or "/"
            md.append("| %s | %s | %s | %s | %s | %s | %s | %s/%s | %s 字 |" % (
                path, r["status"], r["title_len"], r["desc_len"],
                "有" if r.get("canonical") else "无", r["h1"], ",".join(r["ld_types"]) or "无",
                r["img"], r["img_no_alt"], r["text_chars"]))
            if r["title_len"] < 15 or r["title_len"] > 65:
                issues.append((key, path, "标题长度异常 %s" % r["title_len"]))
            if r["desc_len"] < 50 or r["desc_len"] > 170:
                issues.append((key, path, "描述长度异常 %s" % r["desc_len"]))
            if not r.get("canonical"):
                issues.append((key, path, "缺 canonical"))
            if r["h1"] != 1:
                issues.append((key, path, "H1 数量=%s" % r["h1"]))
            if not r["has_ld"]:
                issues.append((key, path, "缺 JSON-LD 结构化数据"))
            if r["img_no_alt"]:
                issues.append((key, path, "图片缺 alt %s 张" % r["img_no_alt"]))
            if r["robots_meta"] and "noindex" in r["robots_meta"].lower():
                issues.append((key, path, "noindex"))
        md.append("")
    md.append("## 待修问题（按严重度）")
    md.append("")
    for i, (k, u, m) in enumerate(issues, 1):
        md.append("%d. [%s] %s —— %s" % (i, k, u, m))
    io.open(os.path.join(outdir, "seo-audit.md"), "w", encoding="utf-8").write("\n".join(md))
    print("ISSUES", len(issues), "OUT", outdir)


if __name__ == "__main__":
    main()
