#!/usr/bin/env bash
help_flow() {
    while true; do
        screen help help_intro
        choose_action quick processing privacy model vds main exit
        case "$MENU_ACTION" in
            main|back) return ;; exit) exit 0 ;;
            *) screen help "help_$MENU_ACTION"; choose_action back main exit
                case "$MENU_ACTION" in main) return ;; exit) exit 0 ;; esac ;;
        esac
    done
}
readiness_flow() {
    local output status key group category details summary failed item
    while true; do
        output=$(php_app "$ROOT/src/ui/windows/inspect.php" check 2>/dev/null) || output=$'fail\tphp\truntime'
        summary=''; failed=0
        for group in runtime config profile storage; do
            item=skip
            while IFS=$'\t' read -r status key category; do
                [[ "$category" == "$group" ]] || continue
                [[ "$item" != skip ]] || item=ok
                [[ "$status" == ok || "$status" == optional ]] || { item=fail; failed=1; }
            done <<< "$output"
            [[ "$item" != skip ]] || failed=1
            summary+="- $(ui_text "group_$group"): $(ui_text "$item")"$'\n'
        done
        if [[ "$failed" == 0 ]]; then key=ready; else key=not_ready; fi
        screen_body check "## $(ui_text "$key")"$'\n'"$summary"$'\n'"$(ui_text checks_note)"
        choose_action details repeat main exit
        case "$MENU_ACTION" in
            main|back) return ;; exit) exit 0 ;;
            details)
                while true; do
                    screen check details_intro
                    choose_action group_runtime group_config group_profile group_storage back main exit
                    case "$MENU_ACTION" in
                        back) break ;; main) return ;; exit) exit 0 ;;
                        *) group=${MENU_ACTION#group_}; details=''
                            while IFS=$'\t' read -r status key category; do
                                [[ "$category" == "$group" ]] || continue
                                details+="- $(ui_text "$status"): $(ui_text "$key")"$'\n'
                            done <<< "$output"
                            screen_body check "$details"
                            choose_action back main exit
                            case "$MENU_ACTION" in main) return ;; exit) exit 0 ;; esac ;;
                    esac
                done ;;
        esac
    done
}
action_guard() {
    if ! check_php_runtime; then GUARD=runtime_invalid; return 1; fi
    local mode
    mode=$(php_app "$ROOT/src/ui/windows/inspect.php" mode 2>/dev/null) || { GUARD=config_invalid; return 1; }
    if [[ "$mode" == webhook ]]; then GUARD=webhook; return 1; fi
    return 0
}
cleanup_diagnostic() {
    if [[ -n ${DIAGNOSTIC_FILE:-} ]]; then
        php_app "$ROOT/src/ui/windows/inspect.php" diagnostic-delete "$DIAGNOSTIC_FILE" >/dev/null 2>&1 || :
        DIAGNOSTIC_FILE=''
    fi
}
begin_diagnostic() {
    DIAGNOSTIC_CATEGORY=runtime
    DIAGNOSTIC_CODE=70
    DIAGNOSTIC_FILE=$(php_app "$ROOT/src/ui/windows/inspect.php" diagnostic-create 2>/dev/null) || return 70
}
invoke_bot() {
    php_app "$ROOT/src/ui/windows/inspect.php" execute "$1" "$DIAGNOSTIC_FILE" "$PHP" "${PHP_ARGS[@]}"
}
end_diagnostic() {
    local result
    DIAGNOSTIC_CODE=$1
    if result=$(php_app "$ROOT/src/ui/windows/inspect.php" diagnostic-read "$DIAGNOSTIC_FILE" 2>/dev/null); then
        IFS=$'\t' read -r DIAGNOSTIC_CATEGORY DIAGNOSTIC_CODE <<< "$result"
    fi
    cleanup_diagnostic
}
diagnostic_text() {
    printf '\n%s\n%s: %s\n' "$(ui_text "diagnostic_${DIAGNOSTIC_CATEGORY:-unknown}")" "$(ui_text exit_code)" "${DIAGNOSTIC_CODE:-1}"
}
identify_flow() {
    local body=identify_intro output ids='' page=0 id id_count=0 failure=''
    local -a id_list=()
    while true; do
        ids=''
        if [[ "$id_count" -gt 0 ]]; then
            ids=$(printf '%s\n' "${id_list[@]:page*5:5}")
        fi
        screen_body identify "$(ui_text "$body")${ids:+$'\n'"$ids"}$failure"
        if [[ "$id_count" -gt 5 ]]; then
            choose_action check_now repeat next_ids prev_ids main exit
        else
            choose_action check_now repeat main exit
        fi
        case "$MENU_ACTION" in
            main|back) return ;; exit) exit 0 ;;
            next_ids) page=$(( (page+1) % ((id_count+4)/5) )); continue ;;
            prev_ids) page=$(( (page+((id_count+4)/5)-1) % ((id_count+4)/5) )); continue ;;
        esac
        page=0; id_list=(); id_count=0
        ids=''; failure=''
        if ! action_guard; then body=$GUARD; continue; fi
        if begin_diagnostic && output=$(invoke_bot identify); then
            end_diagnostic 0
            if ids=$(printf '%s' "$output" | php_app "$ROOT/src/ui/windows/inspect.php" ids 2>/dev/null); then
                if [[ -n "$ids" ]]; then
                    body=identified
                    while IFS= read -r id; do id_list+=("$id"); done <<< "$ids"
                    id_count=${#id_list[@]}
                else body=identify_empty; fi
            else body=identify_error; ids=''; DIAGNOSTIC_CATEGORY=unknown; DIAGNOSTIC_CODE=65; failure=$(diagnostic_text); fi
        else
            end_diagnostic "$?"
            body=identify_error
            failure=$(diagnostic_text)
        fi
    done
}
run_flow() {
    local body status failure
    while true; do
        failure=''
        if action_guard; then
            screen run starting
            if begin_diagnostic && invoke_bot run; then status=0; else status=$?; fi
            end_diagnostic "$status"
            case "$status" in 130|143) exit "$status" ;; esac
            if [[ "$status" == 0 ]]; then body=run_done; else body=run_error; failure=$(diagnostic_text); fi
        else body=$GUARD; fi
        screen_body run "$(ui_text "$body")$failure"
        choose_action again main exit
        case "$MENU_ACTION" in main|back) return ;; exit) exit 0 ;; esac
    done
}
