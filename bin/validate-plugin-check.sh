#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/workspace.sh"

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASE_DIR="$(python3 -B "${PROJECT_DIR}/bin/workspace.py" release-dir)"
ARTIFACT_DIR="${1:-${RELEASE_DIR}/cybermaps}"
ARCHIVE_PATH="${2:-}"
REPORT_PATH="${3:-${RELEASE_DIR}/plugin-check-validation.json}"
PLUGIN_CHECK_VERSION="$(python3 -B -c 'import json,sys; print(json.load(open(sys.argv[1]))["plugin_check_version"])' "${PROJECT_DIR}/docs/dev/release-policy.json")"
PHP_VERSION="${CYBERMAPS_TEST_PHP:-8.2}"
CLI_TOOLS="${PROJECT_DIR}/docs/generated/tmp/wp-cli-modern"

fail() {
	echo "Plugin Check validation failed: $1" >&2
	exit 1
}

for required_command in cmp docker git grep php python3 realpath seq sleep tr; do
	command -v "${required_command}" >/dev/null 2>&1 || fail "required command is unavailable: ${required_command}"
done

CONTAINER_CLI="docker"
if docker --version 2>&1 | grep -i podman >/dev/null && command -v podman >/dev/null 2>&1; then
	CONTAINER_CLI="podman"
fi
[ -f "${CLI_TOOLS}/vendor/wp-cli/wp-cli/php/boot-fs.php" ] || fail "pinned WP-CLI tools missing; run composer validation:setup"
cmp -s "${PROJECT_DIR}/tests/cli/composer.lock" "${CLI_TOOLS}/composer.lock" || fail "WP-CLI lock differs; run composer validation:setup"
[ -d "${ARTIFACT_DIR}" ] || fail "artifact directory does not exist: ${ARTIFACT_DIR}"
[ -f "${ARCHIVE_PATH}" ] || fail "release ZIP does not exist: ${ARCHIVE_PATH}"
[ -f "${PROJECT_DIR}/tests/integration/wporg-smoke.php" ] || fail "smoke test is missing"
ARTIFACT_DIR="$(realpath "${ARTIFACT_DIR}")"
ARCHIVE_PATH="$(realpath "${ARCHIVE_PATH}")"
python3 -B "${PROJECT_DIR}/bin/workspace.py" check-generated "${REPORT_PATH}"
WORDPRESS_VERSION="${CYBERMAPS_TEST_WP:-$(
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
)}"

FROZEN_COMMIT="$(git -C "${PROJECT_DIR}" rev-parse HEAD)"
FROZEN_SHA256="$(sha256sum "${ARCHIVE_PATH}" | cut -d " " -f 1)"
rm -f "${REPORT_PATH}"
EVIDENCE_DIR="${REPORT_PATH%.json}.evidence"
mkdir -p "${EVIDENCE_DIR}"
RUNTIME_DIR="$(mktemp -d "${TMPDIR}/cybermaps-wporg.XXXXXX")"
RUNTIME_ID="cybermaps-wporg-${RANDOM}-$$"
NETWORK_NAME="${RUNTIME_ID}-network"
DATABASE_NAME="${RUNTIME_ID}-db"
HTTP_NAME="${RUNTIME_ID}-http"
WORDPRESS_IMAGE="docker.io/library/wordpress:cli-php${PHP_VERSION}"
DATABASE_IMAGE="docker.io/library/mariadb:10.11"

cleanup() {
	local exit_status="$?"
	cp -f "${RUNTIME_DIR}"/*.txt "${RUNTIME_DIR}"/*.json "${RUNTIME_DIR}"/*.stderr "${EVIDENCE_DIR}/" 2>/dev/null || true
	cp -f "${RUNTIME_DIR}/wordpress/wp-content/debug.log" "${EVIDENCE_DIR}/debug.log" 2>/dev/null || true
	printf '%s\n' "${exit_status}" > "${EVIDENCE_DIR}/exit-status.txt"
	"${CONTAINER_CLI}" rm -f "${HTTP_NAME}" >/dev/null 2>&1 || true
	"${CONTAINER_CLI}" rm -f "${DATABASE_NAME}" >/dev/null 2>&1 || true
	"${CONTAINER_CLI}" network rm "${NETWORK_NAME}" >/dev/null 2>&1 || true
	if [[ "${RUNTIME_DIR}" == "${TMPDIR}/cybermaps-wporg."* ]]; then
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
	"${CONTAINER_CLI}" run --rm -i --network "${NETWORK_NAME}" --user 0:0 \
		-v "${CLI_TOOLS}:/cli-tools:ro" \
		-v "${RUNTIME_DIR}/wordpress:/var/www/html" \
		-v "$(dirname "${ARCHIVE_PATH}"):/artifacts:ro" \
		-v "${PROJECT_DIR}/tests/integration:/validation:ro" \
		-v "${PROJECT_DIR}/tests/Audit/fixtures:/audit-validation:ro" \
		-v "${PROJECT_DIR}/docs/generated/releases:/history:ro" \
		-w /var/www/html \
		"${WORDPRESS_IMAGE}" php -d memory_limit=512M /cli-tools/vendor/wp-cli/wp-cli/php/boot-fs.php "$@" --allow-root
}

wp_cli core download --version="${WORDPRESS_VERSION}" --force --quiet
wp_cli config create --dbname=wordpress --dbuser=wordpress --dbpass=wordpress --dbhost="${DATABASE_NAME}" --skip-check --quiet
wp_cli config set WP_DEBUG true --raw --quiet
wp_cli config set WP_DEBUG_DISPLAY false --raw --quiet
wp_cli config set WP_DEBUG_LOG true --raw --quiet
wp_cli core install --url=http://cybermaps.test --title=Cybermaps --admin_user=admin --admin_password=cybermaps-validation --admin_email=admin@example.com --skip-email --quiet
if [ "${CYBERMAPS_TEST_UPGRADE:-0}" = "1" ]; then
	[ -f "${PROJECT_DIR}/docs/generated/releases/7.5.3/cybermaps_7.5.3.zip" ] || fail "Upgrade fixture 7.5.3 is missing"
	[ "$(sha256sum "${PROJECT_DIR}/docs/generated/releases/7.5.3/cybermaps_7.5.3.zip" | cut -d " " -f 1)" = "b63a959a22a5595891af9374f8481c4e8dfaefbe5b55c1d4bd5f2cce29ddc950" ] || fail "Upgrade fixture checksum changed"
	wp_cli plugin install /history/7.5.3/cybermaps_7.5.3.zip --activate --force --quiet
	wp_cli option update cybermaps_upgrade_fixture preserved --quiet
	[ -f "${PROJECT_DIR}/docs/generated/releases/7.5.4/cybermaps_7.5.4.zip" ] || fail "Upgrade fixture 7.5.4 is missing"
	[ "$(sha256sum "${PROJECT_DIR}/docs/generated/releases/7.5.4/cybermaps_7.5.4.zip" | cut -d " " -f 1)" = "c8bce5305a8b2613ee4f1b3b8506a778ec7165f03d4f153c92e34c1e73f1f5f0" ] || fail "Upgrade fixture 7.5.4 checksum changed"
	wp_cli plugin install /history/7.5.4/cybermaps_7.5.4.zip --activate --force --quiet
	wp_cli eval-file /validation/wporg-mcp-upgrade.php

fi
wp_cli plugin install "/artifacts/$(basename "${ARCHIVE_PATH}")" --force --quiet
wp_cli plugin install plugin-check --version="${PLUGIN_CHECK_VERSION}" --activate --force --quiet
installed_plugin_check="$(wp_cli plugin get plugin-check --field=version | tr -d '\r')"
[ "${installed_plugin_check}" = "${PLUGIN_CHECK_VERSION}" ] || fail "Plugin Check version is ${installed_plugin_check}, expected ${PLUGIN_CHECK_VERSION}"
wp_cli plugin activate cybermaps --quiet
wp_cli eval-file /validation/wporg-smoke.php > "${RUNTIME_DIR}/smoke.txt"
wp_cli eval-file /validation/wporg-sitemap-regressions.php >> "${RUNTIME_DIR}/smoke.txt"
wp_cli eval 'define( "CYBERMAPS_DISPOSABLE_REST_FIXTURE", true ); require "/validation/rest-representation.php";' > "${RUNTIME_DIR}/rest-representation.json"
wp_cli eval 'putenv( "CYBERMAPS_CONFIGURATION_FIXTURE=1" ); require "/validation/configuration-cas.php";' > "${RUNTIME_DIR}/configuration-cas.json"
wp_cli eval 'putenv( "CYBERMAPS_CONFIGURATION_FIXTURE=1" ); require "/validation/upgrade-configuration-cas.php";' > "${RUNTIME_DIR}/upgrade-configuration-cas.json"
wp_cli eval 'putenv( "CYBERMAPS_CLOUDFLARE_FIXTURE=1" ); require "/validation/cloudflare-persistence.php";' > "${RUNTIME_DIR}/cloudflare-persistence.json"
wp_cli eval 'putenv( "CYBERMAPS_AUDIT_LOCK_FIXTURE=1" ); require "/audit-validation/native-run-lock.php";' > "${RUNTIME_DIR}/audit-run-lock.json"
wp_cli eval 'putenv( "CYBERMAPS_STATE_FIXTURE_DISPOSABLE=1" ); require "/validation/wporg-static-ownership.php";' > "${RUNTIME_DIR}/static-ownership.json"
wp_cli eval 'putenv( "CYBERMAPS_STATE_FIXTURE_DISPOSABLE=1" ); require "/validation/wporg-state-cutover.php";' > "${RUNTIME_DIR}/state-cutover.json"
if [ "${CYBERMAPS_TEST_UPGRADE:-0}" = "1" ]; then
	wp_cli eval 'require "/validation/wporg-mcp-upgrade-check.php";' >> "${RUNTIME_DIR}/smoke.txt"
fi

# Serve the same installed ZIP over loopback for actual request/response checks.
"${CONTAINER_CLI}" run -d --name "${HTTP_NAME}" --network "${NETWORK_NAME}" --user 0:0 \
	-p 127.0.0.1::8080 \
	-v "${RUNTIME_DIR}/wordpress:/var/www/html" \
	-v "${PROJECT_DIR}/tests/integration:/validation:ro" \
	-w /var/www/html "${WORDPRESS_IMAGE}" \
	php -d memory_limit=512M -S 0.0.0.0:8080 /validation/wporg-http-router.php >/dev/null
HTTP_ADDRESS="$("${CONTAINER_CLI}" port "${HTTP_NAME}" 8080/tcp)"
wp_cli option update home "http://${HTTP_ADDRESS}" --quiet
wp_cli option update siteurl "http://${HTTP_ADDRESS}" --quiet
python3 -B "${PROJECT_DIR}/tests/integration/wporg-http.py" "http://${HTTP_ADDRESS}" >> "${RUNTIME_DIR}/smoke.txt"
if [ -n "${CYBERMAPS_BROWSER_REPORT:-}" ]; then
	node "${PROJECT_DIR}/tests/browser/release-smoke.mjs" "http://${HTTP_ADDRESS}" "${CYBERMAPS_BROWSER_REPORT}"
fi

run_plugin_check() {
	local output_path="$1"
	shift
	set +e
	wp_cli plugin check cybermaps --mode=new --format=strict-json --include-low-severity-errors --include-low-severity-warnings --require=/var/www/html/wp-content/plugins/plugin-check/cli.php "$@" > "${output_path}" 2> "${output_path}.stderr"
	local status="$?"
	set -e
	python3 -B "${PROJECT_DIR}/bin/plugin_check_result.py" "${output_path}" "${output_path}.stderr" "${status}"
}


wp_cli eval 'putenv( "CYBERMAPS_STATE_FIXTURE_DISPOSABLE=1" ); require "/validation/wporg-mcp-preferences.php";' >> "${RUNTIME_DIR}/smoke.txt"

# Optional MCP integration is tested using the official plugin, never a protocol mock.
MCP_ADAPTER_VERSION="$(python3 -B -c 'import json,sys; print(json.load(open(sys.argv[1]))["mcp_adapter_version"])' "${PROJECT_DIR}/docs/dev/release-policy.json")"
mkdir -p "${RUNTIME_DIR}/wordpress/wp-content/mu-plugins"
cp "${PROJECT_DIR}/tests/integration/wporg-mcp-fixture.php" "${RUNTIME_DIR}/wordpress/wp-content/mu-plugins/cybermaps-mcp-test.php"
wp_cli plugin install mcp-adapter --version="${MCP_ADAPTER_VERSION}" --activate --quiet
wp_cli eval 'require "/validation/wporg-mcp-setup.php";' > "${RUNTIME_DIR}/mcp-credentials.tmp"
python3 -B "${PROJECT_DIR}/tests/integration/wporg-mcp.py" "http://${HTTP_ADDRESS}" "${RUNTIME_DIR}/mcp-credentials.tmp" >> "${RUNTIME_DIR}/smoke.txt"
wp_cli mcp-adapter serve --server=cybermaps --user=mcp-reader < "${PROJECT_DIR}/tests/integration/wporg-mcp-stdio.jsonl" > "${RUNTIME_DIR}/mcp-stdio.jsonl" 2> "${RUNTIME_DIR}/mcp-stdio.stderr"
python3 -B "${PROJECT_DIR}/tests/integration/wporg-mcp-stdio.py" "${RUNTIME_DIR}/mcp-stdio.jsonl" >> "${RUNTIME_DIR}/smoke.txt"
wp_cli eval 'if (get_option("cybermaps_test_hostile_called", false)) { throw new RuntimeException("Hostile ability ran."); }' >> "${RUNTIME_DIR}/smoke.txt"
wp_cli plugin deactivate mcp-adapter --quiet
wp_cli eval 'if (\Cybermaps\MCP\WordPressIntegration::is_enabled() || \Cybermaps\Discovery\MCPServerCard::is_available()) { throw new RuntimeException("Disabled adapter still advertised."); }' >> "${RUNTIME_DIR}/smoke.txt"
rm "${RUNTIME_DIR}/wordpress/wp-content/mu-plugins/cybermaps-mcp-test.php" "${RUNTIME_DIR}/mcp-credentials.tmp"

run_plugin_check "${RUNTIME_DIR}/plugin-check-new.json"
run_plugin_check "${RUNTIME_DIR}/plugin-check-experimental.json" --include-experimental

# Plugin Check 2.1.0 bootstraps wp_install() before WP_Rewrite exists on multisite.
# Keep both runtime scanner modes, then test the same installation as a network.
if [ "${CYBERMAPS_TEST_MULTISITE:-0}" = "1" ]; then
	wp_cli option update home http://cybermaps.test --quiet
	wp_cli option update siteurl http://cybermaps.test --quiet
	wp_cli core multisite-convert --quiet
	wp_cli plugin activate mcp-adapter --network --quiet
	wp_cli eval 'require "/validation/wporg-multisite.php";' >> "${RUNTIME_DIR}/smoke.txt"
fi
wp_cli eval 'putenv( "CYBERMAPS_PRIMING_PROBE=1" ); putenv( "CYBERMAPS_PRIMING_PROBE_SEED=1" ); require "/validation/publication-cache-priming.php";' > "${RUNTIME_DIR}/publication-cache-priming.json"
python3 -B "${PROJECT_DIR}/bin/validate_matrix.py" --verify-priming "${RUNTIME_DIR}/publication-cache-priming.json"
wp_cli eval 'require "/validation/wporg-lifecycle.php";'  >> "${RUNTIME_DIR}/smoke.txt"

COMMIT="$(git -C "${PROJECT_DIR}" rev-parse HEAD)"
[ "${COMMIT}" = "${FROZEN_COMMIT}" ] || fail "Source changed during validation"
ZIP_SHA256="$(php -r '$hash=hash_file("sha256",$argv[1]); if(!is_string($hash)){exit(1);} echo $hash;' "${ARCHIVE_PATH}")"
[ "${ZIP_SHA256}" = "${FROZEN_SHA256}" ] || fail "ZIP changed during validation"
ACTUAL_PHP="$(wp_cli eval 'echo PHP_VERSION;')"
ACTUAL_WP="$(wp_cli core version)"
if [ -s "${RUNTIME_DIR}/wordpress/wp-content/debug.log" ]; then
	cat "${RUNTIME_DIR}/wordpress/wp-content/debug.log" >&2
	fail "WordPress emitted runtime warnings or notices"
fi

python3 - "${REPORT_PATH}" "${COMMIT}" "${ZIP_SHA256}" "${WORDPRESS_VERSION}" "${PLUGIN_CHECK_VERSION}" \
	"${RUNTIME_DIR}/plugin-check-new.json" "${RUNTIME_DIR}/plugin-check-experimental.json" "${RUNTIME_DIR}/smoke.txt" "${ACTUAL_PHP}" "${ACTUAL_WP}" "${CYBERMAPS_TEST_MULTISITE:-0}" "${MCP_ADAPTER_VERSION}" <<'PY'
import json
import sys
from datetime import datetime, timezone
from pathlib import Path

report_path = Path(sys.argv[1])
report = {
    "validated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
    "commit": sys.argv[2],
    "zip_sha256": sys.argv[3],
    "wordpress_version": sys.argv[10],
    "php_version": sys.argv[9],
    "plugin_check_version": sys.argv[5],
    "plugin_check_environment": "single-site",
    "mode": "new",
    "stable_findings": [],
    "experimental_findings": [],
    "smoke": Path(sys.argv[8]).read_text().strip(),
    "wp_debug_clean": True,
    "lifecycle_passed": True,
    "sitemap_regressions_passed": True,
    "rest_representation_passed": True,
    "publication_priming_passed": True,
    "configuration_persistence_passed": True,
    "cloudflare_persistence_passed": True,
    "audit_run_lock_passed": True,
    "static_ownership_passed": True,
    "state_cutover_passed": True,
    "mcp_read_only_passed": True,
    "mcp_adapter_version": sys.argv[12],
    "multisite": sys.argv[11] == "1",
}
report_path.write_text(json.dumps(report, indent=2) + "\n")
PY

echo "Plugin Check ${PLUGIN_CHECK_VERSION} and WordPress ${WORDPRESS_VERSION} validation passed: ${REPORT_PATH}"
