#!/usr/bin/env python3
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import shutil
import stat
import struct
import subprocess
import tarfile
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]
INPUTS = json.loads((ROOT / "build/common/inputs.json").read_text())
WINDOWS_CRT = json.loads((ROOT / "build/common/windows-crt.json").read_text())


def checked_input(path, spec):
    if path is None:
        raise ValueError('Нужны --windows-crt-installer и --windows-crt-license')
    path = Path(path).absolute()
    if (any(p.is_symlink() for p in [path, *path.parents]) or not path.is_file()
            or path.resolve().is_relative_to(ROOT)):
        raise ValueError('CRT-вход должен быть обычным файлом вне checkout, без ссылок')
    if digest(path) != spec['sha256'] or ('size' in spec and path.stat().st_size != spec['size']):
        raise ValueError('SHA256/размер CRT-входа не совпал')
    return path


def check_pe_x64(data):
    try:
        offset = struct.unpack_from('<I', data, 60)[0]
        valid = (data[:2] == b'MZ' and data[offset:offset + 4] == b'PE\0\0'
                 and struct.unpack_from('<H', data, offset + 4)[0] == 0x8664
                 and struct.unpack_from('<H', data, offset + 24)[0] == 0x20b)
    except struct.error:
        valid = False
    if not valid:
        raise ValueError('CRT должен быть PE32+ x64')


def check_authenticode_result(result, spec):
    if (result.get('status') != 'Valid' or result.get('subject') != spec['subject']
            or result.get('thumbprint') != spec['thumbprint']
            or result.get('sha256') != spec['sha256']
            or ('version' in spec and result.get('version') != spec['version'])):
        raise ValueError('CRT: подпись Microsoft, версия или SHA256 не совпали')


def verify_authenticode(path, spec):
    native_path = str(path.resolve())
    if platform.system() == 'Linux':
        native_path = subprocess.check_output(['wslpath', '-w', native_path], text=True).strip()
    elif platform.system() != 'Windows':
        raise ValueError('Для CRT нужен native Windows Authenticode (Windows или WSL2 interop)')
    quoted = "'" + native_path.replace("'", "''") + "'"
    script = ("$ErrorActionPreference='Stop'; $ProgressPreference='SilentlyContinue'; $p=" + quoted + ";"
              "$s=Get-AuthenticodeSignature -LiteralPath $p;"
              "@{status=$s.Status.ToString();subject=$s.SignerCertificate.Subject;"
              "thumbprint=$s.SignerCertificate.Thumbprint;"
              "sha256=(Get-FileHash -LiteralPath $p -Algorithm SHA256).Hash.ToLowerInvariant();"
              "version=(Get-Item -LiteralPath $p).VersionInfo.FileVersion}|ConvertTo-Json -Compress")
    encoded = base64.b64encode(script.encode('utf-16le')).decode('ascii')
    result = json.loads(subprocess.check_output(
        ['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', encoded],
        encoding='utf-8', timeout=60))
    check_authenticode_result(result, spec)
    return result


def prepare_windows_crt(installer, license_file):
    installer = checked_input(installer, WINDOWS_CRT['installer'])
    license_file = checked_input(license_file, WINDOWS_CRT['license'])
    # RU: Только чтение подписанных данных; EXE/MSI не исполняются.
    # EN: Read signed data only; never execute the EXE/MSI.
    evidence = {'installer': verify_authenticode(installer, WINDOWS_CRT['installer'])}
    extractor = shutil.which('7z') or shutil.which('7z.exe')
    if not extractor or WINDOWS_CRT['extractor'] not in subprocess.check_output([extractor, 'i'], text=True):
        raise ValueError('Для извлечения CRT нужен существующий 7-Zip 23.01; автоустановки нет')
    with tempfile.TemporaryDirectory(prefix='tvm-crt-', dir=installer.parent) as directory:
        work = Path(directory)
        data = installer.read_bytes()
        spec = WINDOWS_CRT['attached']
        attached = data[spec['offset']:spec['offset'] + spec['size']]
        if hashlib.sha256(attached).hexdigest() != spec['sha256']:
            raise ValueError('Повреждён встроенный CRT container')
        container = work / 'attached.cab'
        container.write_bytes(attached)
        cab = work / 'minimum.cab'
        cab.write_bytes(subprocess.check_output([extractor, 'x', '-so', container, WINDOWS_CRT['cab']['member']], timeout=60))
        checked_input(cab, WINDOWS_CRT['cab'])
        evidence['cab'] = verify_authenticode(cab, WINDOWS_CRT['cab'])
        dll = work / 'vcruntime140.dll'
        dll.write_bytes(subprocess.check_output([extractor, 'x', '-so', cab, WINDOWS_CRT['dll']['member']], timeout=60))
        checked_input(dll, WINDOWS_CRT['dll'])
        check_pe_x64(dll.read_bytes())
        evidence['dll'] = verify_authenticode(dll, WINDOWS_CRT['dll'])
        result = dll.read_bytes()
    # RU: Повторный digest защищает от изменения входов во время проверки.
    # EN: Recheck digests to detect inputs changed during verification.
    checked_input(installer, WINDOWS_CRT['installer'])
    return result, checked_input(license_file, WINDOWS_CRT['license']).read_bytes(), evidence


def install_windows_crt(runtime, prepared):
    dll, license_data, evidence = prepared
    check_pe_x64(dll)
    if (hashlib.sha256(dll).hexdigest() != WINDOWS_CRT['dll']['sha256']
            or hashlib.sha256(license_data).hexdigest() != WINDOWS_CRT['license']['sha256']):
        raise ValueError('Повреждены подготовленные CRT-входы')
    for name in ['installer', 'cab', 'dll']:
        check_authenticode_result(evidence[name], WINDOWS_CRT[name])
    destination = runtime / WINDOWS_CRT['dll']['destination']
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.is_symlink():
        raise ValueError('Ссылка вместо app-local CRT')
    destination.write_bytes(dll)
    notices = runtime / 'notices'
    notices.mkdir(exist_ok=True)
    (notices / 'Microsoft-Visual-C-v14-LICENSE.docx').write_bytes(license_data)
    (notices / 'Microsoft-CRT-NOTICE.txt').write_text(
        'Microsoft Visual C++ v14 runtime ' + WINDOWS_CRT['dll']['version'] + '\n'
        + WINDOWS_CRT['redist_terms'] + '\n' + WINDOWS_CRT['release_gate'] + '\n', encoding='utf-8')
    record = {'pin': WINDOWS_CRT, 'authenticode': evidence}
    (runtime / 'WINDOWS-CRT.json').write_text(json.dumps(record, indent=2) + '\n', encoding='utf-8')
    return record


def verify_windows_reuse(previous, metadata, manifest):
    if metadata.get('target') != 'windows-x64' or metadata.get('inputs') != INPUTS:
        raise ValueError('Windows runtime: неверные provenance inputs/target')
    files = {p.relative_to(previous).as_posix(): p for p in (previous / 'runtime').rglob('*') if p.is_file()}
    if set(files) != {name for name in manifest if name.startswith('runtime/')}:
        raise ValueError('Windows runtime: отсутствует или добавлен файл manifest')
    for name, path in files.items():
        if path.is_symlink() or digest(path) != manifest[name]:
            raise ValueError('Повреждён Windows runtime')
    record = metadata.get('windows_crt')
    if record is not None:
        if record.get('pin') != WINDOWS_CRT:
            raise ValueError('Windows CRT provenance не совпал')
        if json.loads((previous / 'runtime/WINDOWS-CRT.json').read_text()) != record:
            raise ValueError('Windows CRT evidence не совпал с provenance')
        for name in ['installer', 'cab', 'dll']:
            check_authenticode_result(record['authenticode'][name], WINDOWS_CRT[name])
        if digest(previous / 'runtime' / WINDOWS_CRT['dll']['destination']) != WINDOWS_CRT['dll']['sha256']:
            raise ValueError('В Windows runtime отсутствует закреплённый CRT')
    elif any('vcruntime' in name.casefold() or name == 'runtime/WINDOWS-CRT.json' for name in files):
        raise ValueError('CRT без provenance; допустим только старый runtime без CRT')


def run(args, **kwargs):
    subprocess.run([str(x) for x in args], check=True, **kwargs)


def digest(path, algorithm="sha256"):
    return hashlib.new(algorithm, Path(path).read_bytes()).hexdigest()


def extract(archive, dest):
    dest.mkdir(parents=True, exist_ok=True)
    entries = []
    if zipfile.is_zipfile(archive):
        with zipfile.ZipFile(archive) as z:
            for item in z.infolist():
                mode = item.external_attr >> 16
                entries.append((item.filename, item.is_dir(), mode, lambda i=item: z.read(i)))
            unpack(entries, dest)
    else:
        with tarfile.open(archive) as t:
            for item in t.getmembers():
                if not (item.isdir() or item.isfile()):
                    raise ValueError("Ссылка или специальный файл в архиве")
                entries.append((item.name, item.isdir(), item.mode, lambda i=item: t.extractfile(i).read()))
            unpack(entries, dest)


def unpack(entries, dest):
    seen = set()
    # RU: Сначала проверяем весь список, затем пишем только обычные файлы.
    # EN: Validate the entire member list before writing regular files.
    for name, directory, mode, _ in entries:
        p = PurePosixPath(name)
        if (not name or p.is_absolute() or ".." in p.parts or "\\" in name
                or str(p) != name.rstrip('/')
                or ":" in name or (stat.S_IFMT(mode) not in (0, stat.S_IFREG, stat.S_IFDIR))
                or name.casefold() in seen or any(ord(c) < 32 for c in name)):
            raise ValueError("Небезопасный путь в upstream-архиве")
        seen.add(name.casefold())
    for name, directory, mode, reader in entries:
        path = dest / name
        if directory:
            path.mkdir(parents=True, exist_ok=True)
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(reader())
            path.chmod(0o755 if mode & 0o111 else 0o644)


def fetch(key, cache, offline):
    spec = INPUTS[key]
    path = cache / spec["url"].rsplit("/", 1)[1]
    if not path.exists():
        if offline:
            raise ValueError("Нет проверенного входного файла: " + path.name)
        run(["curl", "--fail", "--location", "--proto", "=https", "--proto-redir", "=https",
             "--tlsv1.2", "--max-time", "300", "--output", path, spec["url"]])
    if path.is_symlink() or digest(path) != spec["sha256"]:
        raise ValueError("SHA256 не совпал: " + path.name)
    if "sha3_256" in spec and digest(path, "sha3_256") != spec["sha3_256"]:
        raise ValueError("SHA3-256 не совпал")
    return path


def copy_file(source, target):
    if source.is_symlink() or not source.is_file():
        raise ValueError("Нельзя упаковать ссылку или отсутствующий файл: " + source.name)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, target)
    target.chmod(0o644)


def verify_pgp(key, fingerprint, work, cache, offline):
    keyring = work / (key + '-keys.bin')
    run(['gpg', '--batch', '--yes', '--dearmor', '--output', keyring, fetch(key + '-keyring', cache, offline)])
    result = subprocess.check_output(['gpgv', '--status-fd', '1', '--keyring', keyring,
                                     fetch(key + '-signature', cache, offline), fetch(key, cache, offline)], text=True)
    if ('[GNUPG:] VALIDSIG ' + fingerprint + ' ') not in result:
        raise ValueError('Подпись не принадлежит закреплённому release manager')


def verify_gum(work, cache, offline, target):
    host = {'Linux': 'linux', 'Darwin': 'macos', 'Windows': 'windows'}[platform.system()]
    cosign = fetch('cosign-' + host, cache, offline)
    cosign.chmod(0o755)
    checksums = fetch('gum-checksums', cache, offline)
    run([cosign, 'verify-blob', '--trusted-root', fetch('sigstore-root', cache, offline),
         '--bundle', fetch('gum-signature', cache, offline),
         '--certificate-identity', 'https://github.com/charmbracelet/meta/.github/workflows/goreleaser.yml@refs/heads/main',
         '--certificate-oidc-issuer', 'https://token.actions.githubusercontent.com', checksums])
    artifact = INPUTS['gum-' + target]
    if artifact['sha256'] + '  ' + artifact['url'].rsplit('/', 1)[1] not in checksums.read_text().splitlines():
        raise ValueError('Артефакт отсутствует в подписанном manifest Gum')


def verify_linux_libraries(libraries, expected):
    if libraries != expected:
        differences = [
            name + ': ожидаемый SHA256=' + expected.get(name, '<отсутствует>')
            + ', фактический SHA256=' + libraries.get(name, '<отсутствует>')
            for name in sorted(libraries.keys() | expected.keys())
            if libraries.get(name) != expected.get(name)
        ]
        raise ValueError('Комплект Linux-библиотек отличается от закреплённого toolchain:\n'
                         + '\n'.join(differences))


def copy_linux_libraries(output, runtime, expected):
    (runtime / 'lib').mkdir()
    originals, libraries, after_copy = {}, {}, {}
    for path in re.findall(r'(/[^\s()]+)', output):
        library = Path(path)
        if library.name in libraries:
            raise ValueError('Повтор Linux-библиотеки: ' + library.name)
        destination = runtime / ('ld-linux-x86-64.so.2' if library.name.startswith('ld-linux')
                                 else 'lib/' + library.name)
        originals[library.name] = digest(library)
        shutil.copyfile(library, destination)
        destination.chmod(0o755)
        libraries[library.name] = digest(destination)
        after_copy[library.name] = digest(library)
    verify_linux_libraries(libraries, expected)
    verify_linux_libraries(originals, expected)
    verify_linux_libraries(after_copy, originals)
    (runtime / 'linux-libraries.json').write_text(json.dumps(libraries, indent=2) + '\n')


def verify_linux_runtime(runtime):
    expected = json.loads((ROOT / 'build/common/linux-libraries.json').read_text())
    recorded = json.loads((runtime / 'linux-libraries.json').read_text())
    verify_linux_libraries(recorded, expected)
    lib = runtime / 'lib'
    if lib.is_symlink() or not lib.is_dir():
        raise ValueError('Отсутствует обычный каталог Linux-библиотек')
    files = list(lib.iterdir())
    loader = runtime / 'ld-linux-x86-64.so.2'
    if loader.exists() or loader.is_symlink():
        files.append(loader)
    libraries = {}
    for file in files:
        if (file.is_symlink() or not file.is_file() or file.name in libraries
                or (file.name == loader.name and file != loader)):
            raise ValueError('Недопустимый файл Linux-библиотеки: ' + file.name)
        libraries[file.name] = digest(file)
    verify_linux_libraries(libraries, expected)


def verify_unix_reuse(previous, metadata, manifest, target):
    if (target not in ('linux-x86_64', 'macos-arm64') or not isinstance(metadata, dict)
            or metadata.get('target') != target or metadata.get('inputs') != INPUTS):
        raise ValueError('Unix runtime: неверные provenance inputs/target')
    runtime = previous / 'runtime'
    if runtime.is_symlink() or not runtime.is_dir() or not isinstance(manifest, dict):
        raise ValueError('Unix runtime: отсутствует runtime или неверный manifest')
    files = {}
    for file in runtime.rglob('*'):
        if file.is_symlink() or not (file.is_dir() or file.is_file()):
            raise ValueError('Unix runtime: ссылка или специальный файл')
        if file.is_file():
            files[file.relative_to(previous).as_posix()] = file
    if set(files) != {name for name in manifest if name.startswith('runtime/')}:
        raise ValueError('Unix runtime: отсутствует или добавлен файл manifest')
    for name, file in files.items():
        if digest(file) != manifest[name]:
            raise ValueError('Повреждён Unix runtime: ' + name)
    if target == 'linux-x86_64':
        verify_linux_runtime(runtime)


def verify_macos_dependencies(output):
    lines = output.splitlines()
    if len(lines) < 2 or not lines[0].endswith(':'):
        raise ValueError('Некорректный вывод otool -L')
    for line in lines[1:]:
        match = re.fullmatch(
            r'\s+(.+?) \(compatibility version \d+\.\d+\.\d+, '
            r'current version \d+\.\d+\.\d+(?:, (?:weak|reexport|upward|lazy))*\)', line)
        if match is None:
            raise ValueError('Некорректная зависимость otool -L: ' + line)
        name = match[1]
        path = PurePosixPath(name)
        if (not name.startswith(('/usr/lib/', '/System/')) or '..' in path.parts
                or str(path) != name or any(ord(c) < 32 for c in name)):
            raise ValueError('В PHP осталась внешняя dylib: ' + name)


def source_runtime(work, runtime, cache, offline, target):
    verify_pgp('php', 'D95C03BC702BE9515344AE3374E44BC9067701A5', work, cache, offline)
    if target == 'linux-x86_64':
        verify_pgp('curl', '27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2', work, cache, offline)
    for key in ["php"] + (["curl", "sqlite"] if target == "linux-x86_64" else []):
        extract(fetch(key, cache, offline), work / key)
    php = work / "php/php-8.5.12"
    prefix = work / "deps"
    env = os.environ.copy()
    env.pop("CONFIG_SITE", None)
    configure = ["./configure", "--disable-all", "--enable-cli", "--disable-cgi",
                 "--disable-phpdbg", "--enable-filter", "--enable-mbstring", "--disable-mbregex",
                 "--enable-pdo", "--with-pdo-sqlite", "--with-curl",
                 "--without-pear", "--without-iconv", "--with-config-file-path=/nonexistent",
                 "--with-config-file-scan-dir=/nonexistent"]
    notices = runtime / "notices"
    notices.mkdir()
    if target == "linux-x86_64":
        sqlite = work / "sqlite/sqlite-autoconf-3540100"
        run(["./configure", "--prefix=" + str(prefix), "--disable-shared", "--enable-static",
             "--disable-readline"], cwd=sqlite)
        run(["make", "-j2"], cwd=sqlite)
        run(["make", "install"], cwd=sqlite)
        curl = work / "curl/curl-8.23.0"
        run(["./configure", "--prefix=" + str(prefix), "--disable-shared", "--enable-static",
             "--with-openssl", "--without-libpsl", "--without-libidn2", "--without-zlib",
             "--without-brotli", "--without-zstd", "--without-nghttp2", "--without-libssh2",
             "--disable-ldap", "--disable-ldaps", "--disable-docs", "--disable-manual"], cwd=curl)
        run(["make", "-j2"], cwd=curl)
        run(["make", "install"], cwd=curl)
        env["PKG_CONFIG_PATH"] = str(prefix / "lib/pkgconfig")
        env["CFLAGS"] = "-O2 -fPIC -march=x86-64 -mtune=generic"
        copy_file(curl / "COPYING", notices / "curl-COPYING")
        (notices / "sqlite.txt").write_text("SQLite 3.54.1 is in the public domain.\nhttps://sqlite.org/copyright.html\n")
    else:
        env["MACOSX_DEPLOYMENT_TARGET"] = "14.0"
        sdk = subprocess.check_output(["xcrun", "--show-sdk-path"], text=True).strip()
        pc = work / "pkgconfig"
        pc.mkdir()
        env['SDKROOT'] = sdk
        for name, library, header, macro in [('libcurl', 'curl', 'curl/curlver.h', 'LIBCURL_VERSION'),
                                             ('sqlite3', 'sqlite3', 'sqlite3.h', 'SQLITE_VERSION')]:
            header_text = (Path(sdk) / 'usr/include' / header).read_text()
            version = re.search(r'#define\s+' + macro + r'\s+"([0-9.]+)"', header_text).group(1)
            (pc / (name + ".pc")).write_text(
                "Name: " + name + "\nDescription: macOS SDK library\nVersion: " + version
                + "\nLibs: -l" + library + "\nCflags: -I" + sdk + "/usr/include\n")
        env["PKG_CONFIG_PATH"] = str(pc)
        env["CFLAGS"] = "-O2 -arch arm64 -mmacosx-version-min=14.0"
    run(configure, cwd=php, env=env)
    run(["make", "-j2"], cwd=php, env=env)
    copy_file(php / "sapi/cli/php", runtime / "php")
    (runtime / "php").chmod(0o755)
    for name in ["LICENSE", "Zend/LICENSE"]:
        copy_file(php / name, notices / ("PHP-" + name.replace("/", "-")))
    if target == "linux-x86_64":
        output = subprocess.check_output(["ldd", runtime / "php"], text=True)
        expected = json.loads((ROOT / 'build/common/linux-libraries.json').read_text())
        copy_linux_libraries(output, runtime, expected)
        for package in ["libc6", "libssl3t64", "libgcc-s1"]:
            copy_file(Path("/usr/share/doc") / package / "copyright", notices / (package + "-copyright"))
        (notices / "linux-source.txt").write_text(
            "Build libraries: Ubuntu 24.04 amd64, glibc/OpenSSL.\n"
            "Corresponding source: https://archive.ubuntu.com/ubuntu/pool/main/g/glibc/\n"
            "https://archive.ubuntu.com/ubuntu/pool/main/o/openssl/\n")
    else:
        output = subprocess.check_output(["otool", "-L", runtime / "php"], text=True)
        verify_macos_dependencies(output)
        run(["codesign", "--force", "--sign", "-", runtime / "php"])


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--target", choices=["linux-x86_64", "windows-x64", "macos-arm64"], required=True)
    parser.add_argument("--cache", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--build-php", required=True)
    parser.add_argument("--composer", required=True)
    parser.add_argument("--offline", action="store_true")
    parser.add_argument("--cross-windows", action="store_true")
    parser.add_argument("--runtime-bundle", type=Path)
    parser.add_argument("--runtime-sha256")
    parser.add_argument("--windows-crt-installer", type=Path)
    parser.add_argument("--windows-crt-license", type=Path)
    args = parser.parse_args()
    expected = {"linux-x86_64": ("Linux", "x86_64"), "windows-x64": ("Windows", "AMD64"), "macos-arm64": ("Darwin", "arm64")}
    cross = args.cross_windows and args.target == "windows-x64" and platform.system() == "Linux"
    if not cross and (platform.system(), platform.machine()) != expected[args.target]:
        raise ValueError("Сборка и smoke должны выполняться на целевой ОС/архитектуре")
    prepared_crt = (prepare_windows_crt(args.windows_crt_installer, args.windows_crt_license)
                    if args.target == 'windows-x64' else None)
    for directory in [args.cache, args.output_dir]:
        if directory.resolve().is_relative_to(ROOT) or directory.is_symlink():
            raise ValueError("Рабочий каталог должен быть вне репозитория")
        directory.mkdir(parents=True, exist_ok=True)
    work = Path(tempfile.mkdtemp(prefix="tvm-build-", dir=args.output_dir))
    package = work / "telegram-vacancy-monitor"
    runtime = package / "runtime"
    runtime.mkdir(parents=True)
    target = args.target.split("-")[0]
    host = {'Linux': 'linux', 'Darwin': 'macos', 'Windows': 'windows'}[platform.system()]
    toolchain = work / 'toolchain'
    extract(fetch('go-' + host, args.cache, args.offline), toolchain)
    go = toolchain / 'go/bin' / ('go.exe' if host == 'windows' else 'go')
    go.chmod(0o755)
    verify_gum(work, args.cache, args.offline, target)
    gumdir = work / "gum"
    extract(fetch("gum-" + target, args.cache, args.offline), gumdir)
    gum = next(gumdir.rglob("gum.exe" if target == "windows" else "gum"))
    copy_file(gum, runtime / gum.name)
    (runtime / gum.name).chmod(0o755)
    copy_file(next(gumdir.rglob("LICENSE")), runtime / "GUM-LICENSE.txt")
    copy_file(fetch("ca", args.cache, args.offline), runtime / "cacert.pem")
    if args.runtime_bundle:
        if target == 'windows' and (args.runtime_bundle.is_symlink() or not args.runtime_bundle.is_file()):
            raise ValueError('Runtime archive должен быть обычным файлом')
        if target != 'windows':
            verify_pgp('php', 'D95C03BC702BE9515344AE3374E44BC9067701A5', work, args.cache, args.offline)
        if target == 'linux':
            verify_pgp('curl', '27EDEAF22F3ABCEB50DB9A125CC908FDB71E12C2', work, args.cache, args.offline)
        if digest(args.runtime_bundle) != args.runtime_sha256:
            raise ValueError('SHA256 кеша runtime не совпал')
        previous = work / 'previous'
        extract(args.runtime_bundle, previous)
        previous = previous / 'telegram-vacancy-monitor'
        metadata = json.loads((previous / 'runtime/PROVENANCE.json').read_text())
        if target == 'windows' and (metadata['target'] != args.target
                                    or any(INPUTS.get(k) != v for k, v in metadata['inputs'].items())):
            raise ValueError('Runtime собран для других входов или платформы')
        manifest = json.loads((previous / 'SHA256SUMS.json').read_text())
        if target == 'windows':
            verify_windows_reuse(previous, metadata, manifest)
            for file in (previous / 'runtime').rglob('*'):
                if file.is_file() and digest(file) != manifest[file.relative_to(previous).as_posix()]:
                    raise ValueError('Повреждён кеш runtime')
        else:
            verify_unix_reuse(previous, metadata, manifest, args.target)
        shutil.copytree(previous / 'runtime', runtime, dirs_exist_ok=True)
        if target != 'windows':
            verify_unix_reuse(package, metadata, manifest, args.target)
    elif target == "windows":
        extract(fetch("php-windows", args.cache, args.offline), runtime / "php")
        for ini in (runtime / "php").glob("php.ini*"):
            ini.unlink()
    else:
        source_runtime(work, runtime, args.cache, args.offline, args.target)
    if target == 'windows':
        crt_record = install_windows_crt(runtime, prepared_crt)
    if target != 'windows':
        php_notices = work / 'php-notices'
        extract(fetch('php', args.cache, args.offline), php_notices)
        for file in (php_notices / 'php-8.5.12').rglob('*'):
            if file.is_file() and file.name.upper().startswith(('LICENSE', 'COPYING', 'NOTICE')):
                copy_file(file, runtime / 'notices/php-components' / file.relative_to(php_notices / 'php-8.5.12'))
    if target == 'linux':
        for name in ['Apache-2.0', 'LGPL-2.1', 'GPL-3', 'GPL-2', 'LGPL-3', 'MPL-2.0']:
            copy_file(Path('/usr/share/common-licenses') / name, runtime / 'notices/common-licenses' / name)
    tracked = subprocess.check_output(["git", "-C", ROOT, "ls-files", "-z", "--", "src"]).decode().split("\0")
    files = [p for p in tracked if p.endswith(".php") and p != 'src/Application/WebhookReceiver.php']
    files += ["bin/bot.php", "bin/vacancy-monitor.sh", "composer.json", "composer.lock",
              "config/bot.example.ini", "config/profile/README.md", "config/profile/instructions.example.md",
              "docs/guides/local-gum.md", "docs/guides/vds-webhook.md"]
    for tree in ["src/ui/gum", "src/ui/windows"]:
        files += [p.relative_to(ROOT).as_posix() for p in (ROOT / tree).rglob("*") if p.is_file()]
    for name in sorted(set(files)):
        copy_file(ROOT / name, package / name)
    lock = json.loads((package / 'composer.lock').read_text())
    if lock['packages'] or lock['packages-dev']:
        raise ValueError('Для новых Composer-зависимостей нужен отдельный проверенный build contract')
    composer_env = os.environ.copy()
    composer_env['COMPOSER_DISABLE_NETWORK'] = '1'
    run([args.build_php, args.composer, "--working-dir=" + str(package), "dump-autoload", "--no-dev",
         "--no-scripts", "--no-plugins", "--no-interaction", "--optimize"], env=composer_env)
    exe = package / ("vacancy-monitor.exe" if target == "windows" else "vacancy-monitor")
    env = os.environ.copy()
    env.update({"CGO_ENABLED": "0", "GOTOOLCHAIN": "local"})
    env.update({'GOROOT': str(toolchain / 'go'), 'GOENV': 'off', 'GOWORK': 'off',
                'GOPATH': str(work / 'go-path'), 'GOPROXY': 'off', 'GOSUMDB': 'off', 'GOTELEMETRY': 'off'})
    env.pop('GOFLAGS', None)
    if cross:
        env.update({"GOOS": "windows", "GOARCH": "amd64"})
    env["GOCACHE"] = str(work / "go-cache")
    run([go, "build", "-trimpath", "-buildvcs=false", "-ldflags=-buildid=", "-o", exe, "."],
        cwd=ROOT / "src/launcher", env=env)
    provenance = {"target": args.target, "inputs": INPUTS, "go": subprocess.check_output([go, "version"], text=True, env=env).strip(),
                  "native_smoke": "NOT VERIFIED: run platform smoke before acceptance",
                  "source_sha256": {p: digest(ROOT / p) for p in sorted(set(files))},
                  "host": platform.platform()}
    if target == 'windows':
        provenance['windows_crt'] = crt_record
    provenance['build_sha256'] = {p.relative_to(ROOT).as_posix(): digest(p)
                                for tree in ['build', 'src/launcher']
                                for p in sorted((ROOT / tree).rglob('*'))
                                if p.is_file() and p.suffix in ['.go', '.mod', '.py', '.json', '.sh', '.ps1']}
    (runtime / "PROVENANCE.json").write_text(json.dumps(provenance, ensure_ascii=False, indent=2) + "\n")
    goroot = str(toolchain / 'go')
    copy_file(Path(goroot) / 'LICENSE', runtime / "GO-LICENSE.txt")
    manifest = {p.relative_to(package).as_posix(): digest(p) for p in sorted(package.rglob("*")) if p.is_file()}
    (package / "SHA256SUMS.json").write_text(json.dumps(manifest, indent=2) + "\n")
    base = work / ("telegram-vacancy-monitor-" + args.target)
    if target == "windows":
        archive = Path(shutil.make_archive(str(base), "zip", work, package.name))
    else:
        archive = base.with_suffix(".tar.gz")
        with tarfile.open(archive, "w:gz") as tar:
            tar.add(package, arcname=package.name)
    archive.with_name(archive.name + ".sha256").write_text(digest(archive) + "  " + archive.name + "\n")
    print("Архив: " + str(archive))


if __name__ == "__main__":
    main()
