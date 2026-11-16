#!/usr/bin/env bash
screen_body() {
    if [[ -t 1 && ${TERM:-dumb} != dumb ]]; then tput clear 2>/dev/null || :; fi
    "$GUM" style --border rounded --padding '0 2' -- "$(ui_text "$1")"
    printf '%s\n' "$2" | "$GUM" format --type markdown
}
screen() { screen_body "$1" "$(ui_text "$2")"; }
