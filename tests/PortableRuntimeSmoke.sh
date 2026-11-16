#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
exec python3 "$ROOT/tests/portable/runtime_smoke.py" "$@"
