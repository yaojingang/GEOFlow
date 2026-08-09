#!/usr/bin/env bash
# Copyright © 2026 姚金刚. All rights reserved.
# Project: geoflow
# Created by: 姚金刚
# Date: 2026-05-16
# X: https://x.com/yaojingang

set -euo pipefail

workspace="${1:-}"
config_path="${2:-}"
preflight_checks="${3:-${GEOFLOW_PREFLIGHT_CHECKS:-catalog}}"

if [[ -z "$workspace" ]]; then
  echo "Usage: geoflow_preflight.sh <workspace> [config] [checks]" >&2
  exit 1
fi

if [[ ! -d "$workspace" ]]; then
  echo "Workspace not found: $workspace" >&2
  exit 1
fi

cli_path="$workspace/bin/geoflow"

api_base_url="${GEOFLOW_BASE_URL:-}"
api_token="${GEOFLOW_API_TOKEN:-}"
admin_path="${GEOFLOW_ADMIN_PATH:-/geo_admin}"
tmp_files=()

cleanup() {
  if (( ${#tmp_files[@]} > 0 )); then
    rm -f "${tmp_files[@]}"
  fi
}

trap cleanup EXIT

run_bounded_curl() {
  local output_path="$1"
  local stderr_path="$2"
  local max_bytes="$3"
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
try:
    max_bytes = int(sys.argv[3])
except ValueError:
    raise SystemExit("Invalid bounded curl byte limit")
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
    path_stat = path.lstat()
    if not stat.S_ISREG(path_stat.st_mode):
        raise SystemExit(f"Bounded curl output must be a regular file: {path}")

header_handle = tempfile.NamedTemporaryFile(prefix="geoflow-curl-headers-", delete=False)
header_path = pathlib.Path(header_handle.name)
header_handle.close()
os.chmod(header_path, 0o600)
command = [command[0], "--dump-header", str(header_path), *command[1:]]
process = None

try:
    with output_path.open("wb") as output_handle, stderr_path.open("wb") as error_handle:
        try:
            process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=error_handle)
        except OSError as exc:
            raise SystemExit(f"Could not start curl: {exc}") from exc
        assert process.stdout is not None
        written = 0
        exceeded = False
        while True:
            chunk = process.stdout.read(65536)
            if not chunk:
                break
            remaining = max_bytes - written
            if len(chunk) > remaining:
                if remaining > 0:
                    output_handle.write(chunk[:remaining])
                    written += remaining
                exceeded = True
                break
            output_handle.write(chunk)
            written += len(chunk)

        if exceeded:
            process.stdout.close()
            process.terminate()
            try:
                process.wait(timeout=2)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
            print(f"Response exceeded {max_bytes} bytes; curl was stopped.", file=sys.stderr)
            raise SystemExit(63)

        process.stdout.close()
        return_code = process.wait()
        if return_code != 0:
            raise SystemExit(return_code)

    header_bytes = header_path.read_bytes()
    statuses = re.findall(rb"(?mi)^HTTP/[^\s]+\s+(\d{3})\b", header_bytes)
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

docker_hint() {
  if [[ -f "$workspace/docker-compose.yml" || -f "$workspace/compose.yml" ]]; then
    cat >&2 <<'EOF'
Docker Compose workspace detected. For Laravel API fallback:
  1. confirm containers are running: docker compose ps
  2. confirm API routes: docker compose exec app php artisan route:list --path=api/v1
  3. set GEOFLOW_BASE_URL to the exposed web root, e.g. http://127.0.0.1:18080
  4. set GEOFLOW_API_TOKEN to a token with the needed catalog/tasks/articles/jobs/materials scopes
EOF
  fi
}

normalize_json_response() {
  python3 - "$1" <<'PY'
import json
import os
import pathlib
import re
import sys
import unicodedata

path = pathlib.Path(sys.argv[1])
try:
    payload = json.loads(path.read_text(encoding="utf-8", errors="strict"))
except (UnicodeDecodeError, json.JSONDecodeError):
    raise SystemExit(1)

sensitive_fragments = ("authorization", "password", "secret", "token", "api_key", "api-key", "apikey")
environment_secrets = [
    value
    for name in ("GEOFLOW_TOKEN", "GEOFLOW_API_TOKEN")
    if (value := os.environ.get(name))
]

def sanitize_text(value):
    value = "".join(char for char in value if unicodedata.category(char) not in {"Cc", "Cf"})
    for secret in environment_secrets:
        value = value.replace(secret, "[redacted]")
    value = re.sub(
        r"(?i)\bAuthorization\s*[:=]\s*Bearer\s+[^\s,;<>&]+",
        "Authorization: Bearer [redacted]",
        value,
    )
    value = re.sub(r"(?i)\bBearer\s+[^\s,;<>&]+", "Bearer [redacted]", value)
    value = re.sub(
        r'''(?i)\b(authorization|password|secret|token|api[_-]?key|apikey)\b(\s*[:=]\s*)(?:"[^"\r\n]*"|'[^'\r\n]*'|[^\s,;<>&]+)''',
        lambda match: match.group(1) + match.group(2) + "[redacted]",
        value,
    )
    return value

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

path.write_text(json.dumps(sanitize(payload), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY
}

print_body_excerpt() {
  python3 - "$1" <<'PY'
import json
import os
import pathlib
import re
import sys
import unicodedata

text = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
sensitive_fragments = ("authorization", "password", "secret", "token", "api_key", "api-key", "apikey")
environment_secrets = [
    value
    for name in ("GEOFLOW_TOKEN", "GEOFLOW_API_TOKEN")
    if (value := os.environ.get(name))
]

def sanitize_text(value):
    value = "".join(char for char in value if unicodedata.category(char) not in {"Cc", "Cf"})
    for secret in environment_secrets:
        value = value.replace(secret, "[redacted]")
    value = re.sub(
        r"(?i)\bAuthorization\s*[:=]\s*Bearer\s+[^\s,;<>&]+",
        "Authorization: Bearer [redacted]",
        value,
    )
    value = re.sub(r"(?i)\bBearer\s+[^\s,;<>&]+", "Bearer [redacted]", value)
    value = re.sub(
        r'''(?i)\b(authorization|password|secret|token|api[_-]?key|apikey)\b(\s*[:=]\s*)(?:"[^"\r\n]*"|'[^'\r\n]*'|[^\s,;<>&]+)''',
        lambda match: match.group(1) + match.group(2) + "[redacted]",
        value,
    )
    return value

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

try:
    text = json.dumps(sanitize(json.loads(text)), ensure_ascii=False, indent=2)
except json.JSONDecodeError:
    sensitive_key = r'(?:authorization|password|secret|token|api[_-]?key|apikey)'
    text = re.sub(
        rf'((?:["\']?{sensitive_key}["\']?)\s*[:=]\s*)(["\'])(.*?)(\2)',
        r'\1\2[redacted]\2',
        text,
        flags=re.I | re.S,
    )
    text = re.sub(
        rf'((?:["\']?{sensitive_key}["\']?)\s*[:=]\s*)([^\s,;<>&]+)',
        r'\1[redacted]',
        text,
        flags=re.I,
    )
    text = re.sub(
        rf'([?&]{sensitive_key}=)[^&#\s]+',
        r'\1[redacted]',
        text,
        flags=re.I,
    )
    text = sanitize_text(text)
text = re.sub(r'(name=["\']_token["\'][^>]*value=["\'])[^"\']+', r'\1[redacted]', text, flags=re.I)
text = re.sub(r'(value=["\'])[A-Za-z0-9]{20,}(["\'])', r'\1[redacted]\2', text)
text = re.sub(r'(Authorization\s*:\s*Bearer\s+)[^\s<]+', r'\1[redacted]', text, flags=re.I)
text = "".join(
    char for char in text
    if char in "\n\t" or unicodedata.category(char) not in {"Cc", "Cf"}
)
print(text[:800])
PY
}

print_admin_summary() {
  python3 - "$1" <<'PY'
import pathlib
import re
import sys
import unicodedata

text = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
title_match = re.search(r"<title[^>]*>(.*?)</title>", text, re.I | re.S)
title = re.sub(r"\s+", " ", title_match.group(1)).strip() if title_match else "(missing title)"
title = "".join(char for char in title if unicodedata.category(char) not in {"Cc", "Cf"})
has_form = bool(re.search(r"<form\b", text, re.I))
has_csrf = bool(re.search(r'name=["\']_token["\']', text, re.I))

print(f"Admin page title: {title}")
print(f"Admin login form: {'present' if has_form else 'missing'}")
print(f"CSRF field: {'present' if has_csrf else 'missing'}")
PY
}

validate_base_url() {
  python3 - "$1" "$2" <<'PY'
import ipaddress
import sys
from urllib.parse import urlsplit

value = sys.argv[1]
token_required = sys.argv[2] == "1"
try:
    parsed = urlsplit(value)
    _ = parsed.port
except ValueError as exc:
    print(f"Invalid GEOFLOW_BASE_URL: {exc}", file=sys.stderr)
    raise SystemExit(1)

if parsed.scheme not in {"http", "https"} or not parsed.hostname:
    print("GEOFLOW_BASE_URL must be an http(s) URL with a hostname.", file=sys.stderr)
    raise SystemExit(1)
if parsed.username is not None or parsed.password is not None:
    print("GEOFLOW_BASE_URL must not contain credentials.", file=sys.stderr)
    raise SystemExit(1)
if parsed.query or parsed.fragment:
    print("GEOFLOW_BASE_URL must not contain a query string or fragment.", file=sys.stderr)
    raise SystemExit(1)
if any(char.isspace() for char in value):
    print("GEOFLOW_BASE_URL must not contain whitespace.", file=sys.stderr)
    raise SystemExit(1)
if "{" in value or "}" in value:
    print("GEOFLOW_BASE_URL must not contain curl glob characters: { or }.", file=sys.stderr)
    raise SystemExit(1)

hostname = parsed.hostname.rstrip(".").lower()
is_loopback = hostname == "localhost" or hostname.endswith(".localhost")
if not is_loopback:
    try:
        is_loopback = ipaddress.ip_address(hostname).is_loopback
    except ValueError:
        pass
if token_required and parsed.scheme != "https" and not is_loopback:
    print("Authenticated GEOFlow API preflight requires HTTPS unless the host is loopback.", file=sys.stderr)
    raise SystemExit(1)
PY
}

validate_admin_path() {
  if [[ "$admin_path" != /* || "$admin_path" == *$'\n'* || "$admin_path" == *$'\r'* || "$admin_path" == *'?'* || "$admin_path" == *'#'* || "$admin_path" == *'{'* || "$admin_path" == *'}'* ]]; then
    echo "GEOFLOW_ADMIN_PATH must be a plain absolute URL path without query, fragment, or curl glob braces." >&2
    return 1
  fi
}

json_success_is_true() {
  python3 - "$1" <<'PY'
import json
import pathlib
import sys

try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except (OSError, UnicodeDecodeError, json.JSONDecodeError):
    raise SystemExit(1)
raise SystemExit(0 if isinstance(payload, dict) and payload.get("success") is True else 1)
PY
}

json_field() {
  python3 - "$1" "$2" <<'PY'
import json
import pathlib
import sys

try:
    payload = json.loads(pathlib.Path(sys.argv[1]).read_text(encoding="utf-8"))
except (OSError, UnicodeDecodeError, json.JSONDecodeError):
    raise SystemExit(1)
value = payload.get(sys.argv[2]) if isinstance(payload, dict) else None
if isinstance(value, (str, int, float)):
    print(value)
PY
}

print_safe_files() {
  local path
  for path in "$@"; do
    if [[ -s "$path" ]]; then
      print_body_excerpt "$path" >&2 || true
    fi
  done
}

print_cli_failure_diagnostic() {
  local check="$1"
  local stdout_path="$2"
  local stderr_path="$3"
  local login_force_hint="$4"

  print_safe_files "$stdout_path" "$stderr_path"
  if grep -Eqi 'HTTP[^0-9]*401|"status"[[:space:]]*:[[:space:]]*401|token-invalid|invalid token|unauthorized|未授权|无效或已过期' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: authentication failed (HTTP 401). Refresh the saved token with: ${login_force_hint}" >&2
  elif grep -Eqi 'HTTP[^0-9]*403|"status"[[:space:]]*:[[:space:]]*403|forbidden|缺少所需 API scope|缺少所需.*scope' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: the token is authenticated but lacks the required scope (HTTP 403). Reissue a least-privilege token in the admin API-token page, then log in again." >&2
  elif grep -Eqi 'HTTP[^0-9]*423|"status"[[:space:]]*:[[:space:]]*423|资源已锁定|resource[^[:alpha:]]+locked' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: the remote resource is locked (HTTP 423). Inspect its current workflow state and retry after the lock is released." >&2
  elif grep -Eqi 'HTTP[^0-9]*429|"status"[[:space:]]*:[[:space:]]*429|too many requests|请求过于频繁|rate.?limit' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: the server rate limit was reached (HTTP 429). Respect retry_after when present and retry after that delay." >&2
  elif grep -Eqi '远程 HTTP|allow-insecure-http|insecure HTTP|HTTPS.*loopback|remote HTTP' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: the CLI blocked authenticated remote HTTP. Use HTTPS. For an explicitly approved test host, save or pass --allow-insecure-http; TLS verification remains enabled for HTTPS." >&2
  elif grep -Eqi 'credential_binding|同次显式凭证|不能继承配置文件 token|隐式 cwd endpoint|endpoint.*credential|endpoint.*token' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: endpoint and credential sources are not safely bound. Select a trusted profile with --config, or pair an explicit --base-url with --token-stdin in the same invocation." >&2
  elif grep -Eqi '缺少 base_url|缺少 token|config|配置文件' "$stdout_path" "$stderr_path"; then
    echo "Preflight failed for $check: CLI configuration is incomplete or unreadable. Run a hidden-prompt login or config init before retrying." >&2
  else
    echo "Preflight failed for $check. Inspect the redacted CLI error above before changing credentials or retrying." >&2
  fi
}

run_admin_check() {
  local base_url="$1"
  local check_tmp curl_error_tmp http_status check_url check_output response_size
  local max_response_bytes=5242880

  if [[ -z "$base_url" ]]; then
    echo "Preflight failed. Admin check needs GEOFLOW_BASE_URL or a CLI config containing base_url." >&2
    return 3
  fi
  validate_base_url "$base_url" 0 || return 1
  validate_admin_path || return 1

  check_url="${base_url%/}${admin_path%/}/login"
  check_tmp="$(mktemp)"
  curl_error_tmp="$(mktemp)"
  tmp_files+=("$check_tmp" "$curl_error_tmp")
  chmod 600 "$check_tmp" "$curl_error_tmp"
  if ! http_status="$(run_bounded_curl "$check_tmp" "$curl_error_tmp" "$max_response_bytes" \
    curl --disable --globoff --proto '=http,https' --silent --show-error --max-time 20 --max-filesize "$max_response_bytes" \
    --header 'Accept: text/html,application/xhtml+xml' --url "$check_url")"; then
    print_safe_files "$check_tmp" "$curl_error_tmp"
    echo "Preflight failed. Could not reach the admin login page: $check_url" >&2
    return 3
  fi
  response_size="$(wc -c < "$check_tmp" | tr -d '[:space:]')"
  if [[ ! "$response_size" =~ ^[0-9]+$ || "$response_size" -gt "$max_response_bytes" ]]; then
    echo "Preflight failed. Admin response exceeded ${max_response_bytes} bytes: $check_url" >&2
    return 3
  fi
  if [[ ! "$http_status" =~ ^2[0-9][0-9]$ ]]; then
    print_safe_files "$check_tmp" "$curl_error_tmp"
    echo "Preflight failed. Admin login page returned HTTP $http_status: $check_url" >&2
    return 3
  fi

  check_output="$(cat "$check_tmp")"
  if ! printf '%s' "$check_output" | grep -Eqi '<form|login|csrf|password|admin'; then
    print_body_excerpt "$check_tmp" >&2
    echo "Preflight failed. Admin web check did not look like a login/admin page: $check_url" >&2
    return 3
  fi

  echo "Preflight OK: $check_url"
  print_admin_summary "$check_tmp"
}

if [[ ! -f "$cli_path" ]]; then
  if [[ -f "$workspace/artisan" && -f "$workspace/routes/api.php" ]]; then
    needs_api_token=0
    IFS=',' read -r -a initial_check_names <<< "$preflight_checks"
    for raw_check in "${initial_check_names[@]}"; do
      check="$(printf '%s' "$raw_check" | tr -d '[:space:]')"
      case "$check" in
        ""|admin|admin-login)
          ;;
        *)
          needs_api_token=1
          ;;
      esac
    done

    if [[ -n "$api_base_url" ]] && ! validate_base_url "$api_base_url" "$needs_api_token"; then
      exit 1
    fi
    validate_admin_path || exit 1

    if [[ -z "$api_base_url" || ( "$needs_api_token" -eq 1 && -z "$api_token" ) ]]; then
      echo "Missing CLI: $cli_path" >&2
      echo "Laravel GEOFlow detected. Set GEOFLOW_BASE_URL for admin checks and also GEOFLOW_API_TOKEN for API v1 fallback checks." >&2
      docker_hint
      exit 1
    fi

    auth_header_tmp=""
    if [[ "$needs_api_token" -eq 1 ]]; then
      if [[ "$api_token" == *$'\n'* || "$api_token" == *$'\r'* ]]; then
        echo "GEOFLOW_API_TOKEN must be a single-line value." >&2
        exit 1
      fi
      auth_header_tmp="$(mktemp)"
      tmp_files+=("$auth_header_tmp")
      chmod 600 "$auth_header_tmp"
      printf 'Authorization: Bearer %s\n' "$api_token" > "$auth_header_tmp"
    fi
    IFS=',' read -r -a check_names <<< "$preflight_checks"

    ran_check=0
    for raw_check in "${check_names[@]}"; do
      check="$(printf '%s' "$raw_check" | tr -d '[:space:]')"
      [[ -z "$check" ]] && continue
      ran_check=1

      case "$check" in
        catalog)
          endpoint_path="/api/v1/catalog"
          expected_json=1
          use_auth=1
          ;;
        materials|material)
          endpoint_path="/api/v1/materials"
          expected_json=1
          use_auth=1
          ;;
        tasks|task)
          endpoint_path="/api/v1/tasks?per_page=1"
          expected_json=1
          use_auth=1
          ;;
        articles|article)
          endpoint_path="/api/v1/articles?per_page=1"
          expected_json=1
          use_auth=1
          ;;
        admin|admin-login)
          endpoint_path="${admin_path%/}/login"
          expected_json=0
          use_auth=0
          ;;
        *)
          echo "Unsupported preflight check: $check" >&2
          echo "Supported checks: catalog, materials, tasks, articles, admin" >&2
          exit 1
          ;;
      esac

      check_url="${api_base_url%/}${endpoint_path}"
      check_tmp="$(mktemp)"
      curl_error_tmp="$(mktemp)"
      tmp_files+=("$check_tmp" "$curl_error_tmp")
      chmod 600 "$check_tmp" "$curl_error_tmp"
      max_response_bytes=5242880
      curl_args=(--disable --globoff --proto '=http,https' --silent --show-error --max-time 20 --max-filesize "$max_response_bytes" --header "Accept: application/json")
      if [[ "$use_auth" -eq 1 ]]; then
        curl_args+=(--header "@$auth_header_tmp")
      fi
      if ! http_status="$(run_bounded_curl "$check_tmp" "$curl_error_tmp" "$max_response_bytes" curl "${curl_args[@]}" --url "$check_url")"; then
        print_safe_files "$check_tmp" "$curl_error_tmp"
        echo "Preflight failed. Could not reach endpoint: $check_url" >&2
        exit 3
      fi
      response_size="$(wc -c < "$check_tmp" | tr -d '[:space:]')"
      if [[ ! "$response_size" =~ ^[0-9]+$ || "$response_size" -gt "$max_response_bytes" ]]; then
        echo "Preflight failed. Endpoint response exceeded ${max_response_bytes} bytes: $check_url" >&2
        exit 3
      fi
      check_output="$(cat "$check_tmp")"

      if [[ ! "$http_status" =~ ^2[0-9][0-9]$ ]]; then
        print_safe_files "$check_tmp" "$curl_error_tmp"
        case "$http_status" in
          401)
            echo "Preflight failed. API token is invalid or expired (HTTP 401). Refresh it through geoflow login or the protected API login flow." >&2
            ;;
          403)
            echo "Preflight failed. API token lacks the scope required by this endpoint (HTTP 403). Reissue a least-privilege token with the required scope." >&2
            ;;
          423)
            echo "Preflight failed. The requested resource is locked (HTTP 423). Inspect its workflow state before retrying." >&2
            ;;
          429)
            echo "Preflight failed. The API rate limit was reached (HTTP 429). Respect retry_after when present before retrying." >&2
            ;;
          *)
            echo "Preflight failed. Endpoint returned HTTP $http_status: $check_url" >&2
            ;;
        esac
        exit 3
      fi

      if [[ "$expected_json" -eq 1 ]] && ! normalize_json_response "$check_tmp"; then
        print_body_excerpt "$check_tmp" >&2
        echo "Preflight failed. API fallback returned invalid JSON. Check that GEOFLOW_BASE_URL points to the GEOFlow public web root and that /api/v1 routes are routed to Laravel API, not a proxy/login/HTML page." >&2
        docker_hint
        exit 3
      fi
      check_output="$(cat "$check_tmp")"

      if [[ "$expected_json" -eq 1 ]] && ! json_success_is_true "$check_tmp"; then
        print_body_excerpt "$check_tmp" >&2
        echo "Preflight failed. API fallback returned a JSON error envelope for: $check_url" >&2
        exit 3
      fi

      if [[ "$expected_json" -eq 0 ]] && ! printf '%s' "$check_output" | grep -Eqi '<form|login|csrf|password|admin'; then
        print_body_excerpt "$check_tmp" >&2
        echo "Preflight failed. Admin web check did not look like a login/admin page: $check_url" >&2
        exit 3
      fi

      echo "Preflight OK: $check_url"
      if [[ "$expected_json" -eq 0 ]]; then
        print_admin_summary "$check_tmp"
      fi
    done

    if [[ "$ran_check" -eq 0 ]]; then
      echo "Preflight failed. No valid API fallback checks requested." >&2
      exit 1
    fi
    exit 0
  fi

  echo "Missing CLI: $cli_path" >&2
  exit 1
fi

if [[ -x "$cli_path" ]]; then
  runner=("$cli_path")
else
  runner=(php "$cli_path")
fi

format_shell_command() {
  local argument quoted formatted=""
  for argument in "$@"; do
    printf -v quoted '%q' "$argument"
    formatted+="${formatted:+ }${quoted}"
  done
  printf '%s' "$formatted"
}

login_args=("${runner[@]}")
if [[ -n "$config_path" ]]; then
  login_args+=(--config "$config_path")
fi
login_args+=(login --force)
login_hint="$(format_shell_command "${login_args[@]}")"
login_force_hint="$login_hint"

run_cli() {
  if [[ -n "$config_path" ]]; then
    "${runner[@]}" --config "$config_path" "$@"
  else
    "${runner[@]}" "$@"
  fi
}

version_stdout="$(mktemp)"
version_stderr="$(mktemp)"
tmp_files+=("$version_stdout" "$version_stderr")
if ! run_cli --version >"$version_stdout" 2>"$version_stderr"; then
  print_cli_failure_diagnostic "--version" "$version_stdout" "$version_stderr" "$login_force_hint"
  exit 2
fi
if ! normalize_json_response "$version_stdout"; then
  print_safe_files "$version_stdout" "$version_stderr"
  echo "Preflight failed. CLI --version did not return a JSON object." >&2
  exit 2
fi
cli_version="$(json_field "$version_stdout" version || true)"
if [[ -z "$cli_version" ]]; then
  echo "Preflight failed. CLI --version did not include a version value." >&2
  exit 2
fi
echo "Preflight OK: GEOFlow CLI $cli_version"

help_stdout="$(mktemp)"
help_stderr="$(mktemp)"
tmp_files+=("$help_stdout" "$help_stderr")
if ! run_cli --help >"$help_stdout" 2>"$help_stderr"; then
  print_cli_failure_diagnostic "--help" "$help_stdout" "$help_stderr" "$login_force_hint"
  exit 2
fi
for signature in 'geoflow catalog' 'geoflow material summary' 'geoflow task list' 'geoflow article list'; do
  if ! grep -Fq "$signature" "$help_stdout"; then
    print_safe_files "$help_stderr"
    echo "Preflight failed. CLI --help is missing required command: $signature" >&2
    exit 2
  fi
done
echo "Preflight OK: CLI help and required read commands"

config_stdout="$(mktemp)"
config_stderr="$(mktemp)"
tmp_files+=("$config_stdout" "$config_stderr")
if ! run_cli config show >"$config_stdout" 2>"$config_stderr"; then
  print_cli_failure_diagnostic "config show" "$config_stdout" "$config_stderr" "$login_force_hint"
  echo "Run after correcting the config target: ${login_hint}" >&2
  exit 2
fi
if ! normalize_json_response "$config_stdout"; then
  print_safe_files "$config_stdout" "$config_stderr"
  echo "Preflight failed. CLI config show did not return valid JSON." >&2
  exit 2
fi
print_safe_files "$config_stderr"
config_base_url="$(json_field "$config_stdout" base_url || true)"
echo "Preflight OK: CLI configuration is readable"

IFS=',' read -r -a check_names <<< "$preflight_checks"
ran_check=0
for raw_check in "${check_names[@]}"; do
  check="$(printf '%s' "$raw_check" | tr -d '[:space:]')"
  [[ -z "$check" ]] && continue
  ran_check=1

  case "$check" in
    catalog)
      cli_check=(catalog)
      ;;
    materials|material)
      cli_check=(material summary)
      ;;
    tasks|task)
      cli_check=(task list --per-page 1)
      ;;
    articles|article)
      cli_check=(article list --per-page 1)
      ;;
    admin|admin-login)
      run_admin_check "${api_base_url:-$config_base_url}" || exit $?
      continue
      ;;
    *)
      echo "Unsupported preflight check: $check" >&2
      echo "Supported checks: catalog, materials, tasks, articles, admin" >&2
      exit 1
      ;;
  esac

  check_stdout="$(mktemp)"
  check_stderr="$(mktemp)"
  tmp_files+=("$check_stdout" "$check_stderr")
  if ! run_cli "${cli_check[@]}" >"$check_stdout" 2>"$check_stderr"; then
    print_cli_failure_diagnostic "$check" "$check_stdout" "$check_stderr" "$login_force_hint"
    exit 3
  fi
  if ! normalize_json_response "$check_stdout" || ! json_success_is_true "$check_stdout"; then
    print_safe_files "$check_stdout" "$check_stderr"
    echo "Preflight failed for $check: CLI returned an invalid or unsuccessful JSON envelope." >&2
    exit 3
  fi
  print_safe_files "$check_stderr"
  echo "Preflight OK: CLI ${cli_check[*]}"
done

if [[ "$ran_check" -eq 0 ]]; then
  echo "Preflight failed. No valid CLI checks requested." >&2
  exit 1
fi
