#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_CLI_COMMAND="${WP_CLI_BIN:-wp}"
MODE="write"
TEMP_DIR=""

usage() {
    cat <<'USAGE'
Usage:
  bin/generate-pot.sh [output.pot]
  bin/generate-pot.sh --check [committed.pot]

Generate the Cybermaps translation template, or verify that the committed
template matches a fresh WP-CLI generation after removing volatile headers.
USAGE
}

cleanup() {
    if [ -n "${TEMP_DIR}" ] && [ -d "${TEMP_DIR}" ]; then
        rm -rf "${TEMP_DIR}"
    fi
}
trap cleanup EXIT

case "${1:-}" in
    --check)
        MODE="check"
        shift
        ;;
    --help|-h)
        usage
        exit 0
        ;;
esac

if [ "$#" -gt 1 ]; then
    usage >&2
    exit 2
fi

OUTPUT_PATH="${1:-${PROJECT_DIR}/languages/cybermaps.pot}"

if ! command -v "${WP_CLI_COMMAND}" >/dev/null 2>&1 && [ ! -x "${WP_CLI_COMMAND}" ]; then
    echo "ERROR: WP-CLI is required to generate the translation template." >&2
    exit 1
fi

generate_pot() {
    local destination="$1"
    local allow_root_args=()

    if [ "${WP_CLI_ALLOW_ROOT:-0}" = "1" ] || [ "${COMPOSER_ALLOW_SUPERUSER:-0}" = "1" ]; then
        allow_root_args=( --allow-root )
    fi

    "${WP_CLI_COMMAND}" i18n make-pot \
        "${PROJECT_DIR}" \
        "${destination}" \
        --slug=cybermaps \
        --domain=cybermaps \
        --include='cybermaps.php,uninstall.php,src,assets/js' \
        --exclude='clean,freemius,vendor,tests,docs' \
        --file-comment=$'Copyright (C) 2026 Aleksandr Oreshkin\nThis file is distributed under the GPLv2 or later.' \
        --headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/cybermaps"}' \
        "${allow_root_args[@]}"
}

normalize_pot() {
    local source="$1"
    local destination="$2"

    sed -E '/^"(POT-Creation-Date|X-Generator):/d' "${source}" > "${destination}"
}

if [ "${MODE}" = "write" ]; then
    mkdir -p "$(dirname "${OUTPUT_PATH}")"
    generate_pot "${OUTPUT_PATH}"
    exit 0
fi

if [ ! -f "${OUTPUT_PATH}" ]; then
    echo "ERROR: Translation template does not exist: ${OUTPUT_PATH}" >&2
    exit 1
fi

for required_command in diff mktemp sed; do
    if ! command -v "${required_command}" >/dev/null 2>&1; then
        echo "ERROR: Required POT validation command is unavailable: ${required_command}" >&2
        exit 1
    fi
done

TEMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cybermaps-pot.XXXXXX")"
GENERATED_PATH="${TEMP_DIR}/cybermaps.pot"
EXPECTED_NORMALIZED="${TEMP_DIR}/expected.pot"
GENERATED_NORMALIZED="${TEMP_DIR}/generated.pot"

generate_pot "${GENERATED_PATH}"
normalize_pot "${OUTPUT_PATH}" "${EXPECTED_NORMALIZED}"
normalize_pot "${GENERATED_PATH}" "${GENERATED_NORMALIZED}"

if ! diff -u "${EXPECTED_NORMALIZED}" "${GENERATED_NORMALIZED}"; then
    echo "ERROR: ${OUTPUT_PATH} is stale; run bin/generate-pot.sh and commit the result." >&2
    exit 1
fi

echo "Translation template is current: ${OUTPUT_PATH}"
