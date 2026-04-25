# 仓库级 Skills（多工具共用）

- **正文目录**：本目录下的技能包（如 `laravel-best-practices/`）为唯一来源。
- **OpenAI Codex**：官方约定从仓库根目录的 `.agents/skills` 加载（见 Codex 文档 *Customization → Skills*）。
- **Cursor**：`.cursor/skills/laravel-best-practices` 为指向本目录的符号链接。
- **Claude Code**：`.claude/skills/laravel-best-practices` 为指向本目录的符号链接。
- **Junie**：`.junie/skills` 指向整个 `.agents/skills` 目录。

在 Windows 上若符号链接未生效，请将各工具配置为直接读取 `.agents/skills/` 下的路径。
