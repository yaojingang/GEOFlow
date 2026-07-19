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
admin_path="${GEOFLOW_ADMIN_PATH:-/admin}"

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
import pathlib
import sys
import unicodedata

path = pathlib.Path(sys.argv[1])
try:
    payload = json.loads(path.read_text(encoding="utf-8", errors="strict"))
except (UnicodeDecodeError, json.JSONDecodeError):
    raise SystemExit(1)

sensitive_fragments = ("authorization", "password", "secret", "token", "api_key", "apikey")

def sanitize(value, key=""):
    if any(fragment in key.lower() for fragment in sensitive_fragments):
        return "[redacted]"
    if isinstance(value, str):
        return "".join(char for char in value if unicodedata.category(char) not in {"Cc", "Cf"})
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
import pathlib
import re
import sys
import unicodedata

text = pathlib.Path(sys.argv[1]).read_text(encoding="utf-8", errors="replace")
sensitive_fragments = ("authorization", "password", "secret", "token", "api_key", "apikey")

def sanitize(value, key=""):
    if any(fragment in key.lower() for fragment in sensitive_fragments):
        return "[redacted]"
    if isinstance(value, str):
        return "".join(char for char in value if unicodedata.category(char) not in {"Cc", "Cf"})
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
    if [[ "$admin_path" != /* || "$admin_path" == *$'\n'* || "$admin_path" == *$'\r'* || "$admin_path" == *'?'* || "$admin_path" == *'#'* ]]; then
      echo "GEOFLOW_ADMIN_PATH must be a plain absolute URL path without query or fragment." >&2
      exit 1
    fi

    if [[ -z "$api_base_url" || ( "$needs_api_token" -eq 1 && -z "$api_token" ) ]]; then
      echo "Missing CLI: $cli_path" >&2
      echo "Laravel GEOFlow detected. Set GEOFLOW_BASE_URL for admin checks and also GEOFLOW_API_TOKEN for API v1 fallback checks." >&2
      docker_hint
      exit 1
    fi

    tmp_files=()
    trap 'rm -f "${tmp_files[@]}"' EXIT
    auth_header_tmp=""
    if [[ "$needs_api_token" -eq 1 ]]; then
      if [[ "$api_token" == *$'\n'* || "$api_token" == *$'\r'* ]]; then
        echo "GEOFLOW_API_TOKEN must be a single-line value." >&2
        exit 1
      fi
      auth_header_tmp="$(mktemp)"
      chmod 600 "$auth_header_tmp"
      printf 'Authorization: Bearer %s\n' "$api_token" > "$auth_header_tmp"
      tmp_files+=("$auth_header_tmp")
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
      tmp_files+=("$check_tmp")
      max_response_bytes=5242880
      curl_args=(--disable --proto '=http,https' --silent --show-error --max-time 20 --max-filesize "$max_response_bytes" --header "Accept: application/json")
      if [[ "$use_auth" -eq 1 ]]; then
        curl_args+=(--header "@$auth_header_tmp")
      fi
      if ! http_status="$(curl "${curl_args[@]}" --output "$check_tmp" --write-out '%{http_code}' --url "$check_url")"; then
        print_body_excerpt "$check_tmp" >&2 || true
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
        print_body_excerpt "$check_tmp" >&2
        echo "Preflight failed. Endpoint returned HTTP $http_status: $check_url" >&2
        exit 3
      fi

      if [[ "$expected_json" -eq 1 ]] && ! normalize_json_response "$check_tmp"; then
        print_body_excerpt "$check_tmp" >&2
        echo "Preflight failed. API fallback returned invalid JSON. Check that GEOFLOW_BASE_URL points to the GEOFlow public web root and that /api/v1 routes are routed to Laravel API, not a proxy/login/HTML page." >&2
        docker_hint
        exit 3
      fi
      check_output="$(cat "$check_tmp")"

      if [[ "$expected_json" -eq 1 ]] && printf '%s' "$check_output" | grep -Eqi '"success"[[:space:]]*:[[:space:]]*false|token-invalid|invalid token|401|403|unauthorized|forbidden|未授权|无效或已过期'; then
        printf '%s\n' "$check_output" >&2
        echo "Preflight failed. API fallback token authentication or scope check failed for: $check_url" >&2
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
      else
        printf '%s\n' "$check_output"
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

config_hint=""
if [[ -n "$config_path" ]]; then
  printf -v config_hint ' --config %q' "$config_path"
fi

login_hint="${runner[*]}${config_hint} login --base-url <url> --username <admin>"
login_force_hint="${runner[*]}${config_hint} login --base-url <url> --username <admin> --force"

run_cli() {
  if [[ -n "$config_path" ]]; then
    "${runner[@]}" --config "$config_path" "$@"
  else
    "${runner[@]}" "$@"
  fi
}

if ! config_output="$(run_cli config show 2>&1)"; then
  printf '%s\n' "$config_output" >&2
  echo "Preflight failed. Could not read config. Run: ${login_hint}" >&2
  exit 2
fi

printf '%s\n' "$config_output"

if ! catalog_output="$(run_cli catalog 2>&1)"; then
  printf '%s\n' "$catalog_output" >&2

  if printf '%s' "$catalog_output" | grep -Eqi 'token-invalid|invalid token|token[^[:alpha:]]*(expired|invalid)|401|403|unauthorized|forbidden|未授权|无效或已过期'; then
    echo "Preflight failed. Config exists but token authentication failed. Run: ${login_force_hint}" >&2
  else
    echo "Preflight failed. Authenticated API access failed for a reason other than an obvious token problem. Inspect the error above before retrying login." >&2
  fi
  exit 3
fi

printf '%s\n' "$catalog_output"
