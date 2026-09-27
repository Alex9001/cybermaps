#!/usr/bin/env bash
# Source from tooling only. Never inherit a temporary directory outside the source.
CYBERMAPS_SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMPDIR="$(python3 -B "${CYBERMAPS_SOURCE_DIR}/bin/workspace.py" tmp)"
export TMPDIR TMP="${TMPDIR}" TEMP="${TMPDIR}" PYTHONDONTWRITEBYTECODE=1 GIT_OPTIONAL_LOCKS=0
