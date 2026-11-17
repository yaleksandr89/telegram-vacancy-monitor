#!/bin/bash
set -euo pipefail
ROOT=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
export VM_PORTABLE=1
unset PHP_BINARY PHPRC PHP_INI_SCAN_DIR LD_PRELOAD LD_LIBRARY_PATH DYLD_LIBRARY_PATH DYLD_INSERT_LIBRARIES
exec /bin/bash "$ROOT/src/ui/gum/main.sh"
