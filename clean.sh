#!/usr/bin/env bash
set -euo pipefail

# clean.sh — Produce and validate the WordPress.org Cybermaps artifact.
# Usage: ./clean.sh
# Output: ./clean/cybermaps/, ./clean/cybermaps_<version>.zip, and its SHA-256.

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTPUT_PARENT="${PLUGIN_DIR}/clean"
OUTPUT_DIR="${PLUGIN_DIR}/clean/cybermaps"
LEGACY_ARCHIVE_PATH="${OUTPUT_PARENT}/cybermaps.zip"

for required_command in basename cmp cp cut diff du find grep mkdir php python3 rg rm sort wc zip; do
	if ! command -v "${required_command}" >/dev/null 2>&1; then
		echo "ERROR: Required packaging command is unavailable: ${required_command}" >&2
		exit 1
    fi
done

PLUGIN_VERSION="$(
    php -r '
        $main = file_get_contents($argv[1]);
        if (
            ! is_string($main)
            || ! preg_match("/^[ \t*]*Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)[ \t]*$/mi", $main, $match)
        ) {
            fwrite(STDERR, "ERROR: Could not read the plugin version from cybermaps.php\n");
            exit(1);
        }
        echo $match[1];
    ' "${PLUGIN_DIR}/cybermaps.php"
)"
ARCHIVE_PATH="${OUTPUT_PARENT}/cybermaps_${PLUGIN_VERSION}.zip"
CHECKSUM_PATH="${ARCHIVE_PATH}.sha256"

for release_tool in \
	"${PLUGIN_DIR}/bin/check-manifest.php" \
	"${PLUGIN_DIR}/bin/check-ai-configuration.php" \
	"${PLUGIN_DIR}/bin/generate-pot.sh" \
	"${PLUGIN_DIR}/bin/validate-release.sh"; do
	if [ ! -f "${release_tool}" ]; then
		echo "ERROR: Required release tool is missing: ${release_tool}" >&2
		exit 1
	fi
done

echo "==> Verifying generated release inputs…"
php "${PLUGIN_DIR}/bin/check-manifest.php"
php "${PLUGIN_DIR}/bin/check-ai-configuration.php"
bash "${PLUGIN_DIR}/bin/generate-pot.sh" --check

if [ -n "${SOURCE_DATE_EPOCH:-}" ]; then
	BUILD_EPOCH="${SOURCE_DATE_EPOCH}"
elif command -v git >/dev/null 2>&1 \
	&& git -C "${PLUGIN_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	BUILD_EPOCH="$(git -C "${PLUGIN_DIR}" log -1 --format=%ct)"
else
	BUILD_EPOCH="$(
		php -r '
			$timestamp = filemtime($argv[1]);
			if (false === $timestamp) {
				fwrite(STDERR, "ERROR: Could not determine the release timestamp.\n");
				exit(1);
			}
			echo $timestamp;
		' "${PLUGIN_DIR}/cybermaps.php"
	)"
fi

if ! [[ "${BUILD_EPOCH}" =~ ^[0-9]+$ ]] \
	|| [ "${BUILD_EPOCH}" -lt 315532800 ] \
	|| [ "${BUILD_EPOCH}" -gt 4354819199 ]; then
	echo "ERROR: SOURCE_DATE_EPOCH must fit the ZIP timestamp range (1980–2107)." >&2
	exit 1
fi

echo "==> Cleaning Cybermaps for production packaging…"
echo "    Source: ${PLUGIN_DIR}"
echo "    Output: ${OUTPUT_DIR}"

# Wipe and recreate only the generated Core artifact paths.
if [ -L "${OUTPUT_PARENT}" ]; then
    echo "ERROR: Refusing to build through a symlinked clean/ directory." >&2
    exit 1
fi
rm -rf "${OUTPUT_DIR}"
rm -f "${ARCHIVE_PATH}"
rm -f "${CHECKSUM_PATH}"
rm -f "${LEGACY_ARCHIVE_PATH}"
mkdir -p "${OUTPUT_DIR}"

# ── Root-level allowed files ──────────────────────────────────────
# Only the files WordPress needs to recognise and run the plugin.
ROOT_FILES=(
    cybermaps.php
    uninstall.php
    readme.txt
    changelog.txt
    LICENSE
)

echo "    → Copying root files…"
for f in "${ROOT_FILES[@]}"; do
    if [ ! -f "${PLUGIN_DIR}/${f}" ]; then
        echo "ERROR: Required release file is missing: ${f}" >&2
        exit 1
    fi
    cp "${PLUGIN_DIR}/${f}" "${OUTPUT_DIR}/${f}"
done

# ── Assets (JS / CSS) ─────────────────────────────────────────────
echo "    → Copying assets…"
cp -r "${PLUGIN_DIR}/assets" "${OUTPUT_DIR}/assets"

# ── Languages ─────────────────────────────────────────────────────
if [ ! -d "${PLUGIN_DIR}/languages" ]; then
    echo "ERROR: Required release directory is missing: languages" >&2
    exit 1
fi
echo "    → Copying languages…"
cp -r "${PLUGIN_DIR}/languages" "${OUTPUT_DIR}/languages"

# ── Source code ───────────────────────────────────────────────────
echo "    → Copying src…"
cp -r "${PLUGIN_DIR}/src" "${OUTPUT_DIR}/src"

# ── Tests (stripped from production) ──────────────────────────────
# Intentionally excluded — no tests/ directory copy.

# ── Docs, plans, dev tooling (stripped) ───────────────────────────
# Documentation and local development tools are excluded.

# ── Post-copy cleanup ─────────────────────────────────────────────
# Strip .map files and source maps from JS (keep production lean).
find "${OUTPUT_DIR}/assets" -type f -name '*.map' -delete 2>/dev/null || true

# Strip all hidden entries from the entire output tree.
# Plugin Check (and WordPress.org review) rejects dotfiles and hidden
# directories such as .gitkeep, .DS_Store, and nested tool metadata.
find "${OUTPUT_DIR}" -depth -name '.*' -exec rm -rf -- {} + 2>/dev/null || true

HIDDEN_ENTRY="$(find "${OUTPUT_DIR}" -name '.*' -print -quit)"
if [ -n "${HIDDEN_ENTRY}" ]; then
    echo "ERROR: Could not remove hidden release entry: ${HIDDEN_ENTRY}" >&2
    exit 1
fi

SOURCE_MAP="$(find "${OUTPUT_DIR}/assets" -type f -name '*.map' -print -quit)"
if [ -n "${SOURCE_MAP}" ]; then
    echo "ERROR: Could not remove source map from release tree: ${SOURCE_MAP}" >&2
    exit 1
fi

# ZIP artifacts must contain only ordinary directories and regular files.
SPECIAL_ENTRY="$(find -P "${OUTPUT_DIR}" ! -type d ! -type f -print -quit)"
if [ -n "${SPECIAL_ENTRY}" ]; then
	echo "ERROR: Release tree contains a symlink or special entry: ${SPECIAL_ENTRY}" >&2
	exit 1
fi

# Keep every recursively copied release directory bounded to its intended file
# formats. An accidentally added design file, database dump, or private export
# must fail the build instead of silently becoming part of a public package.
UNEXPECTED_SOURCE_FILE="$(
	find "${OUTPUT_DIR}/src" -type f ! -name '*.php' -print -quit
)"
if [ -n "${UNEXPECTED_SOURCE_FILE}" ]; then
	echo "ERROR: src/ may contain only PHP files: ${UNEXPECTED_SOURCE_FILE}" >&2
	exit 1
fi

UNEXPECTED_ASSET_FILE="$(
	find "${OUTPUT_DIR}/assets" -type f \
		! \( -name '*.css' -o -name '*.js' -o -name '*.xsl' \) \
		-print -quit
)"
if [ -n "${UNEXPECTED_ASSET_FILE}" ]; then
	echo "ERROR: assets/ may contain only CSS, JavaScript, and XSL files: ${UNEXPECTED_ASSET_FILE}" >&2
	exit 1
fi

UNEXPECTED_LANGUAGE_FILE="$(
	find "${OUTPUT_DIR}/languages" -type f \
		! \( -name '*.pot' -o -name '*.po' -o -name '*.mo' \) \
		-print -quit
)"
if [ -n "${UNEXPECTED_LANGUAGE_FILE}" ]; then
	echo "ERROR: languages/ may contain only POT, PO, and MO files: ${UNEXPECTED_LANGUAGE_FILE}" >&2
	exit 1
fi

# ── Verify output ─────────────────────────────────────────────────
# Core uses its bundled PSR-4 loader and has no production Composer dependency.
if [ -d "${OUTPUT_DIR}/vendor" ]; then
    echo "ERROR: The Core artifact unexpectedly contains vendor/." >&2
    exit 1
fi

# Fail closed if licensing/preprocessor implementation leaked into runnable code.
if CORE_BOUNDARY_MATCHES="$(grep -R -I -n -E \
	--include='*.php' \
	--include='*.js' \
	--include='*.css' \
	--include='*.xsl' \
	'cyb_pro|fs_[a-z_]+|@fs_[a-z_]+|vendor/freemius|freemius/wordpress-sdk|__premium_only|UpgradePromo' \
	"${OUTPUT_DIR}" 2>&1)"; then
	printf '%s\n' "${CORE_BOUNDARY_MATCHES}"
	echo "ERROR: The Core artifact contains licensing or build-strip implementation." >&2
	exit 1
else
	SCAN_STATUS="$?"
	if [ "${SCAN_STATUS}" -ne 1 ]; then
		echo "ERROR: Could not scan the Core artifact: ${CORE_BOUNDARY_MATCHES}" >&2
		exit 1
	fi
fi

echo "    → Linting packaged PHP…"
while IFS= read -r -d '' php_file; do
	php -l "${php_file}" >/dev/null
done < <(find "${OUTPUT_DIR}" -type f -name '*.php' -print0)

# Normalize modes and timestamps before archiving. SOURCE_DATE_EPOCH can be
# supplied by an external release system; otherwise the current commit time is
# used, with the bootstrap mtime as a source-export fallback.
echo "    → Normalizing release metadata…"
php -r '
	$root  = $argv[1];
	$epoch = (int) $argv[2];
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($items as $item) {
		$path = $item->getPathname();
		$mode = $item->isDir() ? 0755 : 0644;
		if (!chmod($path, $mode) || !touch($path, $epoch, $epoch)) {
			fwrite(STDERR, "ERROR: Could not normalize release entry: {$path}\n");
			exit(1);
		}
	}
	if (!chmod($root, 0755) || !touch($root, $epoch, $epoch)) {
		fwrite(STDERR, "ERROR: Could not normalize release root: {$root}\n");
		exit(1);
	}
' "${OUTPUT_DIR}" "${BUILD_EPOCH}"

echo "    → Creating installable ZIP…"
(
	cd "${OUTPUT_PARENT}"
	LC_ALL=C find "$(basename "${OUTPUT_DIR}")" -print \
		| LC_ALL=C sort \
		| TZ=UTC zip -q -X "$(basename "${ARCHIVE_PATH}")" -@
)

echo "    → Validating directory and ZIP parity…"
bash "${PLUGIN_DIR}/bin/validate-release.sh" "${OUTPUT_DIR}" "${ARCHIVE_PATH}"

ARCHIVE_SHA256="$(
	php -r '
		$hash = hash_file("sha256", $argv[1]);
		if (!is_string($hash) || "" === $hash) {
			fwrite(STDERR, "ERROR: Could not hash the release ZIP.\n");
			exit(1);
		}
		echo $hash;
	' "${ARCHIVE_PATH}"
)"
printf '%s  %s\n' "${ARCHIVE_SHA256}" "$(basename "${ARCHIVE_PATH}")" > "${CHECKSUM_PATH}"

echo ""
echo "==> Done. Validated WordPress.org Core artifacts:"
echo "    Directory: ${OUTPUT_DIR}"
echo "    ZIP:       ${ARCHIVE_PATH}"
echo "    SHA-256:   ${ARCHIVE_SHA256}"
echo "    Checksum:  ${CHECKSUM_PATH}"
echo ""
echo "    Included:"
echo "      $(find "${OUTPUT_DIR}" -type f | wc -l) files"
echo "      $(du -sh "${OUTPUT_DIR}" | cut -f1) total"
echo ""
echo "    Excluded:"
echo "      tests/           — unit tests & mocks"
echo "      docs/            — documentation, plans, mockups"
echo "      .git/            — version control"
echo "      vendor/          — no Core runtime dependencies"
echo "      composer.*       — development dependency metadata"
echo "      *.map            — source maps"
echo "      .*               — hidden entries (.gitkeep, .DS_Store, metadata dirs)"
