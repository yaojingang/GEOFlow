# Remote CLI preview verification boundary

Owner: Yao Team. Review cadence: monthly and before release. Mode: Production skill with preview feature coverage.

- input_files: `evals/trigger_cases.json`, `evals/test_geoflow_scripts.py`, and `references/remote-cli-workflow.md` provide file-backed fixture evidence and the execution contract.
- output contract: selected profile, redacted instance/account identity, discovered operation IDs, receipt or workspace state, completed checks, and unavailable capabilities.
- rollback boundary: skill installation preserves the previous package outside the target root. CLI installation uses a verified transaction and previous executable. Theme drafts can be discarded; remote theme publication and rollback are unavailable in this preview. A source package cannot establish any particular machine's installed or authenticated state.
- trust report: the historical reports predate this preview and do not certify it. Re-run package checks and verify the selected instance's identity and capabilities before operations. Native template execution requires explicit code scope and a short-lived password grant for a trusted super administrator.
- `reports/output_quality_scorecard.md`: retained as historical evidence. Current CI exercises deterministic package/helper tests and the signed standalone client in a temporary installation. Test results belong to the evaluated commit and environment.
- missing evidence: official signing trust and download channel, cross-platform installation, model-based trigger/output evaluation, complete two-round theme publication/rollback, and the remaining Skill OS release gates.

Remote operations use the installed CLI and the selected instance's advertised contract. Unsupported release, configuration, large-file, and full-administration operations must remain explicit. Verification of local drafts or fixture APIs does not establish live publication readiness.
