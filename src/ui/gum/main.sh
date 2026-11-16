#!/usr/bin/env bash
set -euo pipefail
UI_DIR=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(CDPATH= cd -- "$UI_DIR/../../.." && pwd)
cd -- "$ROOT"
UI_LANGUAGE=ru
source "$UI_DIR/checks.sh"
if [[ ! -t 0 || ! -t 1 || ! -t 2 ]]; then
    printf '%s\n' 'Нужен интерактивный терминал / An interactive terminal is required.' >&2
    exit 1
fi
select_php || { printf '%s\n' 'Недоступен PHP приложения / App PHP is unavailable.' >&2; exit 1; }
PHP_ARGS+=(-d display_startup_errors=0)
source "$UI_DIR/messages.sh"
load_texts || { printf '%s\n' 'Не удалось загрузить интерфейс / Could not load the interface.' >&2; exit 1; }
source "$UI_DIR/menus.sh"
source "$UI_DIR/screens.sh"
source "$UI_DIR/flows.sh"
if [[ ${VM_PORTABLE:-0} == 1 ]]; then GUM="$ROOT/runtime/gum"
else GUM=$(command -v gum) || { ui_text gum_missing >&2; exit 1; }; fi
[[ -x "$GUM" ]] || { ui_text gum_missing >&2; exit 1; }
trap 'exit 130' INT
trap 'exit 143' TERM
trap cleanup_diagnostic EXIT
while true; do
    screen title welcome
    choose_action run check identify help language exit
    case "$MENU_ACTION" in
        run) run_flow ;;
        identify) identify_flow ;;
        check) readiness_flow ;;
        help) help_flow ;;
        language)
            screen language language_note
            choose_action ru en back
            case "$MENU_ACTION" in ru|en) UI_LANGUAGE=$MENU_ACTION ;; esac ;;
        exit) ui_text bye; exit 0 ;;
    esac
done
