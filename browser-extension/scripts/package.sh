#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
extension_dir="$(cd "${script_dir}/.." && pwd)"
workspace_dir="$(cd "${extension_dir}/.." && pwd)"
output_dir="${1:-${workspace_dir}/dist/browser-extension}"
version="$(php -r '$m=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); echo $m["version"];' "${extension_dir}/manifest.json")"
temporary_dir="$(mktemp -d)"
archive_path="${temporary_dir}/geoflow-chrome-operator-${version}.zip"

trap 'rm -rf "${temporary_dir}"' EXIT
mkdir -p "${output_dir}"

(
    cd "${extension_dir}"
    zip -qr "${archive_path}" manifest.json src _locales icons PRIVACY.md README.md STORE_LISTING.md
)

mv "${archive_path}" "${output_dir}/geoflow-chrome-operator-${version}.zip"
printf '%s\n' "${output_dir}/geoflow-chrome-operator-${version}.zip"
