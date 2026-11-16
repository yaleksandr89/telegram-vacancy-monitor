#!/usr/bin/env bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
: "${PHP_BINARY:?Нужен выбранный PHP для проверок checkout}"
for file in "$ROOT"/src/ui/gum/*.sh "$ROOT"/bin/vacancy-monitor.sh "$ROOT"/build/unix/*.sh "$ROOT"/build/macos/*.sh; do bash -n "$file"; done
python3 "$ROOT/tests/portable/build_smoke.py"
exec python3 "$ROOT/tests/portable/ui_smoke.py" "$ROOT" "$PHP_BINARY"
