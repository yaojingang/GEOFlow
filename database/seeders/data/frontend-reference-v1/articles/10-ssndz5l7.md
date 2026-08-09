GEOFlow 的 API Base URL 应填写服务商提供的接口基础地址；兼容 OpenAI 的服务由系统补全具体路由，原生接口则按后台说明配置。

## 填写规则

接入 DeepSeek、智谱、火山方舟等 OpenAI 兼容接口时，需在 GEOFlow 中手动填写 API Base URL。原生接口（如 Gemini）按后台说明配置 API Key 与模型 ID，不适用此规则。

基础地址会自动补全 `/v1/chat/completions`（chat 模型）与 `/v1/embeddings`（embedding 模型）。包含版本路径的基础地址同样有效，例如智谱的 `/api/paas/v4` 或火山方舟的 `/api/v3`。若已填入完整接口路径，请以服务商文档为准，不再依赖补全规则。

## 常用基础地址

| 服务商 / 类型   | API Base URL 示例                                  |
|----------------|----------------------------------------------------|
| DeepSeek       | `https://api.deepseek.com`                         |
| 智谱           | `https://open.bigmodel.cn/api/paas/v4`             |
| 火山方舟       | `https://ark.cn-beijing.volces.com/api/v3`         |
| OpenAI 兼容代理 | `https://example.com/v1`                           |

服务商明确提供 OpenAI 兼容端点时，优先按兼容方式填入。

## 模型验证与排错

首次配置请先设置一个 chat 模型，验证标题和正文生成。须同时填写模型名称、模型 ID 和 API Key，模型类型选为 `chat`。启用知识库 RAG 时，需额外配置 embedding 模型，并确保其 API Base URL 与接口规则一致。

调用返回 404 时，确认 GEOFlow 版本为 v1.2.x 及以上，并检查基础地址是否误填了完整接口路径。Embedding 服务暂不可用时，系统会保留已创建的知识库和切片，向量写入失败不会丢弃资料，服务恢复后可重新触发向量化。

## 检查清单

1. 服务商提供 OpenAI 兼容接口，否则按原生接口说明配置。
2. 基础地址末尾不含 `/chat/completions` 等路由片段。
3. 版本化路径与官方文档一致。
4. 模型 ID 和 API Key 已正确填入。
5. 启用知识库前，embedding 模型的 API Base URL 已配置且可正常连接。
