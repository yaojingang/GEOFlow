# Agent Configuration

This folder keeps human-readable agent instructions in one place so the repository root stays focused on application files.

## Files

- `AGENTS.md` - General agent instructions for GEOFlow.
- `CODEX.md` - Codex / GPT-oriented instructions.
- `CLAUDE.md` - Claude-oriented instructions.
- `GEMINI.md` - Gemini-oriented instructions.

## Required Root-Level Tooling

Some integration files intentionally remain at the repository root because the related tools discover them by convention. Do not move these into `docs/` unless the tool configuration is changed and verified:

- `.mcp.json` - shared MCP server configuration for Laravel Boost.
- `boost.json` - Laravel Boost install and skill configuration.
- `opencode.json` - OpenCode MCP configuration.
- `.codex/config.toml` - Codex MCP configuration.
- `.cursor/mcp.json` - Cursor MCP configuration.
- `.gemini/settings.json` - Gemini MCP configuration.

The full Laravel Boost guidance lives in:

`../../.boost/guidelines.md`
