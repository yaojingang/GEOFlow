# GEOFlow Agent Instructions

Laravel Boost support is installed for this repository.

Before making Laravel, PHP, Tailwind, Horizon, or AI SDK changes, read:

`../../.boost/guidelines.md`

The Boost MCP server is configured in:

`../../.mcp.json`

Tool-specific configuration files are kept at their default discovery paths in the repository root or hidden tool folders.

## “提交”工作流

本项目维护者明确发出“提交”“提交吧”等执行指令时，默认授权完成以下整个流程，无需逐步再次确认。讨论规则、引用示例、询问状态时出现这些词，不触发执行；用户当次限定的范围或步骤优先。

1. **确定范围。** 默认提交当前任务完成的改动，以及必要的测试和文档。先检查工作区、暂存区、分支、远端和相对 `origin/main` 的完整差异。保留其他任务的改动；按明确文件或代码片段暂存，避免混入无关文件、凭据、客户数据和临时产物。只有用户明确要求“全部提交”时才扩大到全部相关改动。
2. **检查与修复。** 使用 `check` 技能，审查本次提交与 PR 的完整范围，并运行与改动相符的检查。以 `.github/workflows/ci.yml` 为远端检查依据。自动修复本次范围内可明确解决的问题，再验证修复结果。存在并行工作时，在独立工作区验证本次改动。
3. **提交与推送。** 核实目标仓库为 `yaojingang/GEOFlow`，通过聚焦的 `codex/` 功能分支提交并推送。可以复用范围一致的已有分支；当前分支含有无关提交时，使用独立工作区准备本次 PR。每次提交和推送前复核 HEAD 与改动范围。保留共享工作区中的未完成工作，避免强制推送、清理、覆盖或隐藏其他任务的改动。
4. **创建 PR。** 创建或更新目标为 `main` 的 GitHub PR，复用本次分支已有的开放 PR。按仓库模板说明最终变化和实际验证结果，如实填写声明，不代填未经提供的身份或签署信息。
5. **检查通过后合并。** 等待 PR 最新提交的 CI 完成，确认所需检查成功、审查要求满足、冲突已解决，再自动合并。优先使用 squash merge；以实时仓库支持的合并方式为准。合并时核对已审查的 PR head SHA，避免合入未检查的新提交。仓库已启用 GitHub 自动合并时可使用该能力；否则等待检查完成后执行普通合并。遵守分支保护和合并队列要求。
6. **核实结果。** 回读 PR 已合并状态、合并提交和远端 `main`，拉取最新远端引用。仅在不影响其他工作区和未提交内容时快进本地 `main`。最终报告 PR 链接、合并提交、检查结果，以及本地同步是否完成。

可明确解决的测试失败和冲突应继续处理；遇到无法安全解决的冲突、权限限制、外部审查要求或必须由用户决定的问题，说明具体阻塞和已完成步骤。禁止绕过失败检查、使用管理员方式强行合并或把等待检查描述为已合并。

“提交”的完成目标是通过 PR 将改动合入远端 `main`。此指令的授权范围到代码合并为止；部署生产、发布版本、数据库操作和对外消息发送仍按用户另行指定的范围执行。

## Distribution Channel Deletion Safety

- Run channel-scoped remote calls and credential/package exports through `DistributionChannelOperationLeaseService` so final deletion can detect in-flight work.
- Lock the distribution channel row before channel writes, task-channel binding changes, queue claims, retries, or immediate distribution actions, and reject work while the channel status is `deleting`.
- Keep final deletion behind the two-step impact review. Recompute and verify the impact fingerprint inside the locked deletion transaction before removing local data.
