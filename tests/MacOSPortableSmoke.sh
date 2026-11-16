#!/bin/bash
set -euo pipefail
[[ $(uname -s) == Darwin && $(uname -m) == arm64 ]] || { printf '%s\n' 'NOT VERIFIED: нужен native macOS arm64'; exit 1; }
ROOT=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
exec python3 "$ROOT/tests/portable/runtime_smoke.py" "$@"
