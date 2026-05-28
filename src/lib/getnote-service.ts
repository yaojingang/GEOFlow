import { z } from "zod";
import { extractExternalContent, extractUploadedFile, firstUrl, type ExtractedContent } from "@/lib/external-content";
import { generateGetNoteDraft, type GetNoteDraft, type GetNoteMode } from "@/lib/getnote-generator";

const GetNotePayloadSchema = z.object({
  mode: z.enum(["text", "link", "image", "recall"]).optional().default("text"),
  content: z.string().trim().max(20000).optional().default(""),
  context: z.string().trim().max(800).optional(),
});

export type GetNoteRequestPayload = {
  mode: GetNoteMode;
  content: string;
  context?: string;
  file?: File;
};

export type GetNoteResult = {
  draft: GetNoteDraft;
  markdown: string;
};

export async function readGetNotePayload(request: Request): Promise<
  | {
      success: true;
      data: GetNoteRequestPayload;
    }
  | { success: false; error: string }
> {
  const contentType = request.headers.get("content-type") || "";
  if (contentType.includes("multipart/form-data")) {
    const formData = await request.formData();
    const file = formData.get("file");
    const content = String(formData.get("content") || "").trim();
    const context = String(formData.get("context") || "").trim();
    if (!(file instanceof File) && !content) {
      return { success: false, error: "请输入文章、链接，或上传一个文件。" };
    }
    if (file instanceof File && file.size > 12 * 1024 * 1024) {
      return { success: false, error: "文件不要超过 12MB。大文件请先提取正文再粘贴。" };
    }
    return {
      success: true,
      data: {
        mode: "text",
        content: content.slice(0, 20000),
        context: context.slice(0, 800),
        file: file instanceof File ? file : undefined,
      },
    };
  }

  const parsed = GetNotePayloadSchema.safeParse(await request.json());
  if (!parsed.success || !parsed.data.content.trim()) {
    return { success: false, error: "请输入文章、链接，或上传一个文件。" };
  }
  return { success: true, data: { ...parsed.data, content: parsed.data.content.trim() } };
}

export async function generateGetNoteFromPayload(payload: GetNoteRequestPayload): Promise<GetNoteResult> {
  const url = firstUrl(payload.content);
  const source = payload.file ? await extractUploadedFile(payload.file) : url ? await extractExternalContent(url) : null;
  const draft = await generateGetNoteDraft({
    mode: source?.platform === "image" ? "image" : url ? "link" : payload.mode,
    context: payload.context,
    content: source ? buildSourcePrompt(source, payload.content) : payload.content,
  });

  return {
    draft,
    markdown: getNoteToMarkdown(draft),
  };
}

export function getNoteToMarkdown(draft: GetNoteDraft) {
  const sourceLabel: Record<GetNoteDraft["source"], string> = {
    notebooklm: "NotebookLM",
    model: "GPT",
    rules: "rules",
  };
  return [`# ${draft.title.trim() || "GetNote"}`, "", draft.summary.trim(), "", `来源: ${sourceLabel[draft.source]}`].join("\n").trim();
}

function buildSourcePrompt(source: ExtractedContent, userText: string) {
  return [
    `来源类型：${source.source}`,
    `来源平台：${source.platform}`,
    source.url ? `来源链接：${source.url}` : "",
    source.fileName ? `文件名：${source.fileName}` : "",
    source.title ? `标题：${source.title}` : "",
    source.warning ? `提取提醒：${source.warning}` : "",
    userText && !firstUrl(userText) ? `补充说明：${userText}` : "",
    "正文：",
    source.text.slice(0, 18000),
  ]
    .filter(Boolean)
    .join("\n");
}
