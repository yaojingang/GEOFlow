"use client";

import { Bot, Copy, Globe2, KeyRound, Save, ShieldCheck, Trash2 } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Badge } from "@/components/Badge";

type AnalyticsItem = {
  provider: string;
  status: string;
  propertyId?: string | null;
  guide: string;
};

type SocialItem = {
  platform: string;
  handle?: string | null;
  url?: string | null;
  isVisible: boolean;
};

type AgentSettings = {
  mode: "Explain" | "Assist" | "Control";
  canRunDoubaoSampling: boolean;
  canGenerateReports: boolean;
  canCreateContent: boolean;
  canEditContent: boolean;
  canPublish: boolean;
  canManageSources: boolean;
  canManageResearch: boolean;
  canModifySettings: boolean;
  requireConfirmation: boolean;
};

type ApiTokenItem = {
  id: string;
  name: string;
  tokenPrefix: string;
  scopes: string[];
  lastUsedAt?: string | null;
  expiresAt?: string | null;
  revokedAt?: string | null;
  createdAt: string;
};

type WorkspaceState = {
  workspace: {
    name: string;
    domain?: string | null;
    industry: string;
    market: string;
  };
  analyticsConfigs: AnalyticsItem[];
  socialAccounts: SocialItem[];
  agentSettings: AgentSettings | null;
  apiTokens: ApiTokenItem[];
  runtimeConfig?: {
    doubao: {
      configured: boolean;
      baseUrl: string;
      model: string;
    };
    planner: {
      configured: boolean;
      dedicatedKey: boolean;
      baseUrl: string;
      model: string;
    };
  };
};

const defaultAgent: AgentSettings = {
  mode: "Explain",
  canRunDoubaoSampling: false,
  canGenerateReports: false,
  canCreateContent: false,
  canEditContent: false,
  canPublish: false,
  canManageSources: false,
  canManageResearch: false,
  canModifySettings: false,
  requireConfirmation: true,
};

const permissionLabels: Array<[keyof AgentSettings, string]> = [
  ["canRunDoubaoSampling", "允许运行豆包采样"],
  ["canGenerateReports", "允许生成报告"],
  ["canCreateContent", "允许创建内容"],
  ["canEditContent", "允许编辑内容"],
  ["canPublish", "允许发布到客户展示端"],
  ["canManageSources", "允许管理资料库"],
  ["canManageResearch", "允许管理豆包研究中心"],
  ["canModifySettings", "允许修改项目设置"],
  ["requireConfirmation", "危险操作需要二次确认"],
];

const analyticsSetup: Record<
  string,
  {
    field: string;
    placeholder: string;
    steps: string[];
    result: string;
  }
> = {
  GA4: {
    field: "Measurement ID",
    placeholder: "例如 G-XXXXXXXXXX",
    steps: ["Google Analytics 新建 Web 数据流", "把域名填为 geo.youngtuo.win", "复制 Measurement ID 到这里"],
    result: "得到访问来源、页面事件和线索归因。",
  },
  "Search Console": {
    field: "域名资源或站点 URL",
    placeholder: "geo.youngtuo.win 或 https://geo.youngtuo.win",
    steps: ["新建 Domain property", "按 DNS TXT 完成所有权验证", "提交 /sitemap.xml 并等待收录"],
    result: "得到搜索收录、关键词展示和站点健康数据。",
  },
  百度统计: {
    field: "站点 ID / 统计代码标识",
    placeholder: "填写百度统计站点 ID",
    steps: ["百度统计新建站点", "把 geo.youngtuo.win 加入站点", "复制站点 ID 或统计代码标识"],
    result: "得到国内访问趋势和客户更熟悉的统计视角。",
  },
  "Doubao GEO Monitor": {
    field: "运行状态",
    placeholder: "内置监测，无需填写",
    steps: ["确认豆包 Ark Key 已接入", "按问题集运行采样", "生成客户诊断报告"],
    result: "得到豆包提及率、竞品命中和错误事实记录。",
  },
};

const socialSetup: Record<
  string,
  {
    handlePlaceholder: string;
    urlPlaceholder: string;
    result: string;
  }
> = {
  微信公众号: {
    handlePlaceholder: "公众号名称",
    urlPlaceholder: "公众号文章、二维码或介绍页链接",
    result: "客户报告里能展示微信入口，方便承接咨询。",
  },
  小红书: {
    handlePlaceholder: "小红书号 / 昵称",
    urlPlaceholder: "小红书主页链接",
    result: "用于沉淀案例、口碑和生活化搜索证据。",
  },
  抖音: {
    handlePlaceholder: "抖音号 / 昵称",
    urlPlaceholder: "抖音主页链接",
    result: "用于短视频内容分发和品牌可信度补强。",
  },
  视频号: {
    handlePlaceholder: "视频号名称",
    urlPlaceholder: "视频号主页、二维码或介绍页链接",
    result: "适合微信生态里的客户转化和复访。",
  },
  LinkedIn: {
    handlePlaceholder: "Company page / handle",
    urlPlaceholder: "LinkedIn 公司主页链接",
    result: "适合外贸、B2B 和英文语境的可信背书。",
  },
};

function statusLabel(status: string) {
  if (status === "active") return "已启用";
  if (status === "configured") return "已填写";
  return "待配置";
}

function analyticsGuide(item: AnalyticsItem) {
  return (
    analyticsSetup[item.provider] ?? {
      field: "配置值",
      placeholder: "填写平台要求的 ID 或链接",
      steps: [item.guide, "保存到项目数据库", "重新生成报告查看状态"],
      result: "得到可追踪、可复核的渠道配置。",
    }
  );
}

function socialGuide(platform: string) {
  return (
    socialSetup[platform] ?? {
      handlePlaceholder: "账号名 / handle",
      urlPlaceholder: "主页链接 / 二维码链接",
      result: "客户报告里能展示这个触点。",
    }
  );
}

export function SettingsClient() {
  const [state, setState] = useState<WorkspaceState | null>(null);
  const [status, setStatus] = useState("读取配置中...");
  const [adminKey, setAdminKey] = useState(() => {
    if (typeof window === "undefined") {
      return "";
    }

    return window.localStorage.getItem("geo-admin-key") ?? "";
  });
  const [tokenName, setTokenName] = useState("外部 API Token");
  const [tokenScopes, setTokenScopes] = useState<string[]>(["read"]);
  const [newToken, setNewToken] = useState("");

  const refresh = useCallback(async () => {
    const response = await fetch("/api/workspace/state", { cache: "no-store" });
    const data = (await response.json()) as WorkspaceState;
    setState({
      ...data,
      agentSettings: data.agentSettings ?? defaultAgent,
    });
    setStatus("配置已读取");
  }, []);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void refresh();
    }, 0);
    return () => window.clearTimeout(timer);
  }, [refresh]);

  function updateAnalytics(index: number, propertyId: string) {
    setState((current) => {
      if (!current) return current;
      const analyticsConfigs = [...current.analyticsConfigs];
      analyticsConfigs[index] = {
        ...analyticsConfigs[index],
        propertyId,
        status: propertyId.trim() ? "configured" : "missing",
      };
      return { ...current, analyticsConfigs };
    });
  }

  function updateSocial(index: number, patch: Partial<SocialItem>) {
    setState((current) => {
      if (!current) return current;
      const socialAccounts = [...current.socialAccounts];
      socialAccounts[index] = { ...socialAccounts[index], ...patch };
      return { ...current, socialAccounts };
    });
  }

  function updateAgent(patch: Partial<AgentSettings>) {
    setState((current) => {
      if (!current) return current;
      return { ...current, agentSettings: { ...(current.agentSettings ?? defaultAgent), ...patch } };
    });
  }

  async function save() {
    if (!state) return;
    setStatus("保存中...");
    window.localStorage.setItem("geo-admin-key", adminKey);
    const response = await fetch("/api/workspace/settings", {
      method: "PATCH",
      headers: { "Content-Type": "application/json", "x-geo-admin-key": adminKey },
      body: JSON.stringify({
        analytics: state.analyticsConfigs,
        socials: state.socialAccounts,
        agent: state.agentSettings,
      }),
    });

    if (!response.ok) {
      const error = (await response.json()) as { guide?: string; error?: string };
      setStatus(error.guide ?? error.error ?? "保存失败，请检查配置内容");
      return;
    }

    const data = (await response.json()) as WorkspaceState;
    setState({
      ...data,
      agentSettings: data.agentSettings ?? defaultAgent,
    });
    setStatus("已保存到项目数据库");
  }

  function toggleScope(scope: string) {
    setTokenScopes((current) => {
      if (current.includes(scope)) {
        const next = current.filter((item) => item !== scope);
        return next.length > 0 ? next : ["read"];
      }
      return [...current, scope];
    });
  }

  async function createApiToken() {
    setStatus("生成 API Token 中...");
    const response = await fetch("/api/workspace/api-tokens", {
      method: "POST",
      headers: { "Content-Type": "application/json", "x-geo-admin-key": adminKey },
      body: JSON.stringify({ name: tokenName, scopes: tokenScopes }),
    });
    const data = (await response.json()) as { token?: string; state?: WorkspaceState; guide?: string; error?: string };
    if (!response.ok || !data.state || !data.token) {
      setStatus(data.guide ?? data.error ?? "生成 Token 失败");
      return;
    }
    setState({ ...data.state, agentSettings: data.state.agentSettings ?? defaultAgent });
    setNewToken(data.token);
    setStatus("API Token 已生成，只显示这一次");
  }

  async function revokeApiToken(id: string) {
    setStatus("撤销 API Token 中...");
    const response = await fetch(`/api/workspace/api-tokens?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      headers: { "x-geo-admin-key": adminKey },
    });
    const data = (await response.json()) as WorkspaceState & { guide?: string; error?: string };
    if (!response.ok) {
      setStatus(data.guide ?? data.error ?? "撤销失败");
      return;
    }
    setState({ ...data, agentSettings: data.agentSettings ?? defaultAgent });
    setStatus("API Token 已撤销");
  }

  if (!state) {
    return <p className="mt-6 rounded-lg bg-white p-5 text-sm text-ink/60 shadow-soft ring-1 ring-line">{status}</p>;
  }

  const agent = state.agentSettings ?? defaultAgent;

  return (
    <section className="mt-6 grid gap-4">
      <div className="flex flex-col justify-between gap-3 rounded-lg bg-white p-5 shadow-soft ring-1 ring-line md:flex-row md:items-center">
        <div>
          <Badge tone={agent.mode === "Control" ? "doubao" : "dark"}>
            {agent.mode === "Control" ? "控制模式已开启" : "控制权限关闭"}
          </Badge>
          <p className="mt-3 text-sm text-ink/60">{status}</p>
        </div>
        <label className="grid gap-1 text-sm text-ink/65 md:min-w-72">
          <span className="text-xs text-ink/45">管理员控制 Key</span>
          <input
            value={adminKey}
            onChange={(event) => setAdminKey(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="输入后保存在当前浏览器"
            type="password"
          />
        </label>
        <button
          type="button"
          onClick={() => void save()}
          className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
        >
          <Save className="size-4" />
          保存配置
        </button>
      </div>

      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
          <div className="flex items-start gap-3">
            <Globe2 className="mt-1 size-5 text-doubao" />
            <div>
              <h2 className="text-xl font-semibold">域名与客户展示端</h2>
              <p className="mt-2 text-sm leading-6 text-ink/58">客户只需要使用同一个公开入口，资料、报告和发布页都围绕这个域名检查。</p>
            </div>
          </div>
          <Badge tone="doubao">已接入</Badge>
        </div>
        <div className="mt-4 grid gap-3 md:grid-cols-3">
          {[
            ["公开域名", state.workspace.domain ?? "geo.youngtuo.win", "客户打开后能看到展示端和工作台入口。"],
            ["站点地图", "https://geo.youngtuo.win/sitemap.xml", "提交到 Search Console / Bing 后等待收录。"],
            ["报告入口", "https://geo.youngtuo.win/reports/[slug]", "生成报告后拿到可转发客户链接。"],
          ].map(([title, value, body]) => (
            <div key={title} className="rounded-md bg-panel p-4 ring-1 ring-line">
              <p className="text-sm font-medium">{title}</p>
              <p className="mt-2 break-all text-sm text-doubao">{value}</p>
              <p className="mt-2 text-xs leading-5 text-ink/55">{body}</p>
            </div>
          ))}
        </div>
      </article>

      <div className="grid gap-4 xl:grid-cols-2">
        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">分析工具配置</h2>
          <div className="mt-4 grid gap-3">
            {state.analyticsConfigs.map((item, index) => {
              const guide = analyticsGuide(item);
              return (
                <div key={item.provider} className="grid gap-3 rounded-md bg-panel p-4 ring-1 ring-line">
                  <div className="flex items-center justify-between gap-3">
                    <p className="font-medium">{item.provider}</p>
                    <span className="rounded-full bg-white px-2 py-1 text-xs font-semibold text-doubao ring-1 ring-line">
                      {statusLabel(item.status)}
                    </span>
                  </div>
                  <label className="grid gap-2">
                    <span className="text-xs text-ink/45">{guide.field}</span>
                    <input
                      value={item.propertyId ?? ""}
                      onChange={(event) => updateAnalytics(index, event.target.value)}
                      className="rounded-md border-0 bg-white px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
                      placeholder={guide.placeholder}
                      disabled={item.provider === "Doubao GEO Monitor"}
                    />
                  </label>
                  <div className="grid gap-2 text-xs leading-5 text-ink/58">
                    {guide.steps.map((step, stepIndex) => (
                      <p key={step} className="flex gap-2">
                        <span className="font-semibold text-doubao">{stepIndex + 1}</span>
                        <span>{step}</span>
                      </p>
                    ))}
                  </div>
                  <p className="rounded-md bg-white p-3 text-xs leading-5 text-ink/60 ring-1 ring-line">完成后：{guide.result}</p>
                </div>
              );
            })}
          </div>
        </article>

        <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
          <h2 className="text-xl font-semibold">社交账号配置</h2>
          <div className="mt-4 grid gap-3">
            {state.socialAccounts.map((item, index) => {
              const guide = socialGuide(item.platform);
              const ready = Boolean((item.handle || item.url) && item.isVisible);
              return (
              <div key={item.platform} className="grid gap-2 rounded-md bg-panel p-4 ring-1 ring-line">
                <div className="flex items-center justify-between gap-3">
                  <p className="font-medium">{item.platform}</p>
                  <span className="rounded-full bg-white px-2 py-1 text-xs font-semibold text-doubao ring-1 ring-line">
                    {ready ? "客户可见" : "待补齐"}
                  </span>
                </div>
                <input
                  value={item.handle ?? ""}
                  onChange={(event) => updateSocial(index, { handle: event.target.value })}
                  className="rounded-md border-0 bg-white px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
                  placeholder={guide.handlePlaceholder}
                />
                <input
                  value={item.url ?? ""}
                  onChange={(event) => updateSocial(index, { url: event.target.value })}
                  className="rounded-md border-0 bg-white px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
                  placeholder={guide.urlPlaceholder}
                />
                <label className="flex items-center gap-2 text-sm text-ink/65">
                  <input
                    type="checkbox"
                    checked={item.isVisible}
                    onChange={(event) => updateSocial(index, { isVisible: event.target.checked })}
                    className="size-4 accent-doubao"
                  />
                  展示给客户
                </label>
                <p className="rounded-md bg-white p-3 text-xs leading-5 text-ink/60 ring-1 ring-line">完成后：{guide.result}</p>
              </div>
              );
            })}
          </div>
        </article>
      </div>

      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center gap-3">
          <ShieldCheck className="size-5 text-doubao" />
          <h2 className="text-xl font-semibold">Agent 项目控制权限</h2>
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          {(["Explain", "Assist", "Control"] as const).map((mode) => (
            <button
              key={mode}
              type="button"
              onClick={() => updateAgent({ mode })}
              className={`rounded-md px-3 py-2 text-sm font-medium ring-1 transition ${
                agent.mode === mode ? "bg-doubao text-paper shadow-doubao ring-doubao" : "bg-panel text-ink/65 ring-line hover:text-ink"
              }`}
            >
              {mode === "Explain" ? "讲解" : mode === "Assist" ? "协助" : "控制"}
            </button>
          ))}
        </div>
        <div className="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
          {permissionLabels.map(([key, label]) => (
            <label key={key} className="flex items-center gap-3 rounded-md bg-panel p-3 text-sm text-ink/70 ring-1 ring-line">
              <input
                type="checkbox"
                checked={Boolean(agent[key])}
                onChange={(event) => updateAgent({ [key]: event.target.checked } as Partial<AgentSettings>)}
                className="size-4 accent-doubao"
              />
              {label}
            </label>
          ))}
        </div>
      </article>

      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex flex-col justify-between gap-3 md:flex-row md:items-start">
          <div className="flex items-center gap-3">
            <Bot className="size-5 text-doubao" />
            <h2 className="text-xl font-semibold">模型与 Planner 状态</h2>
          </div>
          <Badge tone={state.runtimeConfig?.planner.configured ? "doubao" : "dark"}>
            {state.runtimeConfig?.planner.configured ? "Planner 已接入" : "Planner 未接入"}
          </Badge>
        </div>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          <div className="rounded-md bg-panel p-4 ring-1 ring-line">
            <div className="flex items-center justify-between gap-3">
              <p className="font-medium">豆包采样</p>
              <span className="text-xs font-semibold text-doubao">{state.runtimeConfig?.doubao.configured ? "已配置" : "待配置"}</span>
            </div>
            <p className="mt-3 break-all text-xs leading-5 text-ink/55">{state.runtimeConfig?.doubao.model ?? "doubao-seed-2-0-pro-260215"}</p>
            <p className="mt-2 break-all text-xs leading-5 text-ink/45">{state.runtimeConfig?.doubao.baseUrl ?? "https://ark.cn-beijing.volces.com/api/v3"}</p>
          </div>
          <div className="rounded-md bg-panel p-4 ring-1 ring-line">
            <div className="flex items-center justify-between gap-3">
              <p className="font-medium">Agent Planner</p>
              <span className="text-xs font-semibold text-doubao">
                {state.runtimeConfig?.planner.dedicatedKey ? "专用 Key" : state.runtimeConfig?.planner.configured ? "复用豆包 Key" : "待配置"}
              </span>
            </div>
            <p className="mt-3 break-all text-xs leading-5 text-ink/55">{state.runtimeConfig?.planner.model ?? "gpt-5.4-mini"}</p>
            <p className="mt-2 break-all text-xs leading-5 text-ink/45">{state.runtimeConfig?.planner.baseUrl ?? "https://api.yundongyl.cn/v1"}</p>
          </div>
        </div>
      </article>

      <article className="rounded-lg bg-white p-5 shadow-soft ring-1 ring-line">
        <div className="flex items-center gap-3">
          <KeyRound className="size-5 text-doubao" />
          <h2 className="text-xl font-semibold">Workspace API Token</h2>
        </div>
        <div className="mt-4 grid gap-3 lg:grid-cols-[280px_1fr_auto]">
          <input
            value={tokenName}
            onChange={(event) => setTokenName(event.target.value)}
            className="rounded-md border-0 bg-panel px-3 py-2 text-sm outline-none ring-1 ring-line focus:ring-doubao"
            placeholder="Token 名称"
          />
          <div className="flex flex-wrap gap-2 rounded-md bg-panel p-2 ring-1 ring-line">
            {["read", "source:write", "source:process", "monitor:run", "content:write", "report:write", "getnote:generate", "publish:write"].map((scope) => (
              <button
                key={scope}
                type="button"
                onClick={() => toggleScope(scope)}
                className={`rounded-md px-2 py-1 text-xs font-medium transition ${
                  tokenScopes.includes(scope) ? "bg-doubao text-paper" : "bg-white text-ink/60 ring-1 ring-line"
                }`}
              >
                {scope}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={() => void createApiToken()}
            className="inline-flex items-center justify-center gap-2 rounded-md bg-doubao px-4 py-2 text-sm font-semibold text-paper shadow-doubao transition hover:-translate-y-0.5 hover:bg-ink"
          >
            <KeyRound className="size-4" />
            生成
          </button>
        </div>
        {newToken ? (
          <div className="mt-4 rounded-md bg-panel p-4 ring-1 ring-line">
            <p className="text-xs text-ink/45">Token 只显示一次</p>
            <div className="mt-2 flex flex-col gap-2 md:flex-row md:items-center">
              <code className="min-w-0 flex-1 overflow-x-auto rounded-md bg-white px-3 py-2 text-sm text-ink/75 ring-1 ring-line">
                {newToken}
              </code>
              <button
                type="button"
                onClick={() => void navigator.clipboard.writeText(newToken)}
                className="inline-flex items-center justify-center gap-2 rounded-md bg-white px-3 py-2 text-sm font-semibold text-ink/70 ring-1 ring-line transition hover:text-doubao"
              >
                <Copy className="size-4" />
                复制
              </button>
            </div>
          </div>
        ) : null}
        <div className="mt-4 grid gap-3">
          {state.apiTokens?.length ? (
            state.apiTokens.map((token) => (
              <div key={token.id} className="flex flex-col justify-between gap-3 rounded-md bg-panel p-4 ring-1 ring-line md:flex-row md:items-center">
                <div>
                  <p className="font-medium">{token.name}</p>
                  <p className="mt-1 text-xs text-ink/45">
                    {token.tokenPrefix}... · {Array.isArray(token.scopes) ? token.scopes.join(" / ") : "read"}
                    {token.revokedAt ? " · 已撤销" : ""}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => void revokeApiToken(token.id)}
                  disabled={Boolean(token.revokedAt)}
                  className="inline-flex items-center justify-center gap-2 rounded-md bg-white px-3 py-2 text-sm font-semibold text-ink/60 ring-1 ring-line transition hover:text-doubao disabled:cursor-not-allowed disabled:opacity-40"
                >
                  <Trash2 className="size-4" />
                  撤销
                </button>
              </div>
            ))
          ) : (
            <p className="rounded-md bg-panel p-4 text-sm text-ink/55 ring-1 ring-line">还没有外部 API Token。</p>
          )}
        </div>
      </article>
    </section>
  );
}
