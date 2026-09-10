#!/usr/bin/env bash
# Publish only locally validated artifacts; GitHub Actions is not involved.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
exec python3 bin/release-github.py "$@"
