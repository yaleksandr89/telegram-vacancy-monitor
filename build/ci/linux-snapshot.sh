#!/usr/bin/env bash
set -euo pipefail

fail() {
    printf 'Linux snapshot: %s\n' "$1" >&2
    exit 1
}

[[ ${TVM_CI_CONTAINER:-} == 1 ]] || fail 'Требуется TVM_CI_CONTAINER=1.'
[[ ${GITHUB_ACTIONS:-} == true ]] || fail 'Требуется GITHUB_ACTIONS=true.'
[[ $(id -u) == 0 ]] || fail 'Требуется UID 0.'
[[ -f /.dockerenv ]] || fail 'Требуется файл /.dockerenv.'
[[ $(uname -m) == x86_64 ]] || fail 'Требуется архитектура x86_64.'
source /etc/os-release
[[ ${ID:-} == ubuntu ]] || fail 'Требуется ID=ubuntu в /etc/os-release.'
[[ ${VERSION_ID:-} == 24.04 ]] || fail 'Требуется VERSION_ID=24.04 в /etc/os-release.'
[[ -f /src/build/common/ci-tools.json ]] || fail 'Требуется файл /src/build/common/ci-tools.json.'
[[ -f /setup-php/src/scripts/linux.sh ]] || fail 'Требуется файл /setup-php/src/scripts/linux.sh.'
# RU: Только известный одноразовый Ubuntu image; неожиданные apt sources запрещены.
# EN: Only the known ephemeral Ubuntu image; reject unexpected apt sources.
[[ ! -L /etc/apt/sources.list ]] || fail 'Файл /etc/apt/sources.list является символьной ссылкой.'
if [[ -e /etc/apt/sources.list ]]; then
    [[ -f /etc/apt/sources.list ]] || fail 'Требуется обычный файл /etc/apt/sources.list.'
    if [[ -s /etc/apt/sources.list ]]; then
        digest=$(sha256sum < /etc/apt/sources.list) || fail 'Не удалось проверить SHA256 /etc/apt/sources.list.'
        [[ ${digest%% *} == 8ce6407d5487bed332b5fcb4aab26dacf3f516d811c26792efb0c67f06f1958b ]] ||
            fail 'SHA256 /etc/apt/sources.list не совпал с известным файлом комментариев.'
    fi
fi
[[ -d /etc/apt/sources.list.d && ! -L /etc/apt/sources.list.d ]] ||
    fail 'Требуется обычный каталог /etc/apt/sources.list.d.'
[[ -f /etc/apt/sources.list.d/ubuntu.sources && ! -L /etc/apt/sources.list.d/ubuntu.sources ]] ||
    fail 'Требуется обычный файл /etc/apt/sources.list.d/ubuntu.sources.'
shopt -s dotglob
for file in /etc/apt/sources.list.d/*; do
    [[ $file == /etc/apt/sources.list.d/ubuntu.sources ]] ||
        fail "В /etc/apt/sources.list.d/ ожидается только ubuntu.sources, получено $(printf '%q' "${file##*/}")."
done
shopt -u dotglob
cat > /etc/apt/sources.list.d/ubuntu.sources <<'SOURCES'
Types: deb
URIs: https://snapshot.ubuntu.com/ubuntu/20261115T000000Z/
Suites: noble noble-updates noble-security
Components: main universe
Architectures: amd64
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
SOURCES
export DEBIAN_FRONTEND=noninteractive
printf '%s\n' 'Linux snapshot: проверки пройдены, запускается apt-get update.'
apt-get update -o APT::Update::Error-Mode=any
# RU: Проверяем выбранные signed metadata до исполнения устанавливаемых пакетов.
# EN: Check the selected signed metadata before executing installed packages.
(
    cd /var/lib/apt/lists
    sha256sum --check <<'HASHES'
cdb2f31d809f589719a53c6ad15f255b27569c4059542ada282aaa21b8e164b0  snapshot.ubuntu.com_ubuntu_20261115T000000Z_dists_noble_InRelease
1d61be78c673b98f8c94d6a913645ee03f1f28315cc3fe7fc31357b9793266a3  snapshot.ubuntu.com_ubuntu_20261115T000000Z_dists_noble-updates_InRelease
58c274e629044455eff0a76d09c6b30e1b4bebb6253ca67c4e23cdddba4e7f64  snapshot.ubuntu.com_ubuntu_20261115T000000Z_dists_noble-security_InRelease
HASHES
)
apt-get install -y --no-install-recommends python3 ca-certificates curl git sudo gnupg \
    build-essential make pkg-config autoconf automake bison re2c file jq unzip psmisc \
    gcc-13=13.3.0-6ubuntu2~24.04.1 libc6=2.39-0ubuntu8.12 libc6-dev=2.39-0ubuntu8.12 \
    libssl3t64=3.0.13-0ubuntu3.16 libssl-dev=3.0.13-0ubuntu3.16 libgcc-s1=14.2.0-4ubuntu2~24.04.1
python3 - <<'PY'
import importlib.util
import sys
from pathlib import Path
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('bootstrap', '/src/build/ci/bootstrap.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
module.snapshot_evidence(Path('/work/snapshot-inputs'))
module.release.linux_libraries()
PY
# RU: Официальный Linux entrypoint того же закреплённого setup-php.
# Его штатный изменяемый php-builder одобрен владельцем только для CI build PHP.
# EN: Official Linux entrypoint of the same pinned setup-php.
# Its mutable upstream php-builder is owner-approved only for CI build PHP.
export RUNNER=self-hosted RUNNER_TOOL_CACHE=/work/php-tool-cache SHELL=/bin/bash
mkdir -p "$RUNNER_TOOL_CACHE"
bash /setup-php/src/scripts/linux.sh 8.5
python3 - <<'PY'
import importlib.util
import json
import subprocess
import sys
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('release', '/src/build/release.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
module.linux_libraries()
config = json.load(open('/src/build/common/ci-tools.json'))['linux']['packages']
for name, pin in config.items():
    if name != 'bubblewrap':
        version = subprocess.check_output(['dpkg-query', '-W', '-f=${Version}', name], text=True)
        if version != pin['version']:
            raise SystemExit('CI package version mismatch: ' + name)
subprocess.run(['gcc-13', '--version'], check=True)
subprocess.run(['/usr/bin/php8.5', '--version'], check=True)
PY
