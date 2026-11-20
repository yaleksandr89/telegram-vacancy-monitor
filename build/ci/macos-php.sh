#!/usr/bin/env bash
set -euo pipefail

fail() {
    printf 'macOS PHP: %s\n' "$1" >&2
    exit 1
}

[[ ${GITHUB_ACTIONS:-} == true && ${RUNNER_OS:-} == macOS && ${RUNNER_ARCH:-} == ARM64 &&
    $(uname -s) == Darwin && $(uname -m) == arm64 ]] || fail 'Требуется ARM64 runner GitHub Actions на macOS.'
[[ -d ${RUNNER_TEMP:-} && -f ${GITHUB_PATH:-} ]] || fail 'Требуются RUNNER_TEMP и GITHUB_PATH.'
for tool in brew curl jq tar zstd python3; do
    command -v "$tool" >/dev/null || fail "На runner отсутствует $tool."
done

tools="$(dirname "$0")/../common/ci-tools.json"
work=$(mktemp -d "$RUNNER_TEMP/tvm-macos-php.XXXXXXXX")
trap 'rm -rf -- "$work"' EXIT

verified_download() {
    local key=$1 file=$2 url hash
    url=$(jq -er --arg key "$key" '.macos_build_php[$key].url' "$tools") || fail "Отсутствует URL для $key."
    hash=$(jq -er --arg key "$key" '.macos_build_php[$key].sha256' "$tools") || fail "Отсутствует SHA256 для $key."
    [[ $hash =~ ^[0-9a-f]{64}$ ]] || fail "Некорректный SHA256 pin для $key."
    curl --disable --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
        --connect-timeout 15 --max-time 300 --output "$file" "$url" || fail "Не удалось скачать $key."
    if ! python3 - "$file" "$hash" <<'PY'
import hashlib
import sys

with open(sys.argv[1], "rb") as stream:
    sys.exit(hashlib.file_digest(stream, "sha256").hexdigest() != sys.argv[2])
PY
    then
        fail "SHA256 не совпал для $key."
    fi
    printf '%s\n' "$hash"
}

name=$(jq -er '.macos_build_php.archive.local_name' "$tools") || fail 'Отсутствует локальное имя архива.'
[[ $name == php_8.5-nts-release+darwin_arm64.tar.zst ]] || fail 'Некорректное локальное имя архива.'
archive="$work/$name"
verified_download installer "$work/install.sh" >/dev/null
hash=$(verified_download archive "$archive")
printf '%s  %s\n' "$hash" "$name" > "$archive.sha256"

# RU: Четвёртый аргумент включает проверку локального архива. Пустой пятый отключает пакеты расширений.
# EN: The fourth argument selects verified local-archive mode. An empty fifth argument disables extension packs.
printf '%s\n' 'macOS PHP: оба SHA256 совпали, устанавливается сборочный PHP из локального архива.'
bash "$work/install.sh" 8.5 release nts "$archive" "" || fail 'Установка проверенного архива завершилась ошибкой.'

bin=/opt/homebrew/opt/php@8.5/bin
probe=$("$bin/php" -r 'echo json_encode([PHP_VERSION_ID, PHP_SAPI, PHP_INT_SIZE, php_uname("m"), extension_loaded("phar"), extension_loaded("json"), extension_loaded("filter"), extension_loaded("hash")]);') ||
    fail 'Установленный PHP CLI не запускается.'
[[ $probe =~ ^\[805[0-9][0-9],\"cli\",8,\"arm64\",true,true,true,true\]$ ]] ||
    fail 'Требуется PHP 8.5 CLI arm64 с phar/json/filter/hash.'
version=$("$bin/php-config" --version) || fail 'Установленный php-config не запускается.'
[[ $version =~ ^8\.5\.[0-9]+$ ]] || fail 'Требуется php-config 8.5.'
printf '%s\n' "$bin" >> "$GITHUB_PATH"
printf 'macOS PHP: PHP %s CLI arm64 доступен сборке.\n' "$version"
