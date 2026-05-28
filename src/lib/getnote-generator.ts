import { spawn } from "node:child_process";
import path from "node:path";

export type GetNoteMode = "text" | "link" | "image" | "recall";

export type GetNoteInput = {
  mode: GetNoteMode;
  content: string;
  context?: string;
};

export type GetNoteDraft = {
  source: "notebooklm" | "model" | "rules";
  model: string;
  title: string;
  summary: string;
  noteType: string;
  tags: string[];
  knowledgeBases: string[];
  actions: string[];
  apiPreview: string;
  recallQueries: string[];
  safetyChecks: string[];
};

type ModelResponse = {
  choices?: Array<{
    message?: {
      content?: string;
    };
  }>;
};

type RawDraft = Omit<GetNoteDraft, "source" | "model">;

const modeLabels: Record<GetNoteMode, string> = {
  text: "文本笔记",
  link: "链接笔记",
  image: "图片笔记",
  recall: "语义检索",
};

export async function generateGetNoteDraft(input: GetNoteInput): Promise<GetNoteDraft> {
  const notebookResult = await tryNotebookLmDraft(input);
  if (notebookResult) return notebookResult;
  const modelResult = await tryModelDraft(input);
  if (modelResult) return modelResult;
  return generateRuleDraft(input);
}

function getNotebookLmConfig() {
  const hasAuth =
    Boolean(process.env.NOTEBOOKLM_AUTH_JSON) ||
    Boolean(process.env.NOTEBOOKLM_HOME) ||
    Boolean(process.env.GETNOTE_NOTEBOOKLM_STORAGE);

  return {
    enabled: process.env.GETNOTE_NOTEBOOKLM_ENABLED === "1" || process.env.GETNOTE_NOTEBOOKLM_ENABLED === "true" || hasAuth,
    python: process.env.GETNOTE_NOTEBOOKLM_PYTHON || "/opt/notebooklm/bin/python",
    script: process.env.GETNOTE_NOTEBOOKLM_SCRIPT || path.join(process.cwd(), "scripts/notebooklm_getnote.py"),
    timeoutMs: Number(process.env.GETNOTE_NOTEBOOKLM_TIMEOUT_MS || 150000),
  };
}

function getModelConfig() {
  return {
    apiKey: process.env.GETNOTE_AI_API_KEY || process.env.AGENT_PLANNER_API_KEY || process.env.DOUBAO_API_KEY || "",
    baseUrl: process.env.GETNOTE_AI_BASE_URL || process.env.AGENT_PLANNER_BASE_URL || "https://api.yundongyl.cn/v1",
    model: process.env.GETNOTE_AI_MODEL || process.env.AGENT_PLANNER_MODEL || "gpt-5.4-mini",
  };
}

async function tryModelDraft(input: GetNoteInput): Promise<GetNoteDraft | null> {
  const { apiKey, baseUrl, model } = getModelConfig();
  if (!apiKey) return null;

  try {
    const response = await fetch(`${baseUrl.replace(/\/$/, "")}/chat/completions`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model,
        temperature: 0.2,
        response_format: { type: "json_object" },
        messages: [
          {
            role: "system",
            content:
              "你是 NotebookLM 风格的来源转笔记助手。用户可能提供文章、网页、小红书、抖音、YouTube 字幕、PDF、DOCX、文本文件、图片/音视频素材说明。你的任务是把可提取素材转成一篇清晰、可直接保存的中文笔记。只输出 JSON，不要解释。输出格式：{\"title\":\"...\",\"summary\":\"...\",\"noteType\":\"source-note\",\"tags\":[\"...\"],\"knowledgeBases\":[\"文章笔记\"],\"actions\":[\"...\"],\"apiPreview\":\"\",\"recallQueries\":[\"...\"],\"safetyChecks\":[]}。summary 必须是完整笔记正文，包含来源、核心摘要、结构化要点、可复用结论；如果素材不足，直接说明缺口和需要补充什么。不要写 API、OpenAPI、命令行、密钥、预览这些技术说明。",
          },
          {
            role: "user",
            content: JSON.stringify({
              mode: input.mode,
              modeLabel: modeLabels[input.mode],
              content: input.content,
              context: input.context,
              requirements: [
                "把文章转成一篇可直接保存的笔记",
                "把不同来源先当作 NotebookLM source 处理，再生成统一笔记",
                "保留原文重点，删掉重复和无关内容",
                "用清晰小标题、要点和结论组织正文",
                "不要输出技术实现说明",
              ],
            }),
          },
        ],
      }),
    });

    if (!response.ok) return null;
    const data = (await response.json()) as ModelResponse;
    const parsed = parseDraft(data.choices?.[0]?.message?.content ?? "");
    return parsed ? { source: "model", model, ...parsed } : null;
  } catch {
    return null;
  }
}

async function tryNotebookLmDraft(input: GetNoteInput): Promise<GetNoteDraft | null> {
  const config = getNotebookLmConfig();
  if (!config.enabled) return null;

  try {
    const title = input.content.match(/^标题：(.+)$/m)?.[1]?.trim() || input.content.slice(0, 80) || "GetNote source";
    const stdout = await runNotebookLmScript(config.python, config.script, {
      title,
      content: input.content,
      context: input.context,
    }, config.timeoutMs);

    const parsed = parseDraft(cleanJsonOutput(stdout));
    return parsed ? { source: "notebooklm", model: "notebooklm-private-api", ...parsed } : null;
  } catch {
    return null;
  }
}

function runNotebookLmScript(
  python: string,
  script: string,
  payload: { title: string; content: string; context?: string },
  timeoutMs: number,
) {
  return new Promise<string>((resolve, reject) => {
    const child = spawn(python, [script], {
      env: process.env,
      stdio: ["pipe", "pipe", "pipe"],
    });
    const timer = setTimeout(() => {
      child.kill("SIGKILL");
      reject(new Error("NotebookLM private API timed out"));
    }, timeoutMs);
    const stdout: Buffer[] = [];
    const stderr: Buffer[] = [];

    child.stdout.on("data", (chunk: Buffer) => stdout.push(chunk));
    child.stderr.on("data", (chunk: Buffer) => stderr.push(chunk));
    child.on("error", (error) => {
      clearTimeout(timer);
      reject(error);
    });
    child.on("close", (code) => {
      clearTimeout(timer);
      if (code === 0) {
        resolve(Buffer.concat(stdout).toString("utf8"));
        return;
      }
      reject(new Error(Buffer.concat(stderr).toString("utf8") || `NotebookLM private API exited ${code}`));
    });

    child.stdin.end(
      JSON.stringify({
        title: payload.title,
        content: payload.content,
        context: payload.context,
      }),
    );
  });
}

function generateRuleDraft(input: GetNoteInput): GetNoteDraft {
  const content = input.content.trim();
  const extractedTitle = content.match(/^标题：(.+)$/m)?.[1]?.trim();
  const title = extractedTitle || (content.length > 34 ? `${content.slice(0, 34)}...` : content || modeLabels[input.mode]);
  const baseTags = ["getnote", "geo", modeLabels[input.mode]];
  const normalizedContext = input.context?.trim();

  if (input.mode === "recall") {
    return {
      source: "rules",
      model: getModelConfig().model,
      title: `检索：${title}`,
      summary: `围绕“${title}”发起语义召回，优先查找项目资料、知识库和近期保存的同类笔记。`,
      noteType: "semantic-recall",
      tags: cleanList([...baseTags, "semantic-recall"], 6),
      knowledgeBases: cleanList(["GEO 工作流", "客户资料", normalizedContext || "默认知识库"], 4),
      actions: ["预览检索条件", "执行 recall 搜索", "按知识库过滤", "把高置信结果合并成任务清单"],
      apiPreview: `node skills/getnote/scripts/getnote.mjs search --query "${escapePreview(title)}"`,
      recallQueries: cleanList([title, `${title} 证据`, `${title} 下一步`, normalizedContext || ""], 5),
      safetyChecks: ["不输出隐藏密钥", "先预览结果再写入", "保留来源链接和知识库范围"],
    };
  }

  const apiPreview =
    input.mode === "link"
      ? `node skills/getnote/scripts/getnote.mjs save-link --url "${escapePreview(content)}" --poll`
      : input.mode === "image"
        ? "node skills/getnote/scripts/getnote.mjs save-image --file ./image.png --title \"图片笔记\""
        : `node skills/getnote/scripts/getnote.mjs save-text --content "${escapePreview(title)}"`;

  return {
    source: "rules",
    model: getModelConfig().model,
    title,
      summary: summaryForMode(input.mode, content),
    noteType: input.mode === "link" ? "link-note" : input.mode === "image" ? "image-note" : "text-note",
    tags: cleanList(baseTags, 6),
    knowledgeBases: cleanList(["GEO 工作流", "客户资料", normalizedContext || "默认知识库"], 4),
    actions: actionsForMode(input.mode),
    apiPreview,
    recallQueries: cleanList([title, `${title} 相关资料`, `${title} 后续行动`], 5),
    safetyChecks: ["preview-first", "密钥只读本地 auth.json 或服务端 env", "输出中脱敏 API key 和签名上传参数"],
  };
}

function summaryForMode(mode: GetNoteMode, content: string) {
  if (mode === "link" && !content.includes("正文：")) return `## 来源\n${content}\n\n## 提取结果\n没有提取到足够正文，请复制页面正文后再生成。`;
  if (mode === "image" && !content.includes("正文：")) return "## 图片笔记\n已收到图片素材。当前版本会根据文件名和补充说明整理用途、来源和后续需要补齐的信息。";
  const platform = content.match(/^来源平台：(.+)$/m)?.[1]?.trim();
  const url = content.match(/^来源链接：(.+)$/m)?.[1]?.trim();
  const body = content.split("正文：").at(1)?.trim() || content;
  const hashtags = Array.from(new Set(body.match(/#[^\s#]+/g) || []));
  const stats = body.match(/点赞\s*\d+，收藏\s*\d+，评论\s*\d+/)?.[0];
  if (platform || url) {
    return [
      "## 内容笔记",
      body.slice(0, 2600),
      hashtags.length ? `\n## 关键词\n${hashtags.join(" ")}` : "",
      stats ? `\n## 数据\n${stats}` : "",
      url ? `\n## 来源\n${url}` : "",
    ]
      .filter(Boolean)
      .join("\n");
  }
  return content;
}

function actionsForMode(mode: GetNoteMode) {
  if (mode === "link") return ["校验 URL", "创建链接笔记任务", "轮询 task 状态", "解析完成后补标签和知识库"];
  if (mode === "image") return ["申请签名上传地址", "上传图片文件", "确认文件对象", "创建图片笔记并写入用途说明"];
  return ["核对重点", "补充需要追踪的来源或数据"];
}

function parseDraft(content: string): RawDraft | null {
  try {
    const parsed = JSON.parse(content) as Partial<RawDraft>;
    if (!parsed.title || !parsed.summary) return null;
    return {
      title: String(parsed.title).slice(0, 80),
    summary: String(parsed.summary).slice(0, 5000),
      noteType: String(parsed.noteType || "note").slice(0, 40),
      tags: cleanList(parsed.tags, 8),
      knowledgeBases: cleanList(parsed.knowledgeBases, 5),
      actions: cleanList(parsed.actions, 8),
      apiPreview: String(parsed.apiPreview || "node skills/getnote/scripts/getnote.mjs --help").slice(0, 260),
      recallQueries: cleanList(parsed.recallQueries, 6),
      safetyChecks: cleanList(parsed.safetyChecks, 6),
    };
  } catch {
    return null;
  }
}

function cleanJsonOutput(content: string) {
  const trimmed = content.trim();
  const fenced = trimmed.match(/```(?:json)?\s*([\s\S]*?)```/i)?.[1]?.trim();
  if (fenced) return fenced;
  const start = trimmed.indexOf("{");
  const end = trimmed.lastIndexOf("}");
  if (start >= 0 && end > start) return trimmed.slice(start, end + 1);
  return trimmed;
}

function cleanList(value: unknown, limit: number) {
  return Array.isArray(value)
    ? value
        .map((item) => String(item).trim())
        .filter(Boolean)
        .slice(0, limit)
    : [];
}

function escapePreview(value: string) {
  return value.replaceAll("\"", "'").slice(0, 160);
}
