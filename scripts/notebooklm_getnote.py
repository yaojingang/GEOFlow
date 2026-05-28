#!/usr/bin/env python3
"""Generate a GetNote draft through NotebookLM private APIs.

Input: JSON on stdin with title/content/context.
Output: JSON with title/summary/tags/actions.
"""

from __future__ import annotations

import asyncio
import json
import os
import sys
from datetime import datetime, timezone

from notebooklm import NotebookLMClient


async def main() -> int:
    payload = json.load(sys.stdin)
    title = str(payload.get("title") or "GetNote source")[:120]
    content = str(payload.get("content") or "").strip()
    context = str(payload.get("context") or "").strip()
    if not content:
        raise ValueError("content is required")

    notebook_title = f"GetNote {datetime.now(timezone.utc).strftime('%Y%m%d-%H%M%S')} {title}"[:180]
    notebook_id = None
    source_id = None

    async with NotebookLMClient.from_storage(os.getenv("GETNOTE_NOTEBOOKLM_STORAGE") or None) as client:
        notebook = await client.notebooks.create(notebook_title)
        notebook_id = notebook.id

        source = await client.sources.add_text(notebook_id, title, content[:180000], wait=True, wait_timeout=120)
        source_id = source.id

        prompt = f"""
请把这个来源整理成一篇可直接保存的中文笔记。

要求：
1. 只返回 JSON，不要 Markdown 代码块。
2. JSON 格式必须是：{{"title":"...","summary":"...","noteType":"source-note","tags":["..."],"knowledgeBases":["文章笔记"],"actions":["..."],"apiPreview":"","recallQueries":["..."],"safetyChecks":[]}}
3. summary 是完整笔记正文，包含来源、核心摘要、结构化要点、可复用结论。
4. 如果来源信息不足，直接说明缺口和需要补充什么。
5. 不要输出 API、OpenAPI、命令行、密钥、预览这些技术说明。

上下文：{context or "文章转笔记"}
""".strip()

        answer = await client.chat.ask(notebook_id, prompt, source_ids=[source_id])
        print(answer.answer)

        if os.getenv("GETNOTE_NOTEBOOKLM_KEEP_NOTEBOOK", "").lower() not in {"1", "true", "yes"}:
            try:
                await client.notebooks.delete(notebook_id)
            except Exception:
                pass

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(asyncio.run(main()))
    except Exception as exc:
        print(json.dumps({"error": str(exc)}, ensure_ascii=False), file=sys.stderr)
        raise SystemExit(1)
