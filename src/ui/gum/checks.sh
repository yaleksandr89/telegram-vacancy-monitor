#!/usr/bin/env bash
select_php() {
    PHP_ARGS=(-d display_errors=0)
    if [[ ${VM_PORTABLE:-0} == 1 ]]; then
        PHP="$ROOT/runtime/php"
        [[ -x "$PHP" ]] || return 1
        PHP_ARGS=(-n -d "extension_dir=$ROOT/runtime/empty" -d "curl.cainfo=$ROOT/runtime/cacert.pem")
        if [[ ${VM_PLATFORM:-} == linux || ${OSTYPE:-} == linux* ]]; then
            [[ -x "$ROOT/runtime/ld-linux-x86-64.so.2" ]] || return 1
            local library
            for library in libc.so.6 libm.so.6 libssl.so.3 libcrypto.so.3 libgcc_s.so.1; do
                [[ -f "$ROOT/runtime/lib/$library" ]] || return 1
            done
            PHP_ARGS=("$PHP" "${PHP_ARGS[@]}")
            PHP="$ROOT/runtime/ld-linux-x86-64.so.2"
            PHP_ARGS=(--inhibit-cache --library-path "$ROOT/runtime/lib" "${PHP_ARGS[@]}")
        fi
    else
        PHP=${PHP_BINARY:-}
        [[ -n "$PHP" ]] && command -v "$PHP" >/dev/null || return 1
    fi
}
php_app() { "$PHP" "${PHP_ARGS[@]}" -d display_errors=0 -d log_errors=0 "$@"; }
check_php_runtime() { php_app "$ROOT/src/ui/windows/inspect.php" runtime 2>/dev/null; }
