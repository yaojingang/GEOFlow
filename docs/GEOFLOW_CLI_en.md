# GEOFlow CLI 0.2.0

GEOFlow CLI is the repository's API v1 client for catalog, task, job, material, and article operations. It handles profiles, login, HTTPS policy, secret redaction, JSON validation, deletion confirmation, and API error hints.

The supported platforms are macOS, Linux, and WSL. Native Windows can run PHP, but the CLI cannot verify Windows ACLs. If you must save a token from native Windows, restrict the profile manually or run the CLI in WSL.

## Installation And Prerequisites

The CLI ships with the GEOFlow source tree. It requires:

- PHP 8.3 or later.
- Project dependencies installed with Composer.
- Network access to a GEOFlow instance with `/api/v1` enabled.
- An admin login or an API token with the required scopes.

```bash
git clone https://github.com/yaojingang/GEOFlow.git
cd GEOFlow
composer install --no-interaction --prefer-dist

bin/geoflow --version
bin/geoflow --help
```

If the executable bit was lost while copying the repository, restore it:

```bash
chmod +x bin/geoflow
```

You can also invoke the file through PHP:

```bash
php bin/geoflow --version
```

`--version` returns JSON. The current version is `0.2.0`. Treat `--help` as the source of truth for command names and basic usage.

## Profiles And Precedence

The CLI selects one profile per invocation in this order:

1. `--config PATH`
2. An existing `.geoflow.json` in the current working directory
3. `~/.config/geoflow/config.json`

After profile selection, each value follows this precedence:

1. CLI option
2. Environment variable
3. Selected profile
4. Built-in default

Supported environment variables:

| Setting | Environment variable |
|---|---|
| Base URL | `GEOFLOW_BASE_URL` |
| Token | `GEOFLOW_TOKEN`, `GEOFLOW_API_TOKEN` |
| Timeout | `GEOFLOW_TIMEOUT` |
| Remote HTTP exception | `GEOFLOW_ALLOW_INSECURE_HTTP` |

Inspect resolved sources without exposing the token:

```bash
bin/geoflow --config /path/to/profile.json config show
```

The JSON output masks the token and includes endpoint source, credential source, and binding status.

### Endpoint And Credential Binding

The CLI rejects source combinations that could send a token to the wrong host:

- An endpoint loaded implicitly from the current directory's `.geoflow.json` must use the token from the same file.
- `--base-url` requires credentials in the same invocation. Prefer `--token-stdin`.
- `GEOFLOW_BASE_URL` cannot inherit a token from a profile. Pair it with `GEOFLOW_TOKEN`, `GEOFLOW_API_TOKEN`, or `--token-stdin`.
- A profile selected with `--config`, and the default home profile, are trusted profiles.
- A remote HTTP exception saved in a profile applies only to that profile's endpoint. When `--base-url` or `GEOFLOW_BASE_URL` replaces the endpoint, pass `--allow-insecure-http` or set `GEOFLOW_ALLOW_INSECURE_HTTP` in the same invocation environment.

Git ignores `.geoflow.json` at every repository depth. Keep real tokens out of copies, uploads, shell logs, and commits.

## HTTPS And HTTP

The CLI adds `https://` when a URL has no scheme. It allows HTTP for these loopback hosts:

- `localhost` and `*.localhost`
- `127.0.0.0/8`
- `::1`

Other HTTP targets require `--allow-insecure-http`. Use this exception only for an approved test host. HTTPS certificate verification stays enabled, and the API client does not follow redirects. To enforce the 5 MiB response limit during transfer, the CLI requests `identity` encoding and rejects API responses with a compressed `Content-Encoding`.

```bash
bin/geoflow --allow-insecure-http login \
  --base-url http://test-host.example \
  --username admin
```

Use HTTPS in production.

## Initialize A Profile And Log In

### Initialize With An Existing Token

An interactive terminal can use the hidden token prompt and write the default profile:

```bash
bin/geoflow config init --base-url https://geoflow.example.com
```

Automation can supply one token line through protected stdin:

```bash
bin/geoflow --token-stdin config init \
  --base-url https://geoflow.example.com \
  --file /path/to/profile.json
```

Connect a secret manager or hidden input to stdin. Do not paste a real token into an example. Overwriting an existing profile requires `--force`.

On macOS, Linux, and WSL, token-bearing profiles are written with mode `0600`. The default profile directory is restricted to `0700`.

### Log In With An Admin Account

Interactive login hides the password:

```bash
bin/geoflow login \
  --base-url https://geoflow.example.com \
  --username admin
```

Write the login result to a selected profile:

```bash
bin/geoflow --config /path/to/profile.json login \
  --base-url https://geoflow.example.com \
  --username admin
```

Refresh an invalid or expired token in an existing profile:

```bash
bin/geoflow --config /path/to/profile.json login \
  --base-url https://geoflow.example.com \
  --username admin \
  --force
```

Non-interactive login reads one password line with `--password-stdin`. Legacy `--token` and `--password` options remain accepted for compatibility, emit a deprecation warning, and are scheduled for removal in the next major CLI version. New automation should use hidden prompts, environment variables, or stdin.

## Global Options

| Option | Meaning |
|---|---|
| `--config PATH` | Select a trusted profile. |
| `--base-url URL` | Set the GEOFlow web root for this invocation. |
| `--token-stdin` | Read one token line from stdin. |
| `--timeout SECONDS` | Set a positive request timeout. |
| `--allow-insecure-http` | Allow remote HTTP for an explicitly approved test host. |
| `--no-interaction`, `-n` | Disable prompts. |
| `--help`, `-h` | Show help. |
| `--version`, `-V` | Show JSON version data. |
| `--quiet`, `-q` | Suppress normal output. |
| `--verbose`, `-v` | Increase verbosity. It may be repeated. |

Global options may appear before subcommands. The examples below put `--config` first so command logs are easy to audit.

## Complete Command Reference

### Config, Login, And Catalog

```text
geoflow config init --base-url URL [--token-stdin] [--file PATH] [--force]
geoflow config show [--config PATH]
geoflow login --base-url URL [--username USER] [--password-stdin] [--file PATH] [--force]
geoflow catalog
```

`catalog` returns models, prompts, keyword libraries, title libraries, image libraries, knowledge bases, authors, and categories. Run it before the first mutation:

```bash
bin/geoflow --config /path/to/profile.json catalog
```

### Tasks And Jobs

```text
geoflow task list [--page N] [--per-page N] [--status STATUS] [--search TEXT]
geoflow task create --json FILE [--idempotency-key KEY]
geoflow task get TASK_ID
geoflow task update TASK_ID --json FILE [--idempotency-key KEY]
geoflow task delete TASK_ID [--yes]
geoflow task start TASK_ID [--enqueue-now] [--idempotency-key KEY]
geoflow task stop TASK_ID [--idempotency-key KEY]
geoflow task enqueue TASK_ID [--job-type TYPE] [--payload-json FILE] [--idempotency-key KEY]
geoflow task jobs TASK_ID [--status STATUS] [--limit N]
geoflow job get JOB_ID
```

Task creation requires `name`, `title_library_id`, `prompt_id`, and `ai_model_id`:

```json
{
  "name": "CLI task",
  "title_library_id": 1,
  "prompt_id": 2,
  "ai_model_id": 3,
  "status": "paused",
  "publish_scope": "local_only",
  "knowledge_base_ids": [4, 5],
  "need_review": 1
}
```

```bash
bin/geoflow --config /path/to/profile.json task create \
  --json ./task.json \
  --idempotency-key task-create-001

bin/geoflow --config /path/to/profile.json task start 12 \
  --enqueue-now \
  --idempotency-key task-start-12

bin/geoflow --config /path/to/profile.json task jobs 12 --limit 20
bin/geoflow --config /path/to/profile.json job get 88
```

`knowledge_base_ids` accepts up to five IDs and takes precedence over legacy `knowledge_base_id`. API v1 and the CLI do not bind concrete `distribution_channel_ids`; use the admin task form when a task must target selected channels.

### Materials

Supported types:

- `categories`
- `authors`
- `keyword-libraries`, alias `keywords`
- `title-libraries`, alias `titles`
- `image-libraries`, alias `images`
- `knowledge-bases`, alias `knowledge`

```text
geoflow material summary
geoflow material list TYPE [--page N] [--per-page N] [--search TEXT]
geoflow material create TYPE --json FILE [--idempotency-key KEY]
geoflow material get TYPE ID
geoflow material update TYPE ID --json FILE [--idempotency-key KEY]
geoflow material delete TYPE ID [--yes]
geoflow material item-list TYPE ID [--page N] [--per-page N]
geoflow material item-create TYPE ID --json FILE [--idempotency-key KEY]
geoflow material item-upload TYPE ID --image FILE [--idempotency-key KEY]
geoflow material item-delete TYPE ID (--ids 1,2 | --json FILE) [--yes]
```

`item-upload` supports `image-libraries` or `images` and uses `--image`:

```bash
bin/geoflow --config /path/to/profile.json material item-upload images 9 \
  --image ./cover.png \
  --idempotency-key image-upload-001
```

Choose exactly one item deletion input:

```bash
bin/geoflow --config /path/to/profile.json material item-delete titles 34 --ids 101,102
bin/geoflow --config /path/to/profile.json material item-delete titles 34 --json ./delete-items.json
```

Knowledge-base items are generated chunks and are read-only. Update the parent knowledge-base content to rebuild them.

### Articles

```text
geoflow article list [--page N] [--per-page N] [--task-id ID] [--status STATUS]
  [--review-status STATUS] [--author-id ID] [--search TEXT]
geoflow article create (--json FILE | direct fields) [--idempotency-key KEY]
geoflow article get ARTICLE_ID
geoflow article update ARTICLE_ID (--json FILE | direct fields) [--idempotency-key KEY]
geoflow article review ARTICLE_ID --status STATUS [--note TEXT]
  [--risk-override-reason TEXT] [--idempotency-key KEY]
geoflow article publish ARTICLE_ID [--idempotency-key KEY]
geoflow article trash ARTICLE_ID [--idempotency-key KEY]
```

Article create and update share these direct fields:

- `--title`
- `--excerpt`
- `--slug`
- `--keywords`
- `--meta-description`
- `--task-id`
- `--author-id`
- `--category-id`
- `--content`
- `--content-file`

These workflow fields are create-only:

- `--status`
- `--review-status`
- `--ai-generated`

Use `article review`, `article publish`, or `article trash` for workflow transitions. The update command rejects the three create-only fields before sending a request.

Article creation requires a non-empty title and content. `--content` and `--content-file` are mutually exclusive. When `--json` is present, the JSON object supplies the request body.

```bash
bin/geoflow --config /path/to/profile.json article create \
  --title "CLI article" \
  --content-file ./article.md \
  --author-id 5 \
  --category-id 2 \
  --idempotency-key article-create-001

bin/geoflow --config /path/to/profile.json article review 101 \
  --status approved \
  --note "Reviewed" \
  --idempotency-key article-review-101

bin/geoflow --config /path/to/profile.json article publish 101 \
  --idempotency-key article-publish-101

bin/geoflow --config /path/to/profile.json article get 101
```

After publication, read the article again and use the persisted `/article/{slug}` URL. Do not report the legacy `article.php?id=...` compatibility form.

## JSON Files And Stdin

`--json FILE` and `--payload-json FILE` require a JSON object at the top level. Use `-` to read it from stdin:

```bash
bin/geoflow --config /path/to/profile.json task create \
  --json - \
  --idempotency-key task-create-stdin
```

`--content-file -` reads article content from stdin. JSON and text inputs have a 5 MiB limit. `--token-stdin` and `--password-stdin` read one line with a 64 KiB safety limit.

A process has one stdin stream. Do not combine two options that both need it, such as `--token-stdin` and `--json -`.

## Deletion Confirmation And `--yes`

These commands ask for interactive confirmation:

- `task delete`
- `material delete`
- `material item-delete`

Non-interactive execution requires `--yes`. Read the exact ID and current state first:

```bash
bin/geoflow --config /path/to/profile.json task get 12
bin/geoflow --config /path/to/profile.json --no-interaction task delete 12 --yes
```

`--yes` confirms only the exact CLI target. Token scopes, server authorization, locks, business validation, and admin high-risk confirmations still apply.

Current DELETE operations do not use idempotency keys. Do not add `X-Idempotency-Key` to task deletion, material-library deletion, or material-item deletion. Supported POST and PATCH operations can use `--idempotency-key`, including creates, updates, task actions, article review/publish/trash, and image upload.

## Output, Errors, And Exit Codes

Successful API commands write the server JSON object to stdout. `config show` and `--version` also produce JSON. Warnings and errors go to stderr, with sensitive fields redacted.

Current exit codes:

| Exit code | Meaning |
|---|---|
| `0` | Command succeeded. |
| `1` | Argument, configuration, transport, API, or unexpected runtime error. |

Interpret HTTP status together with the JSON `error.code`:

| HTTP | Action |
|---|---|
| `401` | Token is invalid or expired. Log in again or update the token. |
| `403` | Token lacks the required scope. Issue a least-privilege replacement token. |
| `409` | Idempotency conflict, in-progress request, or uncertain result. Read the resource state first. |
| `422` | Validation failed. Correct the fields reported in `field_errors`. |
| `423` | Target resource is locked. Inspect its workflow state before retrying. |
| `429` | Rate limit reached. Wait for `retry_after` when present. |
| `500` | Server error. Keep the request ID and inspect app or queue logs. |

The CLI rejects empty responses, non-JSON responses, non-object JSON, 2xx responses without `success=true`, and responses larger than 5 MiB.

## Typical Workflows

### Read-Only Check

```bash
bin/geoflow --config /path/to/profile.json catalog
bin/geoflow --config /path/to/profile.json material summary
bin/geoflow --config /path/to/profile.json task list --per-page 20
bin/geoflow --config /path/to/profile.json article list --per-page 20
```

### Create A Task And Inspect Its Runs

```bash
bin/geoflow --config /path/to/profile.json task create \
  --json ./task.json \
  --idempotency-key task-create-001

bin/geoflow --config /path/to/profile.json task start 12 \
  --enqueue-now \
  --idempotency-key task-start-12

bin/geoflow --config /path/to/profile.json task jobs 12 --limit 20
```

After every mutation, use `task get`, `job get`, or the matching list command to read the persisted state.

### Create, Review, And Publish An Article

```bash
bin/geoflow --config /path/to/profile.json article create \
  --json ./article.json \
  --idempotency-key article-create-001

bin/geoflow --config /path/to/profile.json article review 101 \
  --status approved \
  --idempotency-key article-review-101

bin/geoflow --config /path/to/profile.json article publish 101 \
  --idempotency-key article-publish-101

bin/geoflow --config /path/to/profile.json article get 101
```

If the risk gate requires an override, add `--risk-override-reason` with a truthful, auditable reason.

## Running In Docker

The development Compose application service is named `app`:

```bash
docker compose exec app php bin/geoflow --version
docker compose exec app php bin/geoflow --help
docker compose exec app php bin/geoflow --config /path/in/container/profile.json catalog
```

The profile path must exist inside the container. Supply it through a read-only secret mount or a protected volume. Keep tokens out of image layers, Compose files, and public environment files.

Use the same pattern with the production Compose file:

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml \
  exec app php bin/geoflow --version
```

When the caller runs on the host, it can usually run the host copy of `bin/geoflow` and point `base_url` at the HTTPS address exposed by Nginx.

## API And Admin Boundaries

CLI 0.2.0 covers catalog, task, job, material, and article operations exposed by API v1. If the CLI is absent, use the matching `/api/v1` route with a token carrying the required scopes.

These capabilities currently require an authenticated admin session:

- Analytics overview, content, traffic, AI visibility, leads, and distribution pages.
- Manual publication workbench.
- Distribution channels, target packages, secrets, remote sync, and staged channel deletion.
- AI source providers and model bindings.
- Article AI assistant, risk recheck, editor images, and batch actions.
- Enterprise knowledge, lead administration, URL Import, and System Updates.
- Homepage-module GET editor, site settings, and Theme Replication.
- API tokens, admin users, passwords, and security settings.

The admin prefix comes from `ADMIN_BASE_PATH`; the repository default is `geo_admin`. Run `php artisan route:list --except-vendor` to discover the target deployment. The current repository has no live Theme Editor route.

Local maintenance commands also have no CLI or API equivalent:

```bash
php artisan geoflow:recover-knowledge-syncs --stale=600 --limit=50
php artisan geoflow:prune-expired-cache --limit=5000
```

## Troubleshooting

### Composer Autoload Is Missing

Run Composer for the checked-out code version:

```bash
composer install --no-interaction --prefer-dist
```

### `base_url` Or Token Is Missing

Inspect the selected sources:

```bash
bin/geoflow --config /path/to/profile.json config show
```

Then complete the profile with `login` or `config init`. If credential binding is invalid, select an explicit profile or pair `--base-url` with `--token-stdin` in the same invocation.

### Remote HTTP Is Rejected

Use HTTPS for production. An approved test host can enable `allow_insecure_http` in its invocation or profile. This setting does not disable HTTPS certificate verification.

### The Server Returns HTML

The CLI requires a JSON API. Check that:

- `base_url` points to the GEOFlow web root.
- The address does not already contain `/api/v1` or the admin prefix.
- A reverse proxy is not routing API requests to a login or error page.
- The Docker web port reaches the Laravel or Nginx entrypoint.

### `401`, `403`, `423`, Or `429`

Refresh the token for `401`. Check scopes for `403`. Read workflow state for `423`. Read `retry_after` and wait for `429`. Repeated login attempts do not fix missing scopes, locks, or rate limits.

### Deletion Fails In A Non-Interactive Process

Read the target and confirm its ID, then add `--yes` to that exact deletion command. `--no-interaction` alone does not confirm deletion.

### Native Windows ACL Warning

The CLI reports that it cannot verify ACLs. WSL is the supported route. If native Windows is required, use Windows permission tools to restrict the profile to the current user.
