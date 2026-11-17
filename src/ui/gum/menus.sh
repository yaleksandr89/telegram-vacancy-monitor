#!/usr/bin/env bash
choose_action() {
    local key choice status
    local -a labels=()
    for key in "$@"; do labels+=("$(ui_text "$key")"); done
    if choice=$("$GUM" choose --header "$(ui_text next)" --height "${#labels[@]}" -- "${labels[@]}"); then
        for key in "$@"; do
            if [[ "$choice" == "$(ui_text "$key")" ]]; then MENU_ACTION=$key; return; fi
        done
        ui_text menu_error >&2; exit 2
    else
        status=$?
        case "$status" in
            1) MENU_ACTION=back ;;
            130) exit 130 ;;
            *) ui_text menu_error >&2; exit "$status" ;;
        esac
    fi
}
