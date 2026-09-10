#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTIFACT_DIR="${1:-${PROJECT_DIR}/clean/cybermaps}"
ARCHIVE_PATH="${2:-}"

fail() {
    echo "Release validation failed: $1" >&2
    exit 1
}

for required_command in basename cmp diff find grep php python3 rg; do
    if ! command -v "${required_command}" >/dev/null 2>&1; then
        fail "required validation command is unavailable: ${required_command}"
    fi
done

if [ ! -d "${ARTIFACT_DIR}" ]; then
    fail "artifact directory does not exist: ${ARTIFACT_DIR}"
fi

SPECIAL_ENTRY="$(find -P "${ARTIFACT_DIR}" ! -type d ! -type f -print -quit)"
if [ -n "${SPECIAL_ENTRY}" ]; then
    fail "artifact contains a symlink or special entry: ${SPECIAL_ENTRY}"
fi

for required_file in cybermaps.php uninstall.php readme.txt changelog.txt LICENSE; do
    if [ ! -f "${ARTIFACT_DIR}/${required_file}" ]; then
        fail "missing required file: ${required_file}"
    fi
done

README_BYTES="$(php -r '
    $size = filesize($argv[1]);
    if (false === $size) {
        fwrite(STDERR, "Release validation failed: could not determine readme.txt size\n");
        exit(1);
    }
    echo $size;
' "${ARTIFACT_DIR}/readme.txt")"
if [ "${README_BYTES}" -gt 10000 ]; then
    fail "readme.txt is ${README_BYTES} bytes; WordPress.org warns that files larger than 10k may fail"
fi

for required_dir in src assets languages; do
	if [ ! -d "${ARTIFACT_DIR}/${required_dir}" ]; then
		fail "missing required directory: ${required_dir}"
	fi
done

UNEXPECTED_SOURCE_FILE="$(
	find "${ARTIFACT_DIR}/src" -type f ! -name '*.php' -print -quit
)"
if [ -n "${UNEXPECTED_SOURCE_FILE}" ]; then
	fail "src/ may contain only PHP files: ${UNEXPECTED_SOURCE_FILE}"
fi

UNEXPECTED_ASSET_FILE="$(
	find "${ARTIFACT_DIR}/assets" -type f \
		! \( -name '*.css' -o -name '*.js' -o -name '*.xsl' \) \
		-print -quit
)"
if [ -n "${UNEXPECTED_ASSET_FILE}" ]; then
	fail "assets/ may contain only CSS, JavaScript, and XSL files: ${UNEXPECTED_ASSET_FILE}"
fi

UNEXPECTED_LANGUAGE_FILE="$(
	find "${ARTIFACT_DIR}/languages" -type f \
		! \( -name '*.pot' -o -name '*.po' -o -name '*.mo' \) \
		-print -quit
)"
if [ -n "${UNEXPECTED_LANGUAGE_FILE}" ]; then
	fail "languages/ may contain only POT, PO, and MO files: ${UNEXPECTED_LANGUAGE_FILE}"
fi

for forbidden_dir in tests docs freemius vendor .git .github; do
	if [ -e "${ARTIFACT_DIR}/${forbidden_dir}" ]; then
		fail "development directory included: ${forbidden_dir}"
    fi
done

while IFS= read -r -d '' root_entry; do
    case "$(basename "${root_entry}")" in
        cybermaps.php|uninstall.php|readme.txt|changelog.txt|LICENSE|src|assets|languages)
            ;;
        *)
            fail "unexpected artifact root entry: $(basename "${root_entry}")"
            ;;
    esac
done < <(find "${ARTIFACT_DIR}" -mindepth 1 -maxdepth 1 -print0)

if [ -d "${ARTIFACT_DIR}/vendor/freemius" ]; then
    fail "Freemius SDK included"
fi

if LICENSING_MATCHES="$(rg -n \
	--glob '*.php' \
	--glob '*.js' \
	--glob '*.css' \
	--glob '*.xsl' \
	--glob 'composer.json' \
	'cyb_pro|\bfs_[a-z_]+|@fs_premium_only|__premium_only|freemius/wordpress-sdk|UpgradePromo' \
	"${ARTIFACT_DIR}" 2>&1)"; then
	printf '%s\n' "${LICENSING_MATCHES}"
	fail "licensing or preprocessing implementation remains in Core"
else
	SCAN_STATUS="$?"
	if [ "${SCAN_STATUS}" -ne 1 ]; then
		fail "could not scan the artifact for licensing or preprocessing implementation: ${LICENSING_MATCHES}"
	fi
fi

if FILESYSTEM_MATCHES="$(rg -n \
	--glob '*.php' \
	'\b(fopen|fwrite|fclose|file_put_contents)\s*\(' \
	"${ARTIFACT_DIR}" 2>&1)"; then
	printf '%s\n' "${FILESYSTEM_MATCHES}"
	fail "direct filesystem/output-stream functions remain in release PHP"
else
	SCAN_STATUS="$?"
	if [ "${SCAN_STATUS}" -ne 1 ]; then
		fail "could not scan release PHP for direct filesystem functions: ${FILESYSTEM_MATCHES}"
	fi
fi

cmp -s "${PROJECT_DIR}/cybermaps.php" "${ARTIFACT_DIR}/cybermaps.php" \
    || fail "artifact bootstrap differs from source"
cmp -s "${PROJECT_DIR}/uninstall.php" "${ARTIFACT_DIR}/uninstall.php" \
    || fail "artifact uninstall entry point differs from source"
cmp -s "${PROJECT_DIR}/readme.txt" "${ARTIFACT_DIR}/readme.txt" \
    || fail "artifact readme differs from source"
cmp -s "${PROJECT_DIR}/changelog.txt" "${ARTIFACT_DIR}/changelog.txt" \
    || fail "artifact changelog differs from source"
cmp -s "${PROJECT_DIR}/LICENSE" "${ARTIFACT_DIR}/LICENSE" \
    || fail "artifact license differs from source"
diff -qr "${PROJECT_DIR}/src" "${ARTIFACT_DIR}/src" >/dev/null \
    || fail "artifact src differs from source"
diff -qr -x '.*' -x '*.map' \
    "${PROJECT_DIR}/assets" "${ARTIFACT_DIR}/assets" >/dev/null \
    || fail "artifact assets differ from source"
diff -qr -x '.*' \
    "${PROJECT_DIR}/languages" "${ARTIFACT_DIR}/languages" >/dev/null \
    || fail "artifact languages differ from source"

PLUGIN_VERSION="$(php -r '
    function abort_release(string $message): void {
        fwrite(STDERR, "Release validation failed: {$message}\n");
        exit(1);
    }
    function read_required(string $path, string $label): string {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            abort_release("could not read {$label}: {$path}");
        }
        return $contents;
    }
    function capture(string $pattern, string $contents, string $label): string {
        if (!preg_match($pattern, $contents, $match) || empty($match[1])) {
            abort_release("could not read {$label}");
        }
        return (string) $match[1];
    }
    function require_same(string $label, array $values): void {
        $values = array_map("strval", $values);
        if (count(array_unique($values, SORT_STRING)) !== 1 || "" === (string) reset($values)) {
            abort_release("{$label} mismatch: " . implode(" / ", $values));
        }
    }

    $main = read_required($argv[1], "plugin bootstrap");
    $readme = read_required($argv[2], "WordPress readme");
    $changelog = read_required($argv[3], "release changelog");
    $pot = read_required($argv[4], "translation template");
    $manifest_json = read_required($argv[5], "generated manifest");
    $documentation = read_required($argv[6], "technical documentation");
    $features = read_required($argv[7], "feature reference");
    $comparison = read_required($argv[8], "comparison reference");

    try {
        $manifest = json_decode($manifest_json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        abort_release("generated manifest is invalid JSON: " . $error->getMessage());
    }
    if (!is_array($manifest)) {
        abort_release("generated manifest must contain a JSON object");
    }

    $version = capture(
        "/^[ \t*]*Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)[ \t]*$/mi",
        $main,
        "plugin header version"
    );
    $versions = [
        $version,
        capture("~define\(\s*[\x22\x27]CYBERMAPS_VERSION[\x22\x27]\s*,\s*[\x22\x27]([0-9]+\.[0-9]+\.[0-9]+)[\x22\x27]\s*\)~", $main, "version constant"),
        capture("/^Stable tag:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)[ \t]*$/mi", $readme, "Stable tag"),
        capture("/^== Changelog ==[ \t]*\R(?:[ \t]*\R)*= ([0-9]+\.[0-9]+\.[0-9]+) =[ \t]*$/mi", $readme, "top readme changelog"),
        capture("/^== Upgrade Notice ==[ \t]*\R(?:[ \t]*\R)*= ([0-9]+\.[0-9]+\.[0-9]+) =[ \t]*$/mi", $readme, "top Upgrade Notice"),
        capture("/^Cybermaps release history[ \t]*\R=+[ \t]*\R(?:[ \t]*\R)*([0-9]+\.[0-9]+\.[0-9]+)[ \t]*\R-+[ \t]*$/mi", $changelog, "top changelog.txt version"),
        capture("/Project-Id-Version:[^\r\n]*?([0-9]+\.[0-9]+\.[0-9]+)\\\\n/", $pot, "POT project version"),
        capture("/\A([0-9]+\.[0-9]+\.[0-9]+)\z/", (string) ($manifest["version"] ?? ""), "manifest version"),
        capture("/^> Version ([0-9]+\.[0-9]+\.[0-9]+)(?:[ \t]*·|[ \t]*$)/m", $documentation, "documentation version"),
        capture("/^# Cybermaps ([0-9]+\.[0-9]+\.[0-9]+)(?:[ \t]|$)/m", $features, "feature-reference version"),
        capture("/^# Cybermaps ([0-9]+\.[0-9]+\.[0-9]+)(?:[ \t]|$)/m", $comparison, "comparison version"),
    ];
    require_same("Release version", $versions);

    require_same(
        "Required WordPress version",
        [
            capture("/^[ \t*]*Requires at least:[ \t]*([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/mi", $main, "plugin WordPress requirement"),
            capture("/^Requires at least:[ \t]*([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/mi", $readme, "readme WordPress requirement"),
        ]
    );
    require_same(
        "Required PHP version",
        [
            capture("/^[ \t*]*Requires PHP:[ \t]*([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/mi", $main, "plugin PHP requirement"),
            capture("/^Requires PHP:[ \t]*([0-9]+(?:\.[0-9]+){1,2})[ \t]*$/mi", $readme, "readme PHP requirement"),
        ]
    );
    require_same(
        "License",
        [
            strtolower(capture("/^[ \t*]*License:[ \t]*(GPLv2 or later)[ \t]*$/mi", $main, "plugin license")),
            strtolower(capture("/^License:[ \t]*(GPLv2 or later)[ \t]*$/mi", $readme, "readme license")),
        ]
    );

    echo $version;
' \
    "${ARTIFACT_DIR}/cybermaps.php" \
    "${ARTIFACT_DIR}/readme.txt" \
    "${ARTIFACT_DIR}/changelog.txt" \
    "${ARTIFACT_DIR}/languages/cybermaps.pot" \
    "${PROJECT_DIR}/docs/dev/manifest.json" \
    "${PROJECT_DIR}/docs/documentation.md" \
    "${PROJECT_DIR}/docs/features.md" \
    "${PROJECT_DIR}/docs/comparison.md"
)"
echo "Version: ${PLUGIN_VERSION}"

while IFS= read -r -d '' php_file; do
    php -l "${php_file}" >/dev/null
done < <(find "${ARTIFACT_DIR}" -type f -name '*.php' -print0)

if find "${ARTIFACT_DIR}" -name '.*' -print -quit | grep -q .; then
    fail "hidden entry included"
fi

if find "${ARTIFACT_DIR}/assets" -type f -name '*.map' -print -quit | grep -q .; then
    fail "source map included"
fi

if [ -z "${ARCHIVE_PATH}" ]; then
    ARCHIVE_PATH="${PROJECT_DIR}/clean/cybermaps_${PLUGIN_VERSION}.zip"
fi
if [ "$(basename "${ARCHIVE_PATH}")" != "cybermaps_${PLUGIN_VERSION}.zip" ]; then
    fail "archive name must be cybermaps_${PLUGIN_VERSION}.zip"
fi
if [ -L "${ARCHIVE_PATH}" ]; then
    fail "versioned ZIP must not be a symlink: ${ARCHIVE_PATH}"
fi
if [ ! -f "${ARCHIVE_PATH}" ]; then
    fail "versioned ZIP does not exist: ${ARCHIVE_PATH}"
fi

python3 - "${ARCHIVE_PATH}" "${ARTIFACT_DIR}" <<'PY'
import hashlib
import os
import stat
import sys
import unicodedata
import zipfile
from pathlib import Path


def abort(message):
    print(f"Release validation failed: {message}", file=sys.stderr)
    raise SystemExit(1)


def summarize(names):
    ordered = sorted(names)
    displayed = ", ".join(repr(name) for name in ordered[:10])
    if len(ordered) > 10:
        displayed += f", ... ({len(ordered) - 10} more)"
    return displayed


def validate_component(component, member_name):
    if component in {"", ".", ".."}:
        abort(f"ZIP member has an unsafe path component: {member_name!r}")
    if any(unicodedata.category(character).startswith("C") for character in component):
        abort(f"ZIP member contains a control or non-portable Unicode character: {member_name!r}")
    if any(character in '<>:"|?*' for character in component):
        abort(f"ZIP member is not portable across supported filesystems: {member_name!r}")
    if component.endswith((" ", ".")):
        abort(f"ZIP member has a non-portable trailing character: {member_name!r}")

    stem = component.split(".", 1)[0].upper()
    reserved = {"CON", "PRN", "AUX", "NUL"}
    reserved.update(f"COM{number}" for number in range(1, 10))
    reserved.update(f"LPT{number}" for number in range(1, 10))
    if stem in reserved:
        abort(f"ZIP member uses a reserved filesystem name: {member_name!r}")


def validate_member_name(member_name, is_directory):
    if not member_name:
        abort("ZIP contains an empty member name")
    if "\x00" in member_name or "\\" in member_name:
        abort(f"ZIP member contains an unsafe path character: {member_name!r}")
    if member_name.startswith("/"):
        abort(f"ZIP member uses an absolute path: {member_name!r}")
    if is_directory != member_name.endswith("/"):
        abort(f"ZIP member directory marker disagrees with its type: {member_name!r}")

    normalized_name = member_name[:-1] if is_directory else member_name
    components = normalized_name.split("/")
    for component in components:
        validate_component(component, member_name)

    if components[0] != "cybermaps":
        abort(f"ZIP member is outside the cybermaps/ plugin root: {member_name!r}")
    if len(components) == 1 and not is_directory:
        abort("ZIP plugin root is a file instead of a directory")


def sha256_stream(stream):
    digest = hashlib.sha256()
    while True:
        chunk = stream.read(1024 * 1024)
        if not chunk:
            break
        digest.update(chunk)
    return digest.hexdigest()


archive_path = Path(sys.argv[1])
artifact_path = Path(sys.argv[2]).resolve()

expected = {
    "cybermaps/": ("directory", artifact_path),
}
for current_root, directory_names, file_names in os.walk(artifact_path, followlinks=False):
    directory_names.sort()
    file_names.sort()
    current_path = Path(current_root)

    for directory_name in directory_names:
        local_path = current_path / directory_name
        relative = local_path.relative_to(artifact_path).as_posix()
        expected[f"cybermaps/{relative}/"] = ("directory", local_path)

    for file_name in file_names:
        local_path = current_path / file_name
        relative = local_path.relative_to(artifact_path).as_posix()
        expected[f"cybermaps/{relative}"] = ("file", local_path)

try:
    if not zipfile.is_zipfile(archive_path):
        abort(f"archive is not a valid ZIP file: {archive_path}")

    with zipfile.ZipFile(archive_path, "r") as archive:
        actual = {}
        portable_names = {}
        for info in archive.infolist():
            member_name = info.filename
            if member_name in actual:
                abort(f"ZIP contains a duplicate member: {member_name!r}")
            if info.flag_bits & 0x1:
                abort(f"ZIP contains an encrypted member: {member_name!r}")

            mode = (info.external_attr >> 16) & 0xFFFF
            file_type = stat.S_IFMT(mode)
            if file_type == stat.S_IFDIR and info.is_dir():
                kind = "directory"
                if info.file_size != 0:
                    abort(f"ZIP directory member contains data: {member_name!r}")
            elif file_type == stat.S_IFREG and not info.is_dir():
                kind = "file"
            else:
                abort(
                    "ZIP member is a symlink, special entry, or lacks a verifiable "
                    f"file type: {member_name!r}"
                )

            validate_member_name(member_name, kind == "directory")
            portable_key = unicodedata.normalize("NFC", member_name.rstrip("/")).casefold()
            prior_name = portable_names.get(portable_key)
            if prior_name is not None and prior_name != member_name:
                abort(
                    "ZIP contains names that collide on case-insensitive or "
                    f"Unicode-normalizing filesystems: {prior_name!r} / {member_name!r}"
                )
            portable_names[portable_key] = member_name
            actual[member_name] = (info, kind, mode)

        expected_names = set(expected)
        actual_names = set(actual)
        missing_names = expected_names - actual_names
        unexpected_names = actual_names - expected_names
        if missing_names:
            abort(f"ZIP is missing validated artifact entries: {summarize(missing_names)}")
        if unexpected_names:
            abort(f"ZIP has entries absent from the validated artifact: {summarize(unexpected_names)}")

        for member_name in sorted(expected_names):
            expected_kind, local_path = expected[member_name]
            info, actual_kind, archive_mode = actual[member_name]
            if actual_kind != expected_kind:
                abort(f"ZIP member type differs from the artifact: {member_name!r}")
            if expected_kind == "directory":
                continue

            local_stat = local_path.stat()
            if info.file_size != local_stat.st_size:
                abort(f"ZIP member size differs from the artifact: {member_name!r}")
            if (archive_mode & 0o111) != (local_stat.st_mode & 0o111):
                abort(f"ZIP member executable permissions differ from the artifact: {member_name!r}")

            with local_path.open("rb") as local_stream:
                local_digest = sha256_stream(local_stream)
            with archive.open(info, "r") as archive_stream:
                archive_digest = sha256_stream(archive_stream)
            if archive_digest != local_digest:
                abort(f"ZIP member content differs from the artifact: {member_name!r}")
except (OSError, RuntimeError, NotImplementedError, zipfile.BadZipFile) as error:
    abort(f"could not validate ZIP archive: {error}")
PY

echo "Release artifact valid: ${ARTIFACT_DIR}"
echo "Release ZIP valid: ${ARCHIVE_PATH}"
