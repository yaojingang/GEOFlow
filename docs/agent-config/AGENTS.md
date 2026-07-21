# GEOFlow Agent Instructions

Laravel Boost support is installed for this repository.

Before making Laravel, PHP, Tailwind, Horizon, or AI SDK changes, read:

`../../.boost/guidelines.md`

The Boost MCP server is configured in:

`../../.mcp.json`

Tool-specific configuration files are kept at their default discovery paths in the repository root or hidden tool folders.

## Distribution Channel Deletion Safety

- Run channel-scoped remote calls and credential/package exports through `DistributionChannelOperationLeaseService` so final deletion can detect in-flight work.
- Lock the distribution channel row before channel writes, task-channel binding changes, queue claims, retries, or immediate distribution actions, and reject work while the channel status is `deleting`.
- Keep final deletion behind the two-step impact review. Recompute and verify the impact fingerprint inside the locked deletion transaction before removing local data.
