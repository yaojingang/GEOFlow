# Updater coordination protocol v2

Implementation contract for the coordinated preview. Core management protocol
remains `1.0`; host HTTP protocol is `2`, recovery state schema is `1`, and updater
protocol is `5`. These numbers are independent. The host implementation and joint
acceptance must pass before this capability is advertised by an installed pair.

## Host endpoints

All paths start with `/v2/instances/{instance}` and require the existing local
control credential. The initial instance is `primary`.

| Method | Path | Result |
| --- | --- | --- |
| GET | `/capabilities` | Actual host guarantees and current recovery state |
| POST | `/plans` | Persist a ten-minute plan |
| GET | `/plans/{plan_id}` | Read the persisted plan |
| POST | `/operations` | Admit a typed action or return its existing receipt |
| GET | `/requests/{client_request_id}` | Find the authoritative admission record |
| GET | `/operations/{operation_id}` | Find the same receipt by operation identity |

Successful host responses are direct JSON objects, without the Core API envelope.
Errors use `{"error":"bounded_snake_case_code"}`. Only an exact HTTP 404 with
`request_not_found` means the host has no matching receipt at the time of reading.
A missing receipt never authorizes automatic resubmission. Rate-limit errors
preserve the bounded integer `Retry-After` header through the Core bridge.

### Capabilities

Required fields:

- `schema_version: 2`, `instance_id`, `protocol_version: 2`, `updater_protocol: 5`.
- `actions`: currently supported subset of `update`, `backup`, `restore`, `switch-back`.
- `features`: `plans`, `requests`, `idempotency`, `recovery_epoch`, all `true`.
- `maintenance_confirmation: true`, `restore_policy: "latest_update_checkpoint"`.
- `background_status`: `ready` or `held`.
- `recovery`: `host_id`, `epoch` as 32 lower-case hex characters; `phase` from
  `ready`, `restoring`, `validating`, `http_ready`.

Core checks the real response. Capability listing suppresses write operations
when the local recovery mount and host identity/epoch do not agree. Discovery,
status and receipt polling never create a plan.

### Plans

Creation accepts `action`, `expected_epoch`, `actor`, and a `recovery_point_id`
only for restore. The actor contains `management_instance_id` (Core UUID),
`admin_id` (positive integer), and `identity_sha256` (64 lower-case hex). The hash
is opaque to the host; it identifies the current Core account incarnation and
is retained as audit context. Current credentials determine access to receipts.

A plan returns `schema_version: 2`, `instance_id`, `plan_id` (32 hex),
`plan_sha256` and `baseline_sha256` (64 hex), `expected_epoch`, `action`, `actor`,
`expires_at` (UTC RFC3339), `maintenance_required` (boolean), and `continuation`
(`remote` or `host_only`). Action details fix all source/target, configuration,
Compose, slot, original transaction, and recovery-point manifest identities
specified by the approved plan. Digests cover these persisted details.

The host checks plan expiry and current baseline under the instance lock for a
new admission. Existing admission lookup precedes those checks. Valid restore
plans protect their point from retention, as do accepted active operations.
Remote restore admits only the latest update checkpoint. Host CLI retains its
explicit historical restore workflow. Old targets use `host_only` continuation.

### Admission

`POST /operations` business input has exactly these fields:

- `action`, `plan_id`, `plan_sha256`, `expected_epoch`.
- `allow_maintenance`, `confirm_host_access`, both booleans.

The request also contains `client_request_id`, `actor`, and `scope`. The request
ID matches `[A-Za-z0-9][A-Za-z0-9._-]{7,127}`. Scope is `updater:update` for update
and switch-back, `updater:backup` for backup, or `updater:restore` for restore.
The six-digit code is sent only in `X-GEOFlow-Updater-Authorization`. Core verifies
the password and never forwards it. No secret contributes to the business hash.

Canonical business hashing: sort the six business keys alphabetically, encode
compact JSON with native booleans and no whitespace, then SHA-256 the UTF-8 bytes.
The referenced plan hash binds its target and baseline. Admission stores the
original actor and authorization evidence separately, without raw credentials.

Persisted admission is the authority for OTP consumption, request mapping and
preallocated operation identity. Counter and operation files are projections.
Reconcile them before accepting new writes after restart. Side effects start
only after the authoritative record and its parent directory are durable.
Shared v1/v2 OTP accounting prevents cross-protocol reuse. Audit or cleanup
failures after acceptance must preserve a queryable receipt.

### Receipts

The versioned envelope contains:

- `schema_version: 2`, `instance_id`, `client_request_id`, `business_sha256`.
- `operation_id` using the existing timestamp-plus-random v1 identity format.
- `action`, `plan_id`, `accepted_epoch`.
- `admission_status`: `accepted` or `pending`.
- `background_status`: `ready` or `held`.
- `operation`: the unchanged v1 operation object, or `null` while reconciliation
  cannot yet establish the operation projection.

The restore action retains v1 operation kind `rollback`. All v1 fields and enums
remain unchanged. A different request ID or operation ID in a response is an
error. Core preserves `rolled_back` as an unsuccessful target update, and maps
host success with held background work to `recovery_required`.

Full receipts are retained at least 90 days; running and unresolved records are
not pruned. Minimal request-to-business-to-operation mappings live until explicit
instance retirement. Storage exhaustion rejects new admissions.

## Core, Web and CLI

Core routes live under `/api/v1/management/updater/`. Access requires an active
super administrator and explicit `updater:read`, `updater:plan`, or action scope.
Old wildcard tokens receive none of these scopes. Fresh authorization is checked
again immediately before transfer. Existing requests are looked up before plan,
epoch, password or OTP validation; current credentials remain required.

The new Web console uses the same service and host admission. The former direct
Web write endpoints redirect to plan creation and never call v1 writes. The Web
form displays its request ID before submission and saves a non-secret local
continuation marker. Reusing that marker navigates to receipt lookup. It fails
closed if that local marker cannot be saved. Manual receipt lookup also works
after re-login and database restore.

CLI writes require a bound profile, saved plan, explicit request ID and hidden
input or a user-owned protected credentials file. Local validation precedes a
new journal record; an existing journal permits receipt reads only, even after
404, re-login or an epoch change. The journal is synchronized before any POST.
`operation wait` uses GET only, starts at two seconds, backs off to thirty seconds,
and defaults to ten minutes. It returns exit 2 for pending timeout or manual
recovery attention, exit 1 for failed/rolled-back, and exit 0 only for full success.
A target without new Core routes requires host-side receipt lookup.

## Coordinated distribution floor

The pinned Core source includes `deployment/recovery-contract.json`. The Updater
candidate and publication workflows validate that exact declaration and require
minimum updater protocol `5`. A legacy Core checkout without this declaration
keeps its maintenance/online floors `3`/`4`. An invalid declaration fails the
build gate; no version-string inference or legacy fallback is used.

Coordinated Core releases remain maintenance-only. The isolated same-application
online rehearsal retains a source floor of `5`; its infrastructure evidence does
not authorize online compatibility for another Core version pair. The existing
schema 3 TUF manifest uses its existing `minimum_updater_protocol` field.
