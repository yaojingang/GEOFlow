# Coordinated Updater Skill preview boundary

Owner: Yao Team. Review cadence: monthly and before release. Version: 1.2.0-preview.1.

- input_files: `references/remote-updater-workflow.md`, `evals/trigger_cases.json`, `evals/failure_cases.md`, and `evals/test_geoflow_scripts.py` are file-backed fixture inputs.
- output contract: verified profile and capabilities, saved plan, stable request identity, redacted receipt, distinct HTTP/background state, and explicit unverified layers.
- rollback boundary: this source update changes instructions. It does not deploy a server or run a restore. Full restore, code switch-back and theme field rollback retain their separate scopes.
- trust report: host discovery and current explicit scopes control actions. Credentials remain in hidden input or protected files. Missing receipts permit lookup only. Existing password policy is retained; the host code remains mandatory.
- `reports/output_quality_scorecard.md` is historical evidence. Deterministic package checks establish source-package consistency. Core/Updater integration tests establish only their stated fixture and environment boundaries.
- missing evidence: model-based trigger/output evaluation, native macOS/Linux/WSL package installation matrix, complete Skill OS release gates, real protected signing/public release, and native amd64/arm64 coordinated restore rehearsals. These are not claimed by source or unit-test completion.

The package routes new Updater intents to the saved-plan workflow and keeps theme publication gated. Installed instance capabilities decide availability; a source branch cannot establish the user's runtime version.

## Local verification recorded on 2026-09-16

- Deterministic package/helper suite: 39 passing tests in an isolated temporary environment.
- Independent instruction review: command names, scope mapping, plan/receipt handling and declared missing evidence checked against the implemented client and bridge.
- Model-based trigger/output benchmarks remain missing evidence. Independent code/instruction review is recorded separately from those benchmarks.
