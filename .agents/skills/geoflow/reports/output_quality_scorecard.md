# GEOFlow Output Quality Scorecard

## Deterministic Cases

| Case | Required behavior | Result |
|---|---|---|
| Workspace discovery | Emit structured capability evidence without booting the application | pass |
| Homepage design validation | Accept the bundled reviewed payload and report module/style coverage | pass |
| Channel capability gap | Surface missing `lead_form`, require review, and recommend package upgrade or confirmed fallback | pass |
| Theme edit lifecycle | Create an isolated preview and publish it under a new theme ID | pass |
| Theme transaction failure | Restore the live theme after an injected commit failure and block concurrent finalizers | pass |
| Preflight transport | Reject unsafe URL schemes and non-2xx JSON responses before capability use | pass |
| Preflight redaction | Redact sensitive JSON keys before printing non-success response excerpts | pass |
| Signed channel read | Require in-process HTTPS/loopback endpoint validation and disabled redirects before live access | pass |
| Static preview boundary | Serve only six bundled files, disable directory listing, and render metadata through `textContent` | pass |
| Clean Skill upgrade | Stage the exact artifact contract, remove stale files through directory replacement, and retain current plus retired Skills in persistent backup | pass |
| Contract drift | Keep `lead_form`, trigger samples, runtime targets, network inventory, and expected package files synchronized | pass |

Deterministic pass rate: `11 / 11`.

## Boundary Checks

- Unsafe live actions remain explicit confirmation points.
- Channel module support comes from target-package capability evidence.
- Live channel reads fail closed on application versions missing request-time endpoint and redirect safeguards.
- Secrets and personal lead data stay outside report output.
- Non-success preflight response bodies pass through key-level or text fallback redaction before excerpts reach terminal output.
- Bundled previews expose a fixed allowlist from a dedicated directory and do not load external metadata.
- Global installation copies only declared artifacts and keeps recoverable timestamped backups of superseded Skill directories.
- Theme finalization validates complete staged trees and retains a workspace-wide lock through commit or rollback.
- Legacy templates require current Laravel contract validation.

## Evidence Status

- Deterministic script evidence: available (`30` regression tests)
- Trigger evidence: available
- Provider-backed output comparison: `missing evidence`
- Human blind review: `missing evidence`

This scorecard supports local installation and continued evaluation. Stable catalog promotion requires the missing external evidence or an explicit review decision.
