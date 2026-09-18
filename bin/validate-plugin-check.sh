#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTIFACT_DIR="${1:-${PROJECT_DIR}/clean/cybermaps}"
ARCHIVE_PATH="${2:-}"
REPORT_PATH="${3:-${PROJECT_DIR}/clean/plugin-check-validation.json}"
PLUGIN_CHECK_VERSION="2.0.0"

fail() {
	echo "Plugin Check validation failed: $1" >&2
	exit 1
}

for required_command in docker git grep php python3 realpath seq sleep tr; do
	command -v "${required_command}" >/dev/null 2>&1 || fail "required command is unavailable: ${required_command}"
done

CONTAINER_CLI="docker"
if docker --version 2>&1 | grep -i podman >/dev/null && command -v podman >/dev/null 2>&1; then
	CONTAINER_CLI="podman"
fi
[ -d "${ARTIFACT_DIR}" ] || fail "artifact directory does not exist: ${ARTIFACT_DIR}"
[ -f "${ARCHIVE_PATH}" ] || fail "release ZIP does not exist: ${ARCHIVE_PATH}"
[ -f "${PROJECT_DIR}/tests/integration/wporg-smoke.php" ] || fail "smoke test is missing"
ARTIFACT_DIR="$(realpath "${ARTIFACT_DIR}")"
ARCHIVE_PATH="$(realpath "${ARCHIVE_PATH}")"
REPORT_PATH="$(realpath -m "${REPORT_PATH}")"
WORDPRESS_VERSION="$(
	php -r '
		$main = file_get_contents($argv[1]);
		if (
			! is_string($main)
			|| ! preg_match("/^[ \\t*]*Requires at least:[ \\t]*([0-9]+\\.[0-9]+)[ \\t]*$/mi", $main, $match)
		) {
			fwrite(STDERR, "Plugin Check validation failed: could not derive WordPress version from built artifact\\n");
			exit(1);
		}
		echo $match[1];
	' "${ARTIFACT_DIR}/cybermaps.php"
)"

RUNTIME_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cybermaps-wporg.XXXXXX")"
RUNTIME_ID="cybermaps-wporg-${RANDOM}-$$"
NETWORK_NAME="${RUNTIME_ID}-network"
DATABASE_NAME="${RUNTIME_ID}-db"
WORDPRESS_IMAGE="docker.io/library/wordpress:cli-php8.2"
DATABASE_IMAGE="docker.io/library/mariadb:10.11"

cleanup() {
	"${CONTAINER_CLI}" rm -f "${DATABASE_NAME}" >/dev/null 2>&1 || true
	"${CONTAINER_CLI}" network rm "${NETWORK_NAME}" >/dev/null 2>&1 || true
	if [[ "${RUNTIME_DIR}" == "${TMPDIR:-/tmp}/cybermaps-wporg."* ]]; then
		rm -rf -- "${RUNTIME_DIR}"
	fi
}
trap cleanup EXIT

mkdir -p "${RUNTIME_DIR}/wordpress" "$(dirname "${REPORT_PATH}")"
chmod 0777 "${RUNTIME_DIR}/wordpress"
"${CONTAINER_CLI}" network create "${NETWORK_NAME}" >/dev/null
"${CONTAINER_CLI}" run -d --name "${DATABASE_NAME}" --network "${NETWORK_NAME}" \
	-e MARIADB_DATABASE=wordpress \
	-e MARIADB_USER=wordpress \
	-e MARIADB_PASSWORD=wordpress \
	-e MARIADB_ROOT_PASSWORD=wordpress \
	"${DATABASE_IMAGE}" >/dev/null

database_ready=0
for _attempt in $(seq 1 60); do
	if "${CONTAINER_CLI}" exec "${DATABASE_NAME}" mariadb-admin ping -h 127.0.0.1 -uroot -pwordpress --silent >/dev/null 2>&1; then
		database_ready=1
		break
	fi
	sleep 1
done
[ "${database_ready}" -eq 1 ] || fail "disposable database did not become ready"

wp_cli() {
	"${CONTAINER_CLI}" run --rm --network "${NETWORK_NAME}" --user 0:0 \
		-v "${RUNTIME_DIR}/wordpress:/var/www/html" \
		-v "$(dirname "${ARCHIVE_PATH}"):/artifacts:ro" \
		-v "${PROJECT_DIR}/tests/integration:/validation:ro" \
		-w /var/www/html \
		"${WORDPRESS_IMAGE}" php -d memory_limit=512M /usr/local/bin/wp "$@" --allow-root
}

wp_cli core download --version="${WORDPRESS_VERSION}" --force --quiet
wp_cli config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost="${DATABASE_NAME}" --skip-check --quiet
wp_cli config set WP_DEBUG true --raw --quiet
wp_cli config set WP_DEBUG_DISPLAY false --raw --quiet
wp_cli config set WP_DEBUG_LOG true --raw --quiet
wp_cli core install --url=http://cybermaps.test --title=Cybermaps --admin_user=admin --admin_password=cybermaps-validation --admin_email=admin@example.com --skip-email --quiet
wp_cli plugin install "/artifacts/$(basename "${ARCHIVE_PATH}")" --force --quiet
wp_cli plugin install plugin-check --version="${PLUGIN_CHECK_VERSION}" --activate --force --quiet
installed_plugin_check="$(wp_cli plugin get plugin-check --field=version | tr -d '\r')"
[ "${installed_plugin_check}" = "${PLUGIN_CHECK_VERSION}" ] || fail "Plugin Check version is ${installed_plugin_check}, expected ${PLUGIN_CHECK_VERSION}"
wp_cli plugin activate cybermaps --quiet
wp_cli eval-file /validation/wporg-smoke.php > "${RUNTIME_DIR}/smoke.txt"

run_plugin_check() {
	local output_path="$1"
	shift
	set +e
	wp_cli plugin check cybermaps --mode=new --format=json --require=/var/www/html/wp-content/plugins/plugin-check/cli.php "$@" > "${output_path}" 2> "${output_path}.stderr"
	local status="$?"
	set -e
	python3 - "${output_path}" "${output_path}.stderr" "${status}" <<'PY'
import json
import re
import sys
from pathlib import Path

output_path = Path(sys.argv[1])
stderr_path = Path(sys.argv[2])
status = int(sys.argv[3])
stdout = output_path.read_text()
stderr = stderr_path.read_text().strip()
if stdout.strip() == "Success: Checks complete. No errors found.":
    report = []
elif stdout.strip():
    try:
        report = json.loads(stdout)
    except json.JSONDecodeError as error:
        blocks = re.findall(r"^FILE: (.+)\n(\[.*?\])(?=\n\nFILE: |\s*\Z)", stdout, re.MULTILINE | re.DOTALL)
        if not blocks:
            raise SystemExit(
                f"Plugin Check did not return parseable JSON: {error}; "
                f"stdout={stdout[:12000]!r}; stderr={stderr!r}"
            )
        report = []
        for filename, encoded_findings in blocks:
            try:
                file_findings = json.loads(encoded_findings)
            except json.JSONDecodeError as block_error:
                raise SystemExit(
                    f"Plugin Check returned invalid JSON for {filename}: {block_error}"
                )
            for finding in file_findings:
                if isinstance(finding, dict):
                    finding = {"file": filename, **finding}
                report.append(finding)
else:
    report = []

if isinstance(report, list):
    findings = report
elif isinstance(report, dict):
    findings = report.get("results", report.get("errors", []))
    if not isinstance(findings, list):
        findings = [report] if report else []
else:
    findings = [report]

output_path.write_text(json.dumps(report, indent=2) + "\n")

if status != 0 or findings or stderr:
    raise SystemExit(
        f"Plugin Check reported status={status}, findings={findings!r}, stderr={stderr!r}"
    )
PY
}

run_plugin_check "${RUNTIME_DIR}/plugin-check-new.json"
run_plugin_check "${RUNTIME_DIR}/plugin-check-experimental.json" --include-experimental

if [ -s "${RUNTIME_DIR}/wordpress/wp-content/debug.log" ]; then
	cat "${RUNTIME_DIR}/wordpress/wp-content/debug.log" >&2
	fail "WordPress emitted runtime warnings or notices"
fi

COMMIT="$(git -C "${PROJECT_DIR}" rev-parse HEAD)"
ZIP_SHA256="$(php -r '$hash=hash_file("sha256",$argv[1]); if(!is_string($hash)){exit(1);} echo $hash;' "${ARCHIVE_PATH}")"
python3 - "${REPORT_PATH}" "${COMMIT}" "${ZIP_SHA256}" "${WORDPRESS_VERSION}" "${PLUGIN_CHECK_VERSION}" \
	"${RUNTIME_DIR}/plugin-check-new.json" "${RUNTIME_DIR}/plugin-check-experimental.json" "${RUNTIME_DIR}/smoke.txt" <<'PY'
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

report_path = Path(sys.argv[1])
report = {
    "validated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
    "commit": sys.argv[2],
    "zip_sha256": sys.argv[3],
    "wordpress_version": sys.argv[4],
    "php_version": "8.2",
    "plugin_check_version": sys.argv[5],
    "mode": "new",
    "stable_findings": json.loads(Path(sys.argv[6]).read_text()),
    "experimental_findings": json.loads(Path(sys.argv[7]).read_text()),
    "smoke": Path(sys.argv[8]).read_text().strip(),
    "wp_debug_clean": True,
}
report_path.write_text(json.dumps(report, indent=2) + "\n")
PY

echo "Plugin Check ${PLUGIN_CHECK_VERSION} and WordPress ${WORDPRESS_VERSION} validation passed: ${REPORT_PATH}"
