<!--
Copyright © 2026 姚金刚. All rights reserved.
Project: geoflow
Created by: 姚金刚
Date: 2026-05-16
X: https://x.com/yaojingang
-->

# Command Map

## Surface Selection

When `bin/geoflow` exists, treat GEOFlow CLI 0.2.0 as the preferred API v1 client. Run `--version` and `--help`, then use a listed command for catalog, task, job, material, and article operations. The CLI keeps authentication, endpoint validation, JSON envelopes, response limits, redirect blocking, secret redaction, and deletion confirmation in one implementation.

When the CLI is absent, use the API v1 fallback in this reference. Use authenticated admin web routes for capabilities missing from both CLI and API v1. Inspect the current routes before acting:

```bash
php artisan route:list --path=api/v1
php artisan route:list --except-vendor
```

Never invent a CLI command or API route from an admin page name.

## Preflight

```bash
scripts/geoflow_preflight.sh "<workspace>" [config] [checks]
```

`checks` is comma-separated and accepts `catalog`, `materials`, `tasks`, `articles`, and `admin`:

```bash
scripts/geoflow_preflight.sh "/path/to/GEOFlow"
scripts/geoflow_preflight.sh "/path/to/GEOFlow" "/path/to/profile.json" catalog,materials,tasks,articles
GEOFLOW_ADMIN_PATH=/geo_admin scripts/geoflow_preflight.sh "/path/to/GEOFlow" "" admin
```

CLI mode always verifies `--version`, `--help`, and `config show`. It then maps checks to these read-only commands:

| Check | CLI command |
|---|---|
| `catalog` | `catalog` |
| `materials` | `material summary` |
| `tasks` | `task list --per-page 1` |
| `articles` | `article list --per-page 1` |
| `admin` | HTTP read of the configured admin login page |

If a config argument is supplied, the helper passes `--config` to every CLI invocation. API fallback requires `GEOFLOW_BASE_URL` and `GEOFLOW_API_TOKEN` for authenticated checks. The base URL is the public web root, such as `http://127.0.0.1:18080`, without `/api/v1` or the admin prefix.

## CLI Configuration And Authentication

Supported runtime platforms are macOS, Linux, and WSL. Native Windows can run PHP, but the CLI cannot verify Windows ACLs on saved credentials. Use WSL or restrict the file manually if native Windows is unavoidable.

Profile selection uses this order:

1. `--config PATH`
2. `.geoflow.json` in the current working directory, when the file exists
3. `~/.config/geoflow/config.json`

For each setting, CLI options override environment variables, which override the selected profile. Supported environment variables are `GEOFLOW_BASE_URL`, `GEOFLOW_TOKEN` or `GEOFLOW_API_TOKEN`, `GEOFLOW_TIMEOUT`, and `GEOFLOW_ALLOW_INSECURE_HTTP`.

The endpoint and credential binding rules reject unsafe source mixing:

- An endpoint loaded implicitly from the current directory's `.geoflow.json` must use the token from that same file.
- `--base-url` requires a token supplied in the same invocation. Prefer `--token-stdin`.
- `GEOFLOW_BASE_URL` cannot inherit a token from a config file. Pair it with `GEOFLOW_TOKEN`, `GEOFLOW_API_TOKEN`, or `--token-stdin`.
- An explicit `--config` selects a trusted profile. A home profile is also treated as a trusted profile.
- A remote HTTP exception belongs to the endpoint source that enabled it. Overriding a profile endpoint with `--base-url` or `GEOFLOW_BASE_URL` also requires `--allow-insecure-http` or `GEOFLOW_ALLOW_INSECURE_HTTP` from that invocation environment.

Inspect the resolved sources without revealing the token:

```bash
bin/geoflow --config /path/to/profile.json config show
```

Initialize a profile with a token read from stdin:

```bash
bin/geoflow --token-stdin config init \
  --base-url https://geoflow.example.com \
  --file /path/to/profile.json
```

Without `--file` or `--config`, `config init` writes `~/.config/geoflow/config.json`. Existing files require `--force`. On POSIX systems, the CLI writes token-bearing files as mode `0600` and limits the default config directory to `0700`.

## First Login

Login uses a hidden password prompt in an interactive terminal:

```bash
bin/geoflow login \
  --base-url https://geoflow.example.com \
  --username admin
```

For non-interactive secret delivery, supply one line on stdin:

```bash
bin/geoflow --config /path/to/profile.json login \
  --base-url https://geoflow.example.com \
  --username admin \
  --password-stdin \
  --force
```

The caller must connect a protected secret source to stdin. Do not place a real password in the example or command history. `--token` and `--password` remain accepted for compatibility, emit a deprecation warning, and are scheduled for removal in the next major CLI version.

If a URL has no scheme, the CLI adds `https://`. Loopback HTTP is allowed for `localhost`, `*.localhost`, `127.0.0.0/8`, and `::1`. Other HTTP targets require an explicit `--allow-insecure-http`; use that exception only for an approved test host. HTTPS certificate verification remains enabled, API redirects are not followed, and the CLI requests identity encoding so its 5 MiB response limit applies before decompression. Compressed API responses are rejected.

### API-only fallback

When `bin/geoflow` is absent, the following interactive flow keeps the password, response token, and authorization header out of process arguments and stdout. It applies the API fallback policy used below: a missing scheme becomes HTTPS, remote HTTP is rejected, and loopback HTTP is allowed.

Run this protected transport setup once in the current Bash shell. It streams curl stdout through Python, writes at most 5 MiB to the protected response file on every curl version, rejects redirect/output overrides, and recursively redacts error output:

```bash
geoflow_bounded_curl() {
  local output_path="$1" stderr_path="$2" max_bytes="$3"
  shift 3
  python3 - "$output_path" "$stderr_path" "$max_bytes" "$@" <<'PY'
import os
import pathlib
import re
import signal
import stat
import subprocess
import sys
import tempfile

output_path = pathlib.Path(sys.argv[1])
stderr_path = pathlib.Path(sys.argv[2])
max_bytes = int(sys.argv[3])
command = sys.argv[4:]
if max_bytes <= 0 or not command:
    raise SystemExit("Invalid bounded curl invocation")

blocked_long = ("--dump-header", "--include", "--location", "--location-trusted", "--output", "--write-out")
blocked_short = {"D", "i", "L", "o", "w"}
for argument in command[1:]:
    if argument in blocked_long or any(argument.startswith(option + "=") for option in blocked_long):
        raise SystemExit(f"Bounded curl forbids caller-controlled option: {argument}")
    if argument.startswith("-") and not argument.startswith("--") and any(flag in argument[1:] for flag in blocked_short):
        raise SystemExit(f"Bounded curl forbids caller-controlled short option: {argument}")
if "--disable" not in command or "--globoff" not in command:
    raise SystemExit("Bounded curl requires --disable and --globoff")
for path in (output_path, stderr_path):
    if not stat.S_ISREG(path.lstat().st_mode):
        raise SystemExit(f"Bounded curl output must be a regular file: {path}")

header_handle = tempfile.NamedTemporaryFile(prefix="geoflow-curl-headers-", delete=False)
header_path = pathlib.Path(header_handle.name)
header_handle.close()
os.chmod(header_path, 0o600)
command = [command[0], "--dump-header", str(header_path), *command[1:]]
process = None
try:
    with output_path.open("wb") as output_handle, stderr_path.open("wb") as error_handle:
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=error_handle)
        assert process.stdout is not None
        written = 0
        while True:
            chunk = process.stdout.read(65536)
            if not chunk:
                break
            remaining = max_bytes - written
            if len(chunk) > remaining:
                if remaining > 0:
                    output_handle.write(chunk[:remaining])
                process.stdout.close()
                process.terminate()
                try:
                    process.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
                print(f"Response exceeded {max_bytes} bytes; curl was stopped.", file=sys.stderr)
                raise SystemExit(63)
            output_handle.write(chunk)
            written += len(chunk)
        process.stdout.close()
        return_code = process.wait()
        if return_code != 0:
            raise SystemExit(return_code)

    statuses = re.findall(rb"(?mi)^HTTP/[^\s]+\s+(\d{3})\b", header_path.read_bytes())
    if not statuses:
        raise SystemExit("Curl completed without an HTTP status line")
    print(statuses[-1].decode("ascii"))
finally:
    if process is not None and process.poll() is None:
        process.send_signal(signal.SIGTERM)
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
    try:
        header_path.unlink()
    except FileNotFoundError:
        pass
PY
}

geoflow_print_safe_files() {
  python3 - "$@" <<'PY'
import json
import os
import pathlib
import re
import sys
import unicodedata

sensitive_fragments = ("authorization", "password", "secret", "token", "api_key", "api-key", "apikey")
environment_secrets = [
    value for name in ("GEOFLOW_TOKEN", "GEOFLOW_API_TOKEN")
    if (value := os.environ.get(name))
]

def sanitize_text(value):
    value = "".join(char for char in value if unicodedata.category(char) not in {"Cc", "Cf"})
    for secret in environment_secrets:
        value = value.replace(secret, "[redacted]")
    value = re.sub(r"(?i)\bBearer\s+[^\s,;<>&]+", "Bearer [redacted]", value)
    return re.sub(
        r'''(?i)\b(authorization|password|secret|token|api[_-]?key|apikey)\b(\s*[:=]\s*)(?:"[^"\r\n]*"|'[^'\r\n]*'|[^\s,;<>&]+)''',
        lambda match: match.group(1) + match.group(2) + "[redacted]",
        value,
    )

def sanitize(value, key=""):
    if any(fragment in key.lower() for fragment in sensitive_fragments):
        return "[redacted]"
    if isinstance(value, str):
        return sanitize_text(value)
    if isinstance(value, list):
        return [sanitize(item) for item in value]
    if isinstance(value, dict):
        return {str(item_key): sanitize(item, str(item_key)) for item_key, item in value.items()}
    return value

for filename in sys.argv[1:]:
    path = pathlib.Path(filename)
    if not path.is_file() or path.stat().st_size == 0:
        continue
    text = path.read_text(encoding="utf-8", errors="replace")[:65536]
    try:
        rendered = json.dumps(sanitize(json.loads(text)), ensure_ascii=False, indent=2)
    except json.JSONDecodeError:
        rendered = sanitize_text(text)
    print(rendered[:800], file=sys.stderr)
PY
}
```

Then run the protected login flow:

```bash
set -euo pipefail

: "${GEOFLOW_BASE_URL:?Set GEOFLOW_BASE_URL to the GEOFlow web root}"

geoflow_login_request="$(mktemp)"
geoflow_login_response="$(mktemp)"
geoflow_login_error="$(mktemp)"
geoflow_auth_header="$(mktemp)"
chmod 600 "$geoflow_login_request" "$geoflow_login_response" "$geoflow_login_error" "$geoflow_auth_header"
trap 'rm -f "$geoflow_login_request" "$geoflow_login_response" "$geoflow_login_error" "$geoflow_auth_header"' EXIT

geoflow_login_policy="$(python3 - "$GEOFLOW_BASE_URL" <<'PY'
import ipaddress
import sys
from urllib.parse import urlsplit

value = sys.argv[1]
if "://" not in value:
    value = "https://" + value
try:
    parsed = urlsplit(value)
    _ = parsed.port
except ValueError as exc:
    raise SystemExit(f"Invalid GEOFLOW_BASE_URL: {exc}")
if parsed.scheme not in {"http", "https"} or not parsed.hostname:
    raise SystemExit("GEOFLOW_BASE_URL must be an http(s) URL with a hostname")
if parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment:
    raise SystemExit("GEOFLOW_BASE_URL must not contain credentials, query, or fragment")
if any(char.isspace() for char in value):
    raise SystemExit("GEOFLOW_BASE_URL must not contain whitespace")
if "{" in value or "}" in value:
    raise SystemExit("GEOFLOW_BASE_URL must not contain curl glob characters: { or }")

hostname = parsed.hostname.rstrip(".").lower()
is_loopback = hostname == "localhost" or hostname.endswith(".localhost")
if not is_loopback:
    try:
        is_loopback = ipaddress.ip_address(hostname).is_loopback
    except ValueError:
        pass
if parsed.scheme == "http" and not is_loopback:
    raise SystemExit("Authenticated API fallback requires HTTPS unless the host is loopback")

print(value.rstrip("/") + "\t" + ("=http,https" if is_loopback else "=https"))
PY
)"
geoflow_login_base_url="${geoflow_login_policy%%$'\t'*}"
geoflow_login_proto="${geoflow_login_policy#*$'\t'}"

python3 - "$geoflow_login_request" <<'PY'
import getpass
import json
import pathlib
import sys

with open("/dev/tty", "r", encoding="utf-8") as terminal_input, open(
    "/dev/tty", "w", encoding="utf-8"
) as terminal_output:
    terminal_output.write("Admin username: ")
    terminal_output.flush()
    username = terminal_input.readline().strip()
    password = getpass.getpass("Admin password: ", stream=terminal_output)
if not username or not password:
    raise SystemExit("Username and password are required")
pathlib.Path(sys.argv[1]).write_text(
    json.dumps({"username": username, "password": password}) + "\n",
    encoding="utf-8",
)
PY

if ! geoflow_login_status="$(geoflow_bounded_curl \
  "$geoflow_login_response" "$geoflow_login_error" 5242880 \
  curl --disable --globoff --proto "$geoflow_login_proto" \
  --silent --show-error --max-time 20 --max-filesize 5242880 \
  --request POST -H 'Accept: application/json' -H 'Content-Type: application/json' \
  --data-binary "@$geoflow_login_request" \
  "$geoflow_login_base_url/api/v1/auth/login")"; then
  geoflow_print_safe_files "$geoflow_login_response" "$geoflow_login_error"
  echo "Protected GEOFlow login transport failed." >&2
  exit 1
fi
if [[ ! "$geoflow_login_status" =~ ^2[0-9][0-9]$ ]]; then
  geoflow_print_safe_files "$geoflow_login_response" "$geoflow_login_error"
  echo "Protected GEOFlow login returned HTTP $geoflow_login_status" >&2
  exit 1
fi

python3 - "$geoflow_login_response" "$geoflow_auth_header" <<'PY'
import json
import pathlib
import sys

try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except (OSError, UnicodeDecodeError, json.JSONDecodeError):
    raise SystemExit("Login response is not valid JSON")
data = payload.get("data") if isinstance(payload, dict) else None
token = data.get("token") if isinstance(data, dict) else None
if not isinstance(payload, dict) or payload.get("success") is not True or not isinstance(token, str) or not token.strip():
    raise SystemExit("Login response did not contain a successful token result")
token = token.strip()
if any(ord(char) < 32 or ord(char) == 127 for char in token):
    raise SystemExit("Login response contained an invalid token")
pathlib.Path(sys.argv[2]).write_text(
    "Authorization: Bearer " + token + "\n",
    encoding="utf-8",
)
PY

rm -f "$geoflow_login_request" "$geoflow_login_response" "$geoflow_login_error"
```

The request, response, and curl-error files are deleted as soon as the protected header exists. Use `--header "@$geoflow_auth_header"` for later API calls in the same shell. The `EXIT` trap removes all protected files, including the header, when the shell exits. Do not print or persist the response body or header file.

## CLI 0.2.0 Command Reference

Global options are `--config PATH`, `--base-url URL`, `--token-stdin`, `--timeout SECONDS`, `--allow-insecure-http`, and `--no-interaction`. `-h` or `--help` prints help; `-V` or `--version` prints JSON version data. `-q` suppresses normal output, while repeated `-v` increases verbosity.

### Config, Login, And Catalog

| Command | Command-specific options |
|---|---|
| `config init` | `--base-url URL`, `--token-stdin`, `--file PATH`, `--force` |
| `config show` | none; use global `--config PATH` to select a profile |
| `login` | `--base-url URL`, `--username USER`, `--password-stdin`, `--file PATH`, `--force` |
| `catalog` | none |

Use `catalog` as the authenticated read before a mutation:

```bash
bin/geoflow --config /path/to/profile.json catalog
```

### Tasks And Jobs

| Command | Options |
|---|---|
| `task list` | `--page N`, `--per-page N`, `--status STATUS`, `--search TEXT` |
| `task create --json FILE` | `--idempotency-key KEY` |
| `task get TASK_ID` | none |
| `task update TASK_ID --json FILE` | `--idempotency-key KEY` |
| `task delete TASK_ID` | `--yes` |
| `task start TASK_ID` | `--enqueue-now`, `--idempotency-key KEY` |
| `task stop TASK_ID` | `--idempotency-key KEY` |
| `task enqueue TASK_ID` | `--job-type TYPE`, `--payload-json FILE`, `--idempotency-key KEY` |
| `task jobs TASK_ID` | `--status STATUS`, `--limit N` |
| `job get JOB_ID` | none |

Examples:

```bash
bin/geoflow --config /path/to/profile.json task list --status active --per-page 20
bin/geoflow --config /path/to/profile.json task create --json ./task.json --idempotency-key task-create-001
bin/geoflow --config /path/to/profile.json task update 12 --json ./task-patch.json --idempotency-key task-update-12
bin/geoflow --config /path/to/profile.json task start 12 --enqueue-now --idempotency-key task-start-12
bin/geoflow --config /path/to/profile.json task enqueue 12 --job-type generate --payload-json ./job.json --idempotency-key task-enqueue-12
bin/geoflow --config /path/to/profile.json task jobs 12 --limit 20
bin/geoflow --config /path/to/profile.json job get 88
bin/geoflow --config /path/to/profile.json task delete 12
```

Task create requires `name`, `title_library_id`, `prompt_id`, and `ai_model_id`. `knowledge_base_ids` accepts up to five IDs and takes precedence over legacy `knowledge_base_id`. API v1 does not bind `distribution_channel_ids`; use the authenticated admin task form for a concrete channel selection.

### Materials

Supported types are `categories`, `authors`, `keyword-libraries`, `title-libraries`, `image-libraries`, and `knowledge-bases`. Aliases are `keywords`, `titles`, `images`, and `knowledge`.

| Command | Options |
|---|---|
| `material summary` | none |
| `material list TYPE` | `--page N`, `--per-page N`, `--search TEXT` |
| `material create TYPE --json FILE` | `--idempotency-key KEY` |
| `material get TYPE ID` | none |
| `material update TYPE ID --json FILE` | `--idempotency-key KEY` |
| `material delete TYPE ID` | `--yes` |
| `material item-list TYPE ID` | `--page N`, `--per-page N` |
| `material item-create TYPE ID --json FILE` | `--idempotency-key KEY` |
| `material item-upload TYPE ID --image FILE` | `--idempotency-key KEY`; only `image-libraries` or `images` |
| `material item-delete TYPE ID` | exactly one of `--ids 1,2` or `--json FILE`, plus optional `--yes` |

Examples:

```bash
bin/geoflow --config /path/to/profile.json material summary
bin/geoflow --config /path/to/profile.json material list keyword-libraries --search geo --per-page 20
bin/geoflow --config /path/to/profile.json material create keyword-libraries --json ./library.json --idempotency-key library-create-001
bin/geoflow --config /path/to/profile.json material item-create keywords 12 --json ./keyword.json --idempotency-key keyword-create-001
bin/geoflow --config /path/to/profile.json material item-upload images 9 --image ./cover.png --idempotency-key image-upload-001
bin/geoflow --config /path/to/profile.json material item-delete titles 34 --ids 101,102
bin/geoflow --config /path/to/profile.json material delete keyword-libraries 12
```

Knowledge-base items are generated chunks and are read-only through item commands. Update the parent knowledge-base content to rebuild chunks.

### Articles

| Command | Options |
|---|---|
| `article list` | `--page N`, `--per-page N`, `--task-id ID`, `--status STATUS`, `--review-status STATUS`, `--author-id ID`, `--search TEXT` |
| `article create` | `--json FILE`, or direct fields listed below; `--idempotency-key KEY` |
| `article get ARTICLE_ID` | none |
| `article update ARTICLE_ID` | `--json FILE`, or direct fields listed below; `--idempotency-key KEY` |
| `article review ARTICLE_ID --status STATUS` | `--note TEXT`, `--risk-override-reason TEXT`, `--idempotency-key KEY` |
| `article publish ARTICLE_ID` | `--idempotency-key KEY` |
| `article trash ARTICLE_ID` | `--idempotency-key KEY` |

Article create and update share `--title`, `--excerpt`, `--slug`, `--keywords`, `--meta-description`, `--task-id`, `--author-id`, `--category-id`, `--content`, and `--content-file`. Create also accepts `--status`, `--review-status`, and `--ai-generated`; update rejects those workflow fields before sending a request. Use `article review`, `article publish`, or `article trash` for workflow transitions. Create requires a non-empty title and content. `--content` and `--content-file` are mutually exclusive. When `--json` is present, the JSON object supplies the body.

```bash
bin/geoflow --config /path/to/profile.json article list --task-id 12 --review-status pending --per-page 20
bin/geoflow --config /path/to/profile.json article create \
  --title "Article title" \
  --content-file ./article.md \
  --author-id 5 \
  --category-id 2 \
  --idempotency-key article-create-001
bin/geoflow --config /path/to/profile.json article update 101 --json ./article-patch.json --idempotency-key article-update-101
bin/geoflow --config /path/to/profile.json article review 101 --status approved --note "Reviewed" --idempotency-key article-review-101
bin/geoflow --config /path/to/profile.json article publish 101 --idempotency-key article-publish-101
bin/geoflow --config /path/to/profile.json article get 101
bin/geoflow --config /path/to/profile.json article trash 101 --idempotency-key article-trash-101
```

After publication, read the article again and use the persisted `/article/{slug}` URL. Do not report the legacy `article.php?id=...` compatibility form.

## JSON And Stdin

`--json FILE` and `--payload-json FILE` read a JSON object. Use `-` to read the object from stdin:

```bash
bin/geoflow --config /path/to/profile.json task create --json - --idempotency-key task-create-stdin
```

`--content-file -` reads article text from stdin. JSON and text inputs have a 5 MiB limit. Secret stdin flags read one line and have a 64 KiB limit. A process has one stdin stream, so do not combine `--token-stdin`, `--password-stdin`, `--json -`, `--payload-json -`, or `--content-file -` when two options would need to consume it.

## Deletion And Idempotency

`task delete`, `material delete`, and `material item-delete` ask for confirmation in an interactive terminal. A non-interactive run must include `--yes`. The flag confirms only the exact local CLI target; it does not bypass token scopes, server authorization, locks, impact checks, or admin-only confirmations.

DELETE operations do not use `X-Idempotency-Key`. This applies to task deletion, material deletion, and material item deletion in both CLI and API fallback. Use idempotency keys for the POST and PATCH operations that expose them, including creates, updates, task actions, article review/publish/trash, and image upload.

## API v1 Fallback

Use API fallback only when `bin/geoflow` is absent. Validate the base URL before attaching a bearer token. Authenticated non-loopback HTTP is blocked; use HTTPS for remote hosts.

First run the protected transport setup from the API-only login fallback above in the current Bash shell. Then validate the endpoint, create a protected header, and define a request wrapper. The wrapper keeps curl response and error data in mode-`0600` temporary files, enforces a streaming 5 MiB cap on every curl version, recursively redacts failure bodies, and writes a response to stdout only after it is a successful JSON envelope:

```bash
: "${GEOFLOW_BASE_URL:?Set GEOFLOW_BASE_URL to the GEOFlow web root}"
: "${GEOFLOW_API_TOKEN:?Set GEOFLOW_API_TOKEN}"

geoflow_api_policy="$(python3 - "$GEOFLOW_BASE_URL" <<'PY'
import ipaddress
import sys
from urllib.parse import urlsplit

value = sys.argv[1]
if "://" not in value:
    value = "https://" + value
try:
    parsed = urlsplit(value)
    _ = parsed.port
except ValueError as exc:
    raise SystemExit(f"Invalid GEOFLOW_BASE_URL: {exc}")
if parsed.scheme not in {"http", "https"} or not parsed.hostname:
    raise SystemExit("GEOFLOW_BASE_URL must be an http(s) URL with a hostname")
if parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment:
    raise SystemExit("GEOFLOW_BASE_URL must not contain credentials, query, or fragment")
if any(char.isspace() for char in value):
    raise SystemExit("GEOFLOW_BASE_URL must not contain whitespace")
if "{" in value or "}" in value:
    raise SystemExit("GEOFLOW_BASE_URL must not contain curl glob characters: { or }")

hostname = parsed.hostname.rstrip(".").lower()
is_loopback = hostname == "localhost" or hostname.endswith(".localhost")
if not is_loopback:
    try:
        is_loopback = ipaddress.ip_address(hostname).is_loopback
    except ValueError:
        pass
if parsed.scheme == "http" and not is_loopback:
    raise SystemExit("Authenticated API fallback requires HTTPS unless the host is loopback")

print(value.rstrip("/") + "\t" + ("=http,https" if is_loopback else "=https"))
PY
)"
geoflow_api_base_url="${geoflow_api_policy%%$'\t'*}"
geoflow_api_proto="${geoflow_api_policy#*$'\t'}"

geoflow_auth_header="$(mktemp)"
chmod 600 "$geoflow_auth_header"
python3 - "$geoflow_auth_header" <<'PY'
import os
import pathlib
import sys

token = os.environ.get("GEOFLOW_API_TOKEN", "").strip()
if not token:
    raise SystemExit("GEOFLOW_API_TOKEN must not be empty")
if any(ord(char) < 32 or ord(char) == 127 for char in token):
    raise SystemExit("GEOFLOW_API_TOKEN contains invalid control characters")
pathlib.Path(sys.argv[1]).write_text(
    "Authorization: Bearer " + token + "\n",
    encoding="utf-8",
)
PY
trap 'rm -f "$geoflow_auth_header"' EXIT

geoflow_api_request() (
  set -euo pipefail
  local geoflow_api_response geoflow_api_error geoflow_api_status
  geoflow_api_response="$(mktemp)"
  geoflow_api_error="$(mktemp)"
  chmod 600 "$geoflow_api_response" "$geoflow_api_error"
  trap 'rm -f "$geoflow_api_response" "$geoflow_api_error"' EXIT

  if ! geoflow_api_status="$(geoflow_bounded_curl \
    "$geoflow_api_response" "$geoflow_api_error" 5242880 \
    curl --disable --globoff --proto "$geoflow_api_proto" \
    --silent --show-error --max-time 20 --max-filesize 5242880 \
    "$@")"; then
    geoflow_print_safe_files "$geoflow_api_response" "$geoflow_api_error"
    echo "API fallback transport failed." >&2
    exit 1
  fi

  if [[ ! "$geoflow_api_status" =~ ^2[0-9][0-9]$ ]]; then
    geoflow_print_safe_files "$geoflow_api_response" "$geoflow_api_error"
    echo "API fallback returned HTTP $geoflow_api_status." >&2
    exit 1
  fi

  if ! python3 - "$geoflow_api_response" <<'PY'
import json
import pathlib
import sys

try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except (OSError, UnicodeDecodeError, json.JSONDecodeError):
    raise SystemExit(1)
if not isinstance(payload, dict) or payload.get("success") is not True:
    raise SystemExit(1)
PY
  then
    geoflow_print_safe_files "$geoflow_api_response" "$geoflow_api_error"
    echo "API fallback response was not a successful JSON envelope." >&2
    exit 1
  fi

  cat "$geoflow_api_response"
)
```

Every request must use the wrapper and `Accept: application/json`. Pass only request-specific curl options; the wrapper supplies the protocol policy, timeout, response limit, protected files, and redirect blocking:

```bash
geoflow_api_request \
  --header "@$geoflow_auth_header" \
  -H 'Accept: application/json' \
  "$geoflow_api_base_url/api/v1/catalog"
```

The endpoint validation selects HTTP only for a parsed loopback hostname. Never infer loopback safety from a string prefix alone. Do not pass curl output, header-dump, include, write-out, or redirect options to `geoflow_api_request`; its bounded transport rejects those overrides.

API v1 maps directly to the CLI operations:

| Area | API paths |
|---|---|
| Auth | `POST /api/v1/auth/login` |
| Catalog | `GET /api/v1/catalog` |
| Tasks | `GET|POST /api/v1/tasks`, `GET|PATCH|DELETE /api/v1/tasks/{id}`, `POST /start`, `POST /stop`, `POST /enqueue`, `GET /jobs` |
| Jobs | `GET /api/v1/jobs/{id}` |
| Materials | `GET /api/v1/materials`, typed `GET|POST`, typed item `GET|POST|DELETE`, typed parent `GET|PATCH|DELETE` |
| Articles | `GET|POST /api/v1/articles`, `GET|PATCH /api/v1/articles/{id}`, `POST /review`, `POST /publish`, `POST /trash` |

Example task create:

```bash
geoflow_api_request --request POST \
  --header "@$geoflow_auth_header" \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'X-Idempotency-Key: task-create-001' \
  --data-binary @./task.json \
  "$geoflow_api_base_url/api/v1/tasks"
```

Example task delete, with no idempotency header:

```bash
geoflow_api_request --request DELETE \
  --header "@$geoflow_auth_header" \
  -H 'Accept: application/json' \
  "$geoflow_api_base_url/api/v1/tasks/12"
```

An HTML response usually means the base URL, proxy, or Laravel routing is wrong. Classify `401` as invalid or expired credentials, `403` as a missing scope, `423` as a locked resource, and `429` as rate limiting. Preserve the JSON error code and `retry_after` value in the diagnosis without printing secrets.

## Admin Web Boundary

The admin prefix comes from `ADMIN_BASE_PATH` or `geoflow.admin_base_path`; the repository default is `geo_admin`. Replace `{admin}` below with the target deployment's inspected prefix. Admin writes require a current session cookie, CSRF token, route-specific form data, and any super-admin or current-password checks.

Current admin-only groups include:

- Analytics pages: `GET /{admin}/analytics`, `/content`, `/traffic`, `/ai-visibility`, `/leads`, and super-admin `/distribution`.
- Manual publication workbench: routes under `/{admin}/manual-publications`, with super-admin settings for personas and account references.
- Article editor assistance and risk checks: `GET /{admin}/articles/editor/titles`, `POST /{admin}/articles/editor/generate`, and `POST /{admin}/articles/{articleId}/risk-scan`.
- AI source providers: routes under `/{admin}/ai-source-providers`, including provider and model-binding tests.
- Distribution channels: routes under `/{admin}/distribution`, including the preview, prepare, cancel, and final channel-deletion flow.
- Homepage module editor: `GET /{admin}/site-settings/homepage-modules`, with POST update, preset, and import routes.
- Enterprise knowledge, lead forms and leads, URL import, system updates, theme replication, site/security settings, admin users, activity logs, and API-token management.

The current route file has no live Theme Editor route. Report it as unavailable unless route discovery on the target deployment finds an explicit route. See [geoflow-current-capability-map.md](geoflow-current-capability-map.md) and [operation-boundary.md](operation-boundary.md) for authorization and risk boundaries.
