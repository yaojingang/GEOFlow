#!/usr/bin/env -S node --experimental-strip-types
/**
 * Doubao Research X Signals
 *
 * Uses the local Hermes-derived xAI OAuth flow from yao-media-station:
 *   ~/.config/yao-x-insights/xai-oauth.json
 *
 * This does not use the official X API directly. It calls xAI Responses API
 * with the builtin x_search tool, then writes a research ledger for
 * /doubao-research. Community posts remain signals until sampled and reviewed.
 */
import { existsSync, mkdirSync, readFileSync, statSync, writeFileSync, chmodSync } from "node:fs";
import { homedir } from "node:os";
import { join, resolve } from "node:path";

const args = new Set(process.argv.slice(2));
const DRY_RUN = args.has("--dry-run") || args.has("-n");
const QUERY_ARG = [...args].find((arg) => arg.startsWith("--query="))?.slice("--query=".length);
const OUT_DIR = resolve((process.env.DOUBAO_RESEARCH_OUT ?? join(process.cwd(), "research-runs/doubao-x-signals")).replace(/^~/, homedir()));
const OAUTH_TOKEN_FILE = join(homedir(), ".config/yao-x-insights/xai-oauth.json");
const XAI_OAUTH_CLIENT_ID = "b1a00492-073a-47ea-816f-4c329264a828";
const XAI_OAUTH_TOKEN_URL = "https://auth.x.ai/oauth2/token";
const OAUTH_REFRESH_SKEW_SEC = 120;

type OAuthTokens = {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  token_type: string;
  scope: string;
  obtained_at?: number;
};

type SignalItem = {
  tweet_url: string;
  tweet_id: string;
  author: string;
  posted_at: string;
  likes: number;
  retweets: number;
  text: string;
  signal_type: "usage_pattern" | "source_grounding" | "content_workflow" | "risk_rule" | "collection_constraint" | "other";
  evidence_tier: "discussion_signal" | "needs_sampling";
  confidence: number;
  why_it_matters: string;
  doubao_sampling_question: string;
  publishable: false;
};

type ResponsesPayload = {
  output?: Array<{
    type?: string;
    content?: Array<{ type?: string; text?: string }>;
  }>;
  usage?: { num_sources_used?: number };
};

function readOAuthFile(): OAuthTokens | null {
  if (!existsSync(OAUTH_TOKEN_FILE)) return null;
  try {
    return JSON.parse(readFileSync(OAUTH_TOKEN_FILE, "utf-8")) as OAuthTokens;
  } catch {
    return null;
  }
}

async function refreshOAuthToken(refreshToken: string): Promise<OAuthTokens | null> {
  const body = new URLSearchParams({
    grant_type: "refresh_token",
    refresh_token: refreshToken,
    client_id: XAI_OAUTH_CLIENT_ID,
  });
  const res = await fetch(XAI_OAUTH_TOKEN_URL, {
    method: "POST",
    headers: { "content-type": "application/x-www-form-urlencoded" },
    body,
  });
  if (!res.ok) {
    console.error(`OAuth refresh failed: HTTP ${res.status} ${await res.text()}`);
    return null;
  }
  const next = (await res.json()) as OAuthTokens;
  next.obtained_at = Date.now();
  if (!next.refresh_token) next.refresh_token = refreshToken;
  writeFileSync(OAUTH_TOKEN_FILE, JSON.stringify(next, null, 2));
  chmodSync(OAUTH_TOKEN_FILE, 0o600);
  return next;
}

async function loadOAuthAccessToken(): Promise<string | null> {
  const tok = readOAuthFile();
  if (!tok) return null;

  const obtainedMs = tok.obtained_at ?? statSync(OAUTH_TOKEN_FILE).mtimeMs;
  const expiresAtMs = obtainedMs + (tok.expires_in ?? 0) * 1000;
  if (Date.now() < expiresAtMs - OAUTH_REFRESH_SKEW_SEC * 1000) return tok.access_token;

  const next = await refreshOAuthToken(tok.refresh_token);
  return next?.access_token ?? null;
}

function buildPrompt(query: string) {
  return [
    `Research target: Doubao / 豆包, AI search, link summarization, source citation, search-generated answers.`,
    `Query focus: ${query}`,
    ``,
    `Use the x_search tool to find recent and relevant X posts in Chinese or English.`,
    `Prioritize firsthand user complaints, workflow screenshots, comparison posts, prompt examples, and product behavior observations.`,
    `Do not treat social posts as facts. Treat them as discussion signals or sampling hypotheses.`,
    ``,
    `Return one JSON object only, no markdown fence, with this exact shape:`,
    `{ "items": [`,
    `  {`,
    `    "tweet_url": "https://x.com/<author>/status/<id>",`,
    `    "tweet_id": "<id>",`,
    `    "author": "@username",`,
    `    "posted_at": "<ISO date or empty string>",`,
    `    "likes": 0,`,
    `    "retweets": 0,`,
    `    "text": "<short exact post text excerpt>",`,
    `    "signal_type": "usage_pattern|source_grounding|content_workflow|risk_rule|collection_constraint|other",`,
    `    "evidence_tier": "discussion_signal|needs_sampling",`,
    `    "confidence": 0.0,`,
    `    "why_it_matters": "<Chinese sentence for Doubao research>",`,
    `    "doubao_sampling_question": "<Chinese question to test in Doubao>",`,
    `    "publishable": false`,
    `  }`,
    `] }`,
    ``,
    `Return 5 to 12 items. Keep publishable false for every item.`,
  ].join("\n");
}

function extractFinalText(resp: ResponsesPayload): string | null {
  if (!resp.output) return null;
  for (let i = resp.output.length - 1; i >= 0; i--) {
    const text = resp.output[i]?.content?.find((content) => content.type === "output_text")?.text;
    if (text) return text;
  }
  return null;
}

async function callResponsesAPI(accessToken: string, prompt: string): Promise<ResponsesPayload> {
  const model = process.env.XAI_MODEL?.trim() || "grok-4.3";
  let res = await fetch("https://api.x.ai/v1/responses", {
    method: "POST",
    headers: { "content-type": "application/json", authorization: `Bearer ${accessToken}` },
    body: JSON.stringify({
      model,
      input: [{ role: "user", content: prompt }],
      tools: [{ type: "x_search" }],
    }),
  });

  if (res.status === 401) {
    const tok = readOAuthFile();
    const next = tok?.refresh_token ? await refreshOAuthToken(tok.refresh_token) : null;
    if (next?.access_token) {
      res = await fetch("https://api.x.ai/v1/responses", {
        method: "POST",
        headers: { "content-type": "application/json", authorization: `Bearer ${next.access_token}` },
        body: JSON.stringify({
          model,
          input: [{ role: "user", content: prompt }],
          tools: [{ type: "x_search" }],
        }),
      });
    }
  }

  if (!res.ok) {
    throw new Error(`/v1/responses ${res.status}: ${(await res.text()).slice(0, 500)}`);
  }
  return (await res.json()) as ResponsesPayload;
}

function parseItems(text: string): SignalItem[] {
  const cleaned = text.replace(/^```(?:json)?\s*|\s*```$/g, "").trim();
  const parsed = JSON.parse(cleaned) as { items?: SignalItem[] };
  return (parsed.items ?? []).map((item) => ({
    ...item,
    evidence_tier: item.evidence_tier === "discussion_signal" ? "discussion_signal" : "needs_sampling",
    publishable: false,
  }));
}

function renderMarkdown(query: string, items: SignalItem[], sourcesUsed?: number) {
  const date = new Date().toISOString();
  const lines = [
    `# 豆包 X 社区信号抓取运行记录`,
    ``,
    `- generatedAt: ${date}`,
    `- query: ${query}`,
    `- method: Hermes-derived xAI OAuth + Responses API x_search`,
    `- sourcesUsed: ${sourcesUsed ?? "unknown"}`,
    `- publishPolicy: all X posts are discussion signals; publishable=false until Doubao sampling and review`,
    ``,
    `## Signals`,
    ``,
    `| URL | Author | Tier | Type | Confidence | Why it matters | Doubao sampling question |`,
    `|---|---|---|---|---:|---|---|`,
    ...items.map((item) =>
      [
        item.tweet_url,
        item.author,
        item.evidence_tier,
        item.signal_type,
        String(item.confidence ?? 0),
        item.why_it_matters.replaceAll("|", "/"),
        item.doubao_sampling_question.replaceAll("|", "/"),
      ].join(" | "),
    ),
    ``,
    `## Source Excerpts`,
    ``,
    ...items.flatMap((item, index) => [
      `### ${index + 1}. ${item.author} · ${item.likes ?? 0} likes`,
      ``,
      `- URL: ${item.tweet_url}`,
      `- postedAt: ${item.posted_at || "unknown"}`,
      `- publishable: false`,
      ``,
      `> ${String(item.text ?? "").split("\n").join("\n> ")}`,
      ``,
    ]),
  ];
  return lines.join("\n");
}

async function main() {
  const query = QUERY_ARG || "豆包 AI 搜索 OR 豆包 链接 总结 OR 豆包 来源 引用 OR Doubao AI search";
  if (!existsSync(OUT_DIR)) mkdirSync(OUT_DIR, { recursive: true });

  if (DRY_RUN) {
    const mock: SignalItem[] = [
      {
        tweet_url: "https://x.com/example/status/0",
        tweet_id: "0",
        author: "@example",
        posted_at: new Date().toISOString(),
        likes: 0,
        retweets: 0,
        text: "[MOCK] 豆包链接总结是否保留来源，需要实测。",
        signal_type: "source_grounding",
        evidence_tier: "needs_sampling",
        confidence: 0.6,
        why_it_matters: "这是来源引用稳定性的采样入口。",
        doubao_sampling_question: "豆包总结 X 链接时会不会给可复核来源？",
        publishable: false,
      },
    ];
    const outFile = join(OUT_DIR, `${new Date().toISOString().slice(0, 10)}-x-signals.md`);
    writeFileSync(outFile, renderMarkdown(query, mock, 0), "utf-8");
    console.log(outFile);
    return;
  }

  const token = await loadOAuthAccessToken();
  if (!token) throw new Error(`Missing xAI OAuth token. Run /Users/qiuxuanmai/dev/yao-media-station/scripts/x-insights/probe-xai-oauth.py first.`);

  const resp = await callResponsesAPI(token, buildPrompt(query));
  const finalText = extractFinalText(resp);
  if (!finalText) throw new Error("No output_text returned from xAI Responses API.");

  const items = parseItems(finalText);
  const outFile = join(OUT_DIR, `${new Date().toISOString().slice(0, 10)}-x-signals.md`);
  writeFileSync(outFile, renderMarkdown(query, items, resp.usage?.num_sources_used), "utf-8");
  console.log(outFile);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
