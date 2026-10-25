#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: geoflow-deploy.sh <install|enroll|update|status|rollback> [updater options]
  install --instance primary --root /opt/geoflow --url https://geo.example
  enroll --instance-id primary --instance-root /opt/geoflow
  update --instance primary --dry-run --json
  update --instance primary --plan-sha256 HASH [--allow-maintenance]
  status --instance primary --json
  rollback --application --instance primary
  rollback --data --instance primary --recovery-point POINT
Requires geoflow-updater installed through its verified packaging/scripts/install.sh bootstrap.
USAGE
}

command_name=${1:-help}
if [[ "$command_name" == help || "$command_name" == --help || "$command_name" == -h ]]; then
    usage
    exit 0
fi
shift
if ! command -v geoflow-updater >/dev/null 2>&1; then
    printf '%s\n' 'Install geoflow-updater with its verified packaging/scripts/install.sh bootstrap first.' >&2
    exit 1
fi
case "$command_name" in
    install|enroll|update) exec geoflow-updater "$command_name" "$@" ;;
    status) exec geoflow-updater doctor "$@" ;;
    rollback)
        mode=${1:-}
        [[ $# -gt 0 ]] && shift
        case "$mode" in
            --application) exec geoflow-updater switch-back "$@" ;;
            --data)
                found_point=false
                for argument in "$@"; do
                    case "$argument" in --recovery-point|--recovery-point=*) found_point=true ;; esac
                done
                if [[ "$found_point" != true ]]; then
                    printf '%s\n' 'Data recovery requires an explicit --recovery-point POINT.' >&2
                    exit 2
                fi
                exec geoflow-updater rollback "$@"
                ;;
            *) printf '%s\n' 'Choose rollback --application or rollback --data --recovery-point POINT.' >&2; exit 2 ;;
        esac
        ;;
    *) usage >&2; exit 2 ;;
esac
