import { prisma } from "@/lib/prisma";
import { getWorkspaceState } from "@/lib/workspace-service";

const sourceKindLabels: Record<string, string> = {
  brand: "品牌事实",
  proof: "可信证据",
  faq: "FAQ 问答",
  competitor: "竞品对比",
  case: "客户案例",
  social: "社媒素材",
  image: "图片素材",
  analytics: "分析工具",
  policy: "政策/规则",
};

function cleanHtml(html: string) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<noscript[\s\S]*?<\/noscript>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&#39;/g, "'")
    .replace(/&quot;/g, "\"")
    .replace(/\s+/g, " ")
    .trim();
}

function normalizeKind(type: string, title: string, url?: string | null) {
  const text = `${type} ${title} ${url ?? ""}`.toLowerCase();
  if (/faq|问答|常见问题|question|q&a/.test(text)) return "faq";
  if (/竞品|对比|comparison|compare|competitor|竞对/.test(text)) return "competitor";
  if (/案例|客户|case|story|success/.test(text)) return "case";
  if (/小红书|抖音|视频号|公众号|wechat|douyin|xhs|social|社媒/.test(text)) return "social";
  if (/图片|海报|封面|配图|image|photo|poster|cover|png|jpg|jpeg|webp|gif/.test(text)) return "image";
  if (/ga4|analytics|统计|search console|百度统计|数据/.test(text)) return "analytics";
  if (/政策|规则|协议|条款|资质|备案|policy|terms/.test(text)) return "policy";
  if (/手册|白皮书|报告|pdf|资料|manual|guide|proof|证据/.test(text)) return "proof";
  return "brand";
}

function buildRouting(kind: string) {
  const base = {
    kind,
    label: sourceKindLabels[kind] ?? "品牌事实",
    retrievalScopes: ["brand", "report"],
    suggestedTools: ["extract_brand_fact", "report_evidence"],
  };

  const map: Record<string, typeof base> = {
    brand: base,
    proof: {
      kind,
      label: sourceKindLabels.proof,
      retrievalScopes: ["brand", "proof", "report"],
      suggestedTools: ["extract_brand_fact", "report_evidence", "content_citation"],
    },
    faq: {
      kind,
      label: sourceKindLabels.faq,
      retrievalScopes: ["faq", "content", "doubao"],
      suggestedTools: ["extract_question_answer", "content_gap_writer"],
    },
    competitor: {
      kind,
      label: sourceKindLabels.competitor,
      retrievalScopes: ["competitor", "comparison", "doubao"],
      suggestedTools: ["extract_comparison", "competitor_gap"],
    },
    case: {
      kind,
      label: sourceKindLabels.case,
      retrievalScopes: ["case", "proof", "content"],
      suggestedTools: ["extract_case", "content_case_writer"],
    },
    social: {
      kind,
      label: sourceKindLabels.social,
      retrievalScopes: ["social", "content"],
      suggestedTools: ["extract_social_angle", "short_content_writer"],
    },
    image: {
      kind,
      label: sourceKindLabels.image,
      retrievalScopes: ["image", "content", "social"],
      suggestedTools: ["select_content_image", "short_content_visual"],
    },
    analytics: {
      kind,
      label: sourceKindLabels.analytics,
      retrievalScopes: ["analytics", "report"],
      suggestedTools: ["extract_metric", "report_evidence"],
    },
    policy: {
      kind,
      label: sourceKindLabels.policy,
      retrievalScopes: ["policy", "proof", "report"],
      suggestedTools: ["extract_constraint", "report_evidence"],
    },
  };

  return map[kind] ?? base;
}

function pickSentences(text: string) {
  return text
    .split(/(?<=[。！？.!?])\s+/)
    .map((item) => item.trim())
    .filter((item) => item.length > 18)
    .slice(0, 4);
}

type PageCitation = {
  page: number;
  excerpt: string;
  textLength: number;
};

function buildPageCitations(pages: Array<{ num: number; text: string }>): PageCitation[] {
  return pages
    .map((page) => {
      const text = page.text.replace(/\s+/g, " ").trim();
      return {
        page: page.num,
        excerpt: text.slice(0, 240),
        textLength: text.length,
      };
    })
    .filter((page) => page.textLength > 0)
    .slice(0, 12);
}

async function extractPdfText(buffer: Buffer) {
  const { PDFParse } = await import("pdf-parse");
  const parser = new PDFParse({ data: buffer });
  try {
    const parsed = await parser.getText();
    return {
      text: parsed.text.replace(/\s+/g, " ").trim(),
      totalPages: parsed.total,
      pageCitations: buildPageCitations(parsed.pages),
    };
  } finally {
    await parser.destroy();
  }
}

function buildSummary(text: string) {
  const sentences = pickSentences(text);
  return (
    sentences.join(" ") ||
    text.slice(0, 500) ||
    "已抓取资料，但没有提取到足够正文。建议手工补充摘要。"
  );
}

async function upsertEvidenceFact({
  workspaceId,
  kind,
  title,
  summary,
  url,
}: {
  workspaceId: string;
  kind: string;
  title: string;
  summary: string;
  url?: string | null;
}) {
  const safeTitle = title.length > 70 ? title.slice(0, 70) : title;
  const factTitle = `${sourceKindLabels[kind] ?? "资料事实"}：${safeTitle}`;
  const existingFact = await prisma.brandFact.findFirst({
    where: {
      workspaceId,
      title: factTitle,
    },
  });

  if (!existingFact) {
    await prisma.brandFact.create({
      data: {
        workspaceId,
        title: factTitle,
        body: summary.slice(0, 700),
        evidenceUrl: url,
        confidence: kind === "proof" || kind === "case" ? 78 : 72,
      },
    });
  }

  return !existingFact;
}

export async function processSourceAsset(id: string) {
  const state = await getWorkspaceState();
  const source = state.sourceAssets.find((item) => item.id === id);

  if (!source) {
    throw new Error("Source not found");
  }

  const inferredKind = normalizeKind(source.type, source.title, source.url);
  const kind = !source.kind || source.kind === "brand" ? inferredKind : source.kind;
  const routing = buildRouting(kind);

  if (!source.url) {
    const updated = await prisma.sourceAsset.update({
      where: { id },
      data: {
        status: "needs-url",
        kind,
        routing,
        summary: source.summary ?? "这条资料没有 URL。请补充链接，或手工填写摘要和品牌事实。",
      },
    });
    return { source: updated, factCreated: false };
  }

  const response = await fetch(source.url, {
    headers: {
      "User-Agent": "geo.youngtuo.win source processor/1.0",
    },
    signal: AbortSignal.timeout(20000),
  });

  if (!response.ok) {
    const updated = await prisma.sourceAsset.update({
      where: { id },
      data: {
        status: "fetch-failed",
        kind,
        routing,
        summary: `抓取失败：HTTP ${response.status}。请检查链接是否公开可访问。`,
      },
    });
    return { source: updated, factCreated: false };
  }

  const contentType = response.headers.get("content-type") ?? "";
  const isPdf = contentType.includes("pdf") || /\.pdf($|\?)/i.test(source.url);
  let text = "";
  let parseMode = "text";
  let parseMetadata: Record<string, unknown> = {};

  if (isPdf) {
    parseMode = "pdf";
    const buffer = Buffer.from(await response.arrayBuffer());
    const pdf = await extractPdfText(buffer);
    text = pdf.text;
    parseMetadata = {
      totalPages: pdf.totalPages,
      pageCitations: pdf.pageCitations,
    };
  } else {
    const raw = await response.text();
    text = contentType.includes("html") ? cleanHtml(raw) : raw.replace(/\s+/g, " ").trim();
    parseMode = contentType.includes("html") ? "html" : "text";
  }

  if (!text || text.length < 40) {
    const updated = await prisma.sourceAsset.update({
      where: { id },
      data: {
        status: "no-readable-text",
        kind,
        mimeType: contentType || (isPdf ? "application/pdf" : null),
        routing,
        metadata: {
          parseMode,
          textLength: text.length,
          note: "资料抓取成功，但没有提取到足够正文。",
        },
        summary: "资料已访问，但没有提取到足够可读文本。建议补充摘要，或上传可复制文字版 PDF。",
      },
    });
    return { source: updated, factCreated: false };
  }

  const summary = buildSummary(text);

  const updated = await prisma.sourceAsset.update({
    where: { id },
    data: {
      status: "processed",
      kind,
      processedText: text.slice(0, 20000),
      mimeType: contentType || (isPdf ? "application/pdf" : "text/plain"),
      routing,
      metadata: {
        parseMode,
        textLength: text.length,
        ...parseMetadata,
        extractedAt: new Date().toISOString(),
        retrievalScopes: routing.retrievalScopes,
      },
      summary: summary.slice(0, 1000),
    },
  });

  const factCreated = await upsertEvidenceFact({
    workspaceId: state.workspace.id,
    kind,
    title: source.title,
    summary,
    url: source.url,
  });

  return { source: updated, factCreated, routing };
}

export async function processUploadedSourceAsset({
  workspaceId,
  type,
  title,
  fileName,
  mimeType,
  buffer,
  summary,
}: {
  workspaceId: string;
  type: string;
  title: string;
  fileName: string;
  mimeType: string;
  buffer: Buffer;
  summary?: string | null;
}) {
  const inferredKind = normalizeKind(type, title, fileName);
  const routing = buildRouting(inferredKind);
  const lowerName = fileName.toLowerCase();
  const isImage =
    mimeType.startsWith("image/") ||
    lowerName.endsWith(".png") ||
    lowerName.endsWith(".jpg") ||
    lowerName.endsWith(".jpeg") ||
    lowerName.endsWith(".webp") ||
    lowerName.endsWith(".gif");
  const isPdf = mimeType.includes("pdf") || lowerName.endsWith(".pdf");
  const isHtml = mimeType.includes("html") || lowerName.endsWith(".html") || lowerName.endsWith(".htm");
  const isTextLike =
    mimeType.startsWith("text/") ||
    mimeType.includes("json") ||
    lowerName.endsWith(".md") ||
    lowerName.endsWith(".csv") ||
    lowerName.endsWith(".json") ||
    lowerName.endsWith(".txt");

  let text = "";
  let parseMode = "upload";
  let parseMetadata: Record<string, unknown> = {};

  if (isImage) {
    const imageMimeType = mimeType.startsWith("image/") ? mimeType : "image/jpeg";
    const dataUrl = `data:${imageMimeType};base64,${buffer.toString("base64")}`;
    return prisma.sourceAsset.create({
      data: {
        workspaceId,
        type,
        kind: "image",
        title,
        url: dataUrl,
        status: "ready",
        summary: summary || "图片已上传，可作为官网封面、报告配图、案例截图或社媒封面素材。",
        mimeType: imageMimeType,
        routing: buildRouting("image"),
        metadata: {
          source: "upload",
          fileName,
          fileSize: buffer.byteLength,
          parseMode: "uploaded-image",
          retrievalScopes: ["image", "content", "social"],
          uploadedAt: new Date().toISOString(),
        },
      },
    });
  }

  if (isPdf) {
    parseMode = "uploaded-pdf";
    const pdf = await extractPdfText(buffer);
    text = pdf.text;
    parseMetadata = {
      totalPages: pdf.totalPages,
      pageCitations: pdf.pageCitations,
    };
  } else if (isTextLike || isHtml) {
    const raw = buffer.toString("utf8");
    text = isHtml ? cleanHtml(raw) : raw.replace(/\s+/g, " ").trim();
    parseMode = isHtml ? "uploaded-html" : "uploaded-text";
  } else {
    return prisma.sourceAsset.create({
      data: {
        workspaceId,
        type,
        kind: inferredKind,
        title,
        status: "unsupported-file",
        summary:
          summary ||
          "文件已收到，但当前只自动解析 PDF、HTML、TXT、Markdown、CSV 和 JSON。请先转成可复制文字版，或手工填写摘要。",
        mimeType,
        routing,
        metadata: {
          source: "upload",
          fileName,
          fileSize: buffer.byteLength,
          parseMode: "unsupported",
          supportedTypes: ["pdf", "html", "txt", "md", "csv", "json"],
        },
      },
    });
  }

  if (!text || text.length < 40) {
    return prisma.sourceAsset.create({
      data: {
        workspaceId,
        type,
        kind: inferredKind,
        title,
        status: isPdf ? "needs-ocr" : "no-readable-text",
        summary:
          summary ||
          (isPdf
            ? "PDF 已上传，但没有提取到足够可复制文字。它很可能是扫描版，需要 OCR 后再进入证据库。"
            : "文件已上传，但没有提取到足够可读文本。请检查编码或手工补充摘要。"),
        mimeType,
        routing,
        metadata: {
          source: "upload",
          fileName,
          fileSize: buffer.byteLength,
          parseMode,
          textLength: text.length,
          ...parseMetadata,
          ocrStatus: isPdf ? "required" : "not_applicable",
        },
      },
    });
  }

  const finalSummary = summary?.trim() || buildSummary(text);
  const source = await prisma.sourceAsset.create({
    data: {
      workspaceId,
      type,
      kind: inferredKind,
      title,
      status: "processed",
      summary: finalSummary.slice(0, 1000),
      processedText: text.slice(0, 20000),
      mimeType,
      routing,
      metadata: {
        source: "upload",
        fileName,
        fileSize: buffer.byteLength,
        parseMode,
        textLength: text.length,
        ...parseMetadata,
        extractedAt: new Date().toISOString(),
        retrievalScopes: routing.retrievalScopes,
      },
    },
  });

  const factCreated = await upsertEvidenceFact({
    workspaceId,
    kind: inferredKind,
    title,
    summary: finalSummary,
    url: null,
  });

  return { source, factCreated, routing };
}
