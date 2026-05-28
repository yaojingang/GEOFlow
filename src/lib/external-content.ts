import { YoutubeTranscript } from "youtube-transcript";

export type ExtractedContent = {
  source: "url" | "file" | "text";
  url?: string;
  fileName?: string;
  platform: "xiaohongshu" | "douyin" | "youtube" | "pdf" | "docx" | "text" | "image" | "audio" | "video" | "file" | "web";
  title?: string;
  text: string;
  warning?: string;
};

const userAgent =
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36";

export function firstUrl(value: string) {
  return value.match(/https?:\/\/[^\s<>"']+/)?.[0];
}

export async function extractExternalContent(url: string): Promise<ExtractedContent> {
  const platform = detectPlatform(url);
  if (platform === "youtube") {
    const youtube = await extractYouTube(url);
    if (youtube.text.trim()) return youtube;
  }

  const response = await fetchReadable(url);
  const contentType = response.headers.get("content-type") || "";
  if (contentType.includes("application/pdf") || url.toLowerCase().split("?")[0].endsWith(".pdf")) {
    return extractPdfBuffer(Buffer.from(await response.arrayBuffer()), {
      source: "url",
      url,
      title: safeFileTitle(url),
    });
  }

  if (contentType.startsWith("text/plain")) {
    const text = cleanText(await response.text());
    return {
      source: "url",
      url,
      platform: "text",
      title: safeFileTitle(url),
      text: text || url,
      warning: text.length < 80 ? "链接可提取文本较少，可能需要复制正文后再生成。" : undefined,
    };
  }

  const html = await response.text();
  const meta = extractMeta(html);
  const bodyText = cleanText(stripHtml(html));
  const pageText = platform === "xiaohongshu" || platform === "douyin" ? "" : bodyText.slice(0, 3500);
  const usefulText = cleanText([meta.title, meta.description, meta.stats, pageText].filter(Boolean).join("\n\n"));

  return {
    source: "url",
    url,
    platform,
    title: meta.title,
    text: usefulText || url,
    warning: usefulText.length < 80 ? "网页可提取内容较少，可能需要复制正文后再生成。" : undefined,
  };
}

export async function extractUploadedFile(file: File): Promise<ExtractedContent> {
  const fileName = file.name || "上传文件";
  const buffer = Buffer.from(await file.arrayBuffer());
  const mime = file.type || "";
  const ext = fileName.split(".").pop()?.toLowerCase() || "";
  const base = { source: "file" as const, fileName, title: fileName };

  if (mime === "application/pdf" || ext === "pdf") {
    return extractPdfBuffer(buffer, base);
  }

  if (
    mime === "application/vnd.openxmlformats-officedocument.wordprocessingml.document" ||
    ext === "docx"
  ) {
    const mammoth = await import("mammoth");
    const parsed = await mammoth.extractRawText({ buffer });
    const text = cleanText(parsed.value);
    return {
      ...base,
      platform: "docx",
      text: text || fileName,
      warning: text.length < 80 ? "DOCX 可提取正文较少，可能是扫描件或内容为空。" : undefined,
    };
  }

  if (isTextLike(mime, ext)) {
    const raw = buffer.toString("utf8");
    const text = ext === "html" || ext === "htm" ? cleanText(stripHtml(raw)) : cleanText(raw);
    return {
      ...base,
      platform: "text",
      text: text || fileName,
      warning: text.length < 80 ? "文件可提取文本较少，建议补充说明。" : undefined,
    };
  }

  if (mime.startsWith("image/") || ["png", "jpg", "jpeg", "webp", "gif", "heic"].includes(ext)) {
    return {
      ...base,
      platform: "image",
      text: `上传了一张图片文件：${fileName}。文件类型：${mime || ext}。如果图片里有文字，请同时粘贴文字说明，当前版本会先根据文件名和补充说明整理笔记。`,
      warning: "图片 OCR/视觉理解未稳定接入，已按图片素材信息生成。",
    };
  }

  if (mime.startsWith("audio/") || ["mp3", "m4a", "wav", "aac", "flac", "ogg"].includes(ext)) {
    return {
      ...base,
      platform: "audio",
      text: `上传了一个音频文件：${fileName}。文件类型：${mime || ext}。当前版本尚未做语音转写，请补充转写文本或公开视频链接。`,
      warning: "音频转写未接入，已按音频素材信息生成。",
    };
  }

  if (mime.startsWith("video/") || ["mp4", "mov", "webm", "mkv"].includes(ext)) {
    return {
      ...base,
      platform: "video",
      text: `上传了一个视频文件：${fileName}。文件类型：${mime || ext}。YouTube 链接会优先抓字幕，本地视频当前需补充转写文本。`,
      warning: "本地视频转写未接入，已按视频素材信息生成。",
    };
  }

  return {
    ...base,
    platform: "file",
    text: `上传文件：${fileName}。文件类型：${mime || ext || "未知"}。当前版本没有稳定提取到正文。`,
    warning: "暂不支持该文件正文提取，请改传 PDF/DOCX/TXT/MD/HTML/JSON/CSV，或粘贴正文。",
  };
}

function detectPlatform(url: string): ExtractedContent["platform"] {
  const host = safeHost(url);
  if (host.includes("xiaohongshu.com") || host.includes("xhslink.com")) return "xiaohongshu";
  if (host.includes("douyin.com") || host.includes("iesdouyin.com")) return "douyin";
  if (host.includes("youtube.com") || host.includes("youtu.be")) return "youtube";
  return "web";
}

async function extractYouTube(url: string): Promise<ExtractedContent> {
  const meta = await fetchHtml(url)
    .then(extractMeta)
    .catch(() => ({ title: "", description: "", stats: "" }));
  try {
    const transcript = await YoutubeTranscript.fetchTranscript(url);
    const text = transcript.map((item) => item.text).join("\n");
    return {
      source: "url",
      url,
      platform: "youtube",
      title: meta.title,
      text: cleanText([meta.title, meta.description, text].filter(Boolean).join("\n\n")),
    };
  } catch {
    return {
      source: "url",
      url,
      platform: "youtube",
      title: meta.title,
      text: cleanText([meta.title, meta.description].filter(Boolean).join("\n\n")),
      warning: "没有拿到 YouTube 字幕，已改用页面标题和描述生成。",
    };
  }
}

async function extractPdfBuffer(
  buffer: Buffer,
  source: { source: "url"; url: string; title?: string } | { source: "file"; fileName: string; title?: string },
): Promise<ExtractedContent> {
  const { PDFParse } = await import("pdf-parse");
  const parser = new PDFParse({ data: buffer });
  try {
    const parsed = await parser.getText();
    const text = cleanText(parsed.text);
    return {
      ...source,
      platform: "pdf",
      title: source.title,
      text: text || source.title || "PDF 文件",
      warning: text.length < 80 ? "PDF 可提取正文较少，可能是扫描件。" : undefined,
    };
  } finally {
    await parser.destroy();
  }
}

async function fetchHtml(url: string) {
  const response = await fetchReadable(url);
  return response.text();
}

async function fetchReadable(url: string) {
  const response = await fetch(url, {
    redirect: "follow",
    headers: {
      "User-Agent": userAgent,
      Accept: "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
      "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.7",
    },
  });
  if (!response.ok) throw new Error(`无法读取链接内容：HTTP ${response.status}`);
  return response;
}

function extractMeta(html: string) {
  const title = decodeHtml(
    pickMeta(html, "og:title") ||
      pickMeta(html, "twitter:title") ||
      html.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] ||
      "",
  );
  const description = decodeHtml(pickMeta(html, "description") || pickMeta(html, "og:description") || pickMeta(html, "twitter:description") || "");
  const stats = [
    statLine("点赞", pickMeta(html, "og:xhs:note_like")),
    statLine("收藏", pickMeta(html, "og:xhs:note_collect")),
    statLine("评论", pickMeta(html, "og:xhs:note_comment")),
  ]
    .filter(Boolean)
    .join("，");
  return {
    title: cleanText(title),
    description: cleanText(description),
    stats,
  };
}

function pickMeta(html: string, key: string) {
  const escaped = key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const patterns = [
    new RegExp(`<meta[^>]+(?:property|name)=["']${escaped}["'][^>]+content=["']([^"']*)["'][^>]*>`, "i"),
    new RegExp(`<meta[^>]+content=["']([^"']*)["'][^>]+(?:property|name)=["']${escaped}["'][^>]*>`, "i"),
  ];
  for (const pattern of patterns) {
    const match = html.match(pattern);
    if (match?.[1]) return match[1];
  }
  return "";
}

function stripHtml(html: string) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ");
}

function cleanText(value: string) {
  return decodeHtml(value)
    .replace(/\s+/g, " ")
    .replace(/\\u002F/g, "/")
    .trim();
}

function isTextLike(mime: string, ext: string) {
  return (
    mime.startsWith("text/") ||
    [
      "txt",
      "md",
      "markdown",
      "csv",
      "tsv",
      "json",
      "jsonl",
      "html",
      "htm",
      "xml",
      "log",
      "yaml",
      "yml",
    ].includes(ext)
  );
}

function decodeHtml(value: string) {
  return value
    .replace(/&amp;/g, "&")
    .replace(/&lt;/g, "<")
    .replace(/&gt;/g, ">")
    .replace(/&quot;/g, "\"")
    .replace(/&#39;/g, "'");
}

function statLine(label: string, value: string) {
  return value ? `${label} ${value}` : "";
}

function safeHost(url: string) {
  try {
    return new URL(url).hostname.toLowerCase();
  } catch {
    return "";
  }
}

function safeFileTitle(value: string) {
  try {
    const pathname = new URL(value).pathname;
    const last = pathname.split("/").filter(Boolean).at(-1);
    return decodeURIComponent(last || new URL(value).hostname);
  } catch {
    return value;
  }
}
