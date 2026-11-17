#!/usr/bin/env bash
load_texts() {
    TEXT_KEYS=() TEXT_RU=() TEXT_EN=()
    local key ru en
    while IFS= read -r -d '' key && IFS= read -r -d '' ru && IFS= read -r -d '' en; do
        TEXT_KEYS+=("$key"); TEXT_RU+=("$ru"); TEXT_EN+=("$en")
    done < <(php_app "$ROOT/src/ui/windows/inspect.php" texts)
    [[ ${#TEXT_KEYS[@]} -gt 30 ]]
}
ui_text() {
    local i
    for ((i=0; i<${#TEXT_KEYS[@]}; i++)); do
        if [[ ${TEXT_KEYS[$i]} == "$1" ]]; then
            if [[ "$UI_LANGUAGE" == en ]]; then printf '%s\n' "${TEXT_EN[$i]}"; else printf '%s\n' "${TEXT_RU[$i]}"; fi
            return
        fi
    done
    return 2
}
