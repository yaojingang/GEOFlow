import { createGeneratedContent } from "@/lib/content-service";
import { generateReport, runDoubaoSampling } from "@/lib/doubao-service";
import { confirmGeoLesson, searchGeoLessons, writeGeoLesson, type GeoLessonRecord, type LessonOutcome } from "@/lib/geo-lesson-service";
import { createResearchNote, linkResearchNotes, searchResearchNotes } from "@/lib/research-service";
import { AgentToolRegistry } from "@/lib/agent-runtime/tool-registry";
import { text } from "@/lib/agent-runtime/types";

function numberArg(value: unknown, fallback: number) {
  if (typeof value !== "number" || Number.isNaN(value)) {
    return fallback;
  }

  return Math.max(1, Math.min(Math.round(value), 12));
}

function outcomeArg(value: unknown): LessonOutcome {
  if (value === "partial" || value === "did_not_work" || value === "worked") {
    return value;
  }
  return "worked";
}

function stringArg(value: unknown, fallback: string) {
  return typeof value === "string" && value.trim() ? value.trim() : fallback;
}

export function createWorkspaceToolRegistry() {
  const registry = new AgentToolRegistry();

  registry.register({
    name: "run_doubao_sampling",
    description: "运行豆包问题采样，写入监测记录，并返回新增样本数量。",
    parameters: {
      type: "object",
      properties: {
        limit: { type: "number", description: "采样问题数量，默认 5，最多 12。" },
      },
    },
    async execute(args) {
      const limit = numberArg(args.limit, 5);
      const samples = await runDoubaoSampling(limit);

      return {
        content: [text(`已完成 ${samples.length} 条豆包采样。`)],
        details: {
          count: samples.length,
          sources: samples.map((sample) => sample.source),
        },
      };
    },
  });

  registry.register({
    name: "generate_report",
    description: "基于当前资料、采样和配置生成一份豆包 GEO 诊断报告。",
    parameters: {
      type: "object",
      properties: {
        titlePrefix: { type: "string", description: "报告标题前缀。" },
      },
    },
    async execute(args) {
      const report = await generateReport(stringArg(args.titlePrefix, "geo.youngtuo.win 豆包 GEO 诊断报告"));

      return {
        content: [text(`已生成报告：《${report.title}》。`)],
        details: {
          id: report.id,
          title: report.title,
          publicSlug: report.publicSlug,
          publicUrl: report.publicSlug ? `/reports/${report.publicSlug}` : null,
        },
      };
    },
  });

  registry.register({
    name: "create_content_draft",
    description: "根据当前资料、品牌事实、问题集和采样缺口生成内容草稿。",
    parameters: {
      type: "object",
      properties: {
        type: { type: "string", description: "内容类型，例如 FAQ、对比页、品牌事实页、案例页、社媒短内容。" },
      },
    },
    async execute(args) {
      const content = await createGeneratedContent(stringArg(args.type, "FAQ"));

      return {
        content: [text(`已生成内容草稿：《${content.title}》。`)],
        details: {
          id: content.id,
          title: content.title,
          type: content.type,
          status: content.status,
        },
      };
    },
  });

  registry.register({
    name: "search_geo_lessons",
    description: "检索历史 GEO 经验，用于判断哪些优化动作之前有效或无效。",
    parameters: {
      type: "object",
      properties: {
        query: { type: "string", description: "检索关键词，例如豆包 FAQ、案例页、证据链、竞品对比。" },
      },
    },
    async execute(args) {
      const query = stringArg(args.query, "");
      const lessons = await searchGeoLessons(query, 5);

      return {
        content: [
          text(
            lessons.length
              ? `找到 ${lessons.length} 条 GEO 经验：${lessons.map((lesson: GeoLessonRecord) => `${lesson.title}(${lesson.verificationStatus})`).join("；")}`
              : "没有找到可复用的 GEO 经验。",
          ),
        ],
        details: { lessons },
      };
    },
  });

  registry.register({
    name: "write_geo_lesson",
    description: "在用户明确反馈某个 GEO 动作有效、部分有效或无效后，沉淀新的 GEO 经验。",
    parameters: {
      type: "object",
      properties: {
        title: { type: "string", description: "经验标题。" },
        tactic: { type: "string", description: "执行过的 GEO 动作。" },
        scenario: { type: "string", description: "适用场景。" },
        outcome: { type: "string", enum: ["worked", "partial", "did_not_work"], description: "用户确认的结果。" },
        evidenceUrl: { type: "string", description: "证据链接，例如公开报告 URL。" },
        reportId: { type: "string", description: "关联报告 ID。" },
        notes: { type: "string", description: "补充说明。" },
      },
      required: ["title", "tactic", "scenario", "outcome"],
    },
    async execute(args) {
      const lesson = await writeGeoLesson({
        title: stringArg(args.title, "GEO 经验"),
        tactic: stringArg(args.tactic, "补齐资料、问题集和内容证据后复测豆包答案"),
        scenario: stringArg(args.scenario, "豆包可见度优化"),
        outcome: outcomeArg(args.outcome),
        evidenceUrl: typeof args.evidenceUrl === "string" ? args.evidenceUrl : null,
        reportId: typeof args.reportId === "string" ? args.reportId : null,
        notes: typeof args.notes === "string" ? args.notes : null,
      });

      return {
        content: [text(`已沉淀 GEO 经验：《${lesson.title}》，状态 ${lesson.verificationStatus}。`)],
        details: { lesson },
      };
    },
  });

  registry.register({
    name: "confirm_geo_lesson",
    description: "用户对已有 GEO 经验给出新反馈时，更新该经验的验证结果。",
    parameters: {
      type: "object",
      properties: {
        id: { type: "string", description: "经验 ID。" },
        outcome: { type: "string", enum: ["worked", "partial", "did_not_work"], description: "新反馈结果。" },
        notes: { type: "string", description: "补充说明。" },
      },
      required: ["id", "outcome"],
    },
    async execute(args) {
      const lesson = await confirmGeoLesson(stringArg(args.id, ""), outcomeArg(args.outcome), typeof args.notes === "string" ? args.notes : null);

      return {
        content: [text(`已更新 GEO 经验：《${lesson.title}》，状态 ${lesson.verificationStatus}，置信度 ${lesson.confidence}。`)],
        details: { lesson },
      };
    },
  });

  registry.register({
    name: "search_research_notes",
    description: "检索已发布的豆包研究中心节点，用于回答研究结论、证据链和历史观察。",
    parameters: {
      type: "object",
      properties: {
        query: { type: "string", description: "检索关键词，例如豆包答案、证据链、Agent 会话、采样观察。" },
      },
    },
    async execute(args) {
      const query = stringArg(args.query, "");
      const notes = await searchResearchNotes(query, 6);

      return {
        content: [
          text(
            notes.length
              ? `找到 ${notes.length} 条研究节点：${notes.map((note) => `${note.title}(/doubao-research/${note.slug})`).join("；")}`
              : "没有找到已发布的研究节点。",
          ),
        ],
        details: {
          notes: notes.map((note) => ({
            id: note.id,
            title: note.title,
            slug: note.slug,
            excerpt: note.excerpt,
            type: note.type,
          })),
        },
      };
    },
  });

  registry.register({
    name: "write_research_note",
    description: "把明确、可公开的豆包研究结论写入豆包研究中心。不要写入原始私密对话。",
    parameters: {
      type: "object",
      properties: {
        title: { type: "string", description: "研究节点标题。" },
        excerpt: { type: "string", description: "公开摘要。" },
        body: { type: "string", description: "Markdown 正文，可包含 [[双链]]。" },
        type: { type: "string", description: "研究笔记、豆包机制、案例观察、证据卡或实验记录。" },
        tags: { type: "string", description: "逗号分隔标签。" },
        status: { type: "string", enum: ["draft", "published"], description: "默认 draft；明确要求公开时用 published。" },
      },
      required: ["title", "body"],
    },
    async execute(args) {
      const note = await createResearchNote({
        title: stringArg(args.title, "豆包研究节点"),
        excerpt: typeof args.excerpt === "string" ? args.excerpt : null,
        body: stringArg(args.body, "## 研究结论\n\n请补充可复核的豆包研究结论。"),
        type: typeof args.type === "string" ? args.type : "研究笔记",
        tags: typeof args.tags === "string" ? args.tags : "豆包,GEO",
        status: args.status === "published" ? "published" : "draft",
        sourceType: "agent",
      });

      return {
        content: [text(`已写入豆包研究中心：《${note.title}》，状态 ${note.status}。`)],
        details: {
          id: note.id,
          title: note.title,
          slug: note.slug,
          status: note.status,
          publicUrl: note.status === "published" ? `/doubao-research/${note.slug}` : null,
        },
      };
    },
  });

  registry.register({
    name: "link_research_notes",
    description: "为两个研究节点建立 Obsidian 式关联边，用于反向链接和轻量图谱。",
    parameters: {
      type: "object",
      properties: {
        fromNoteId: { type: "string", description: "起点研究节点 ID。" },
        toNoteId: { type: "string", description: "终点研究节点 ID。" },
        label: { type: "string", description: "关系标签。" },
        strength: { type: "number", description: "关系强度 1-100。" },
      },
      required: ["fromNoteId", "toNoteId"],
    },
    async execute(args) {
      const link = await linkResearchNotes({
        fromNoteId: stringArg(args.fromNoteId, ""),
        toNoteId: stringArg(args.toNoteId, ""),
        label: typeof args.label === "string" ? args.label : "相关",
        strength: typeof args.strength === "number" ? args.strength : 60,
      });

      return {
        content: [text(`已建立研究节点关联：${link.label}，强度 ${link.strength}。`)],
        details: link,
      };
    },
  });

  return registry;
}
