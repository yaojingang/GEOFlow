# Security Trust Report

- OK: `True`
- Automated scanner files: `46`
- Script files: `12` (`10` Python, `2` Bash)
- Internal script modules: `1`
- Secret findings: `0`
- Outbound or delegated-live network entrypoints: `3`
- Loopback-only preview listeners: `1`
- Network policy covered scripts: `3`
- Network policy missing scripts: `0`
- File-write scripts: `3`
- Automated required permission approvals: `2 / 2`
- Reviewed capabilities: `3` (`network`, `file_write`, `subprocess`)
- Permission approval gaps: `0`
- CLI help smoke checked: `9`
- CLI help smoke failures: `0`
- Bash syntax checks: `2 / 2`
- Interactive scripts: `0`
- Package hash scope: `source-contract-without-generated-reports`
- Package hash files: `46`
- Package SHA256: `d953e16a97ee24da76c0971cd76fa9497d5e74381903966587f7a8b03a28ff84`

## Failures

- None

## Warnings

- No dependency or lock file is required; runtime prerequisites are documented in `README.md`.
- The upstream automated trust scanner currently classifies Python direct calls only. This reviewed report additionally covers Bash `curl`, delegated Laravel live reads, and the loopback preview listener. Package regression tests enforce the supplemental inventory.

## Dependency Evidence

- Files: `none`
- Pinned entries: `0`
- Unpinned entries: `0`

## Network Policy

- Policy file: `security/network_policy.json`
- Present: `True`
- Covered scripts: `scripts/geoflow_preflight.sh`, `scripts/build_sync_preview_report.py`, `scripts/compare_default_vs_channel_frontend.py`
- Missing scripts: `none`
- Mismatches: `0`

## Permission Governance

- Policy file: `security/permission_policy.json`
- Present: `True`
- Required capabilities: `file_write, subprocess`
- Approved capabilities: `file_write, subprocess`
- Separately reviewed capability: `network`
- Missing approvals: `none`
- Invalid approvals: `none`
- Expired approvals: `none`

## CLI Help Smoke

- Enabled: `True`
- Timeout seconds: `5.0`
- Checked scripts: `9`
- Passed scripts: `9`
- Failed scripts: `none`

## Script Surface

| Script | Interface | Declared | Argparse | Main Guard | Input | Network mode | File Write | Subprocess | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| scripts/build_sync_preview_report.py | cli | False | True | True | False | delegated-live | False | True | Fixed-argument local Artisan command; live mode delegates the signed remote read to GEOFlow. |
| scripts/channel_endpoint_safety.py | internal-module | True | False | False | False | False | False | False | Shared endpoint and redirect-safety checks imported by channel report CLIs. |
| scripts/compare_default_vs_channel_frontend.py | cli | False | True | True | False | delegated-live | False | True | Fixed-argument local Artisan command; live mode delegates the signed remote read to GEOFlow. |
| scripts/discover_frontend_surfaces.py | cli | False | True | True | False | False | False | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
| scripts/discover_geoflow_workspace.py | cli | False | True | True | False | False | True | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
| scripts/discover_themes.py | cli | False | True | True | False | False | False | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
| scripts/finalize_theme_edit_session.py | cli | False | True | True | False | False | True | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
| scripts/geoflow_preflight.sh | cli | True | n/a | n/a | False | direct-curl | temporary-only | False | Validates operator-selected GEOFlow endpoints, bounds responses, and redacts sensitive output. |
| scripts/install_codex_skill.sh | cli | True | n/a | n/a | False | False | bounded-install | False | Copies the declared artifact contract through same-filesystem staging and moves prior Skills to persistent backup. |
| scripts/prepare_theme_edit_session.py | cli | False | True | True | False | False | True | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
| scripts/serve_preview.py | cli | False | True | True | False | loopback-listener | False | False | Serves a fixed allowlist from the bundled preview directory on `127.0.0.1`. |
| scripts/validate_homepage_design_payload.py | cli | False | True | True | False | False | False | False | Default CLI classification; add SCRIPT_INTERFACE for internal modules. |
