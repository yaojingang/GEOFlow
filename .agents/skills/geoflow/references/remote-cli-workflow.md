# Remote CLI workflow — 0.4.0 preview

## Installation and connection

Use an existing `geoflow` executable on PATH. It can run outside a GEOFlow checkout with PHP 8.3+ and the declared extensions. This iteration includes an attested candidate workflow, a versioned trust-bundle verifier and a signed bundle installer. Official public assets and production trust roots remain release gates. Do not invent a download URL or trust a key supplied by the same unverified bundle. Install from a maintainer-provided bundle with an independently trusted key file, following its installer instructions. Do not pipe remote scripts into a shell.

Run these with the intended instance and profile, substituting the user's values:

```sh
geoflow --version
geoflow --help
geoflow login --profile staging --base-url https://geoflow.example --username admin --scopes sites:read,themes:read,themes:write,themes:code
geoflow whoami --profile staging
geoflow capabilities --profile staging
geoflow doctor --profile staging
```

Use the hidden password prompt or `--password-stdin`; keep secrets out of command arguments, transcripts, logs, and reusable payloads. Login explicitly requests scopes. The CLI verifies the granted set and stores the instance/account identity. Bind older profiles before new management writes: either re-login, or review `whoami` and run `geoflow --profile staging profile bind --instance-id EXPECTED_INSTANCE_UUID --admin-id EXPECTED_ADMIN_ID`. Binding uses only the selected profile's address and token, requires both expected values to match the server, and refuses to overwrite a configuration changed during the request. Re-check identity after changing a base URL. Each profile has separate credentials. `logout --profile staging` revokes that profile's current token remotely; a network failure requires later revocation and must be reported.

Old servers may only expose the legacy article, task, catalog, job, and material commands. A capability endpoint returning 404 permits a verified legacy read-only diagnostic fallback. Authentication, rate-limit, TLS, and server errors do not imply a legacy server.

## Planned host operations

For backups, upgrades, code switch-back and full data recovery, read [remote-updater-workflow.md](remote-updater-workflow.md). These actions have their own scopes, plans and host receipts. Theme field rollback and full database restoration have different effects.

## Remote theme draft

1. Read `site list`, `site show primary`, and `api themes.list`; only the primary site is supported here.
2. Invoke `api theme-workspaces.create --input FILE` with `{"body":{"site":"primary","theme":"default"}}`. Both built-in and installed source themes are discovered on the server. Record the workspace ID and version. No template archive round trip is required.
3. Read `api theme-workspaces.contract` with `{"path":{"workspace":"ID"}}`. The contract gives the frozen revision, fallback files, controller variables, URL rules, limits, and explicitly unavailable features. Read the file inventory from `theme-workspaces.show`.
4. Read files through `theme-workspaces.file`, for example `{"path":{"workspace":"ID"},"query":{"path":"resources/views/site/home.blade.php","offset":0,"length":262144}}`. Content is base64, at most 256 KiB per response. Join chunks only after checking revision ID, file hash, and total size remain constant.
5. Native Blade/CSS/JS editing and candidate rendering require a trusted instance super administrator, explicit `themes:code`, and a password-verified 30-minute grant on that workspace. Request `theme-workspaces.authorize-code` with the password via `--input -` from a protected input stream; never save it in a retry file. Blade executes with the application's privileges. Browser sandbox headers do not isolate server-side PHP.
6. Submit `theme-workspaces.change` with the current `expected_version` and an atomic changes array. Each file needs a path, `action: put|delete`, and `expected_sha256`; a new file uses null. Text replacements use `content`. The entire JSON batch is limited to 1 MiB, even though a stored file may be up to 5 MiB. Large-file upload adapters are still pending.
7. Request `theme-workspaces.preview` and use its complete signed links. Preview covers actual published content and empty states, custom article permalinks, navigation and assets. Links expire after 15 minutes and require current authorization. Editing, discarding, revocation, or an expired code grant invalidates access. Record browser/CSP/visual checks separately; URL generation is not visual validation.
8. Repeat read → compare → change → preview. A version/hash conflict requires a fresh read. After a lost create response, do not blindly create another draft. After a lost change response, inspect its version and file hashes before continuing; this preview has no draft receipt lookup.
9. Close an unwanted draft using `theme-workspaces.discard` with `expected_version`. Closing invalidates its grant and preview. Cleanup protects referenced revisions and gives in-flight reads a 15-minute grace period. Retry discard later to reclaim eligible files; inspect `cleanup.state` and reclaimed byte count.

Every generic invocation has the form:

```sh
geoflow api theme-workspaces.show --profile staging --input workspace.json
```

Input is structured as `path`, `query`, and `body`; it is never an arbitrary URL or shell command. The client only dispatches locally known operation IDs also advertised by the server.

## Receipts and uncertain outcomes

For `api tasks.enqueue`, choose one client request ID and retain it. The client writes a protected journal before transmission. Reuse the same identity/input when recovering and query `operation lookup CLIENT_REQUEST_ID` after a lost response. Re-login to the same instance/account preserves access when current resource permissions still permit it. A different input with the same ID is a conflict. `operation wait ID --wait-seconds 30` is bounded; pending state must not be reported as completed work.

A missing remote receipt cannot prove the write was never executed: restoring an older database can erase its record. Repeated local requests stop on `operation_not_found` / 404, including old `prepared` journals without an operation ID. Keep the journal through login, profile binding and CLI updates. Reconcile the business result and recovery history; only an explicit instruction to execute again after that reconciliation permits a new request ID. Do not delete the journal or generate a new ID to bypass an uncertain result.

Legacy command idempotency and new receipt support use separate request headers. Use `--client-request-id` for `api tasks.enqueue`; receipt operations reject `--idempotency-key`. The legacy `task enqueue --idempotency-key` command remains supported. The server rejects requests combining `X-Client-Request-Id` and `X-Idempotency-Key`, so no requested protection is silently ignored. Never infer a receipt from a POST method or attach automatic retries to every mutation.

## Delivery boundary

The current server reports `theme_publication.available: false` with missing prerequisites. Publish/apply, field-level rollback, managed-revision sources, large-file staging, a remote configuration adapter, and full backend/hosted-site/Agent management are tracked in the upgrade coverage ledger. Their commands must not be claimed available. Existing Web operations retain their own contracts and require authorization for their actual effect. Do not silently switch to a template-package upload workflow when the requested remote operation is missing.

Output: selected profile and redacted instance/account identity; discovered operation IDs; workspace/revision/version or receipt IDs; performed checks and current state; unresolved transport outcomes; unavailable capabilities and unverified environment layers.
