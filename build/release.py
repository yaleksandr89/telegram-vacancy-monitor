#!/usr/bin/env python3
"""RU: Единый интерфейс сборки; упаковка принадлежит common/package.py.
EN: Unified build interface; common/package.py owns packaging.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[1]
TARGETS = {"linux": "linux-x86_64", "windows": "windows-x64", "macos": "macos-arm64"}
WORKFLOW = ".github/workflows/standalone-bundles.yml"


def selection(value):
    names = list(TARGETS) if value == "all" else value.split(",")
    if not names or any(n not in TARGETS for n in names) or len(set(names)) != len(names):
        raise argparse.ArgumentTypeError("Нужны linux, windows, macos без повторов, их набор через запятую или all")
    return [name for name in TARGETS if name in names]


def sha256(path):
    result = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            result.update(chunk)
    return result.hexdigest()


def external(path):
    path = Path(path).absolute()
    if any(p.is_symlink() for p in [path, *path.parents]) or path.resolve().is_relative_to(ROOT):
        raise ValueError("Нужен путь вне checkout без symlink: " + str(path))
    return path.resolve()


def executable(value):
    path = shutil.which(value)
    if path is None:
        raise ValueError("Не найден исполняемый файл: " + value)
    return str(Path(path).resolve())


def output(command, **kwargs):
    return subprocess.check_output([str(a) for a in command], text=True, timeout=60, **kwargs).strip()


def host_targets(system, machine, wsl=False):
    if (system, machine) == ("Linux", "x86_64"):
        return {"linux", "windows"} if wsl else {"linux"}
    return {("Windows", "AMD64"): {"windows"}, ("Darwin", "arm64"): {"macos"}}.get((system, machine), set())


def windows_probe():
    script = ("$ErrorActionPreference='Stop'; "
              "if ([System.Runtime.InteropServices.RuntimeInformation]::OSArchitecture.ToString() -ne 'X64') { exit 1 }; "
              "'tvm-windows-x64'")
    encoded = base64.b64encode(script.encode("utf-16le")).decode("ascii")
    return output([executable("powershell.exe"), "-NoProfile", "-NonInteractive", "-EncodedCommand", encoded]) == "tvm-windows-x64"


def wsl_interop():
    if (platform.system(), platform.machine()) != ("Linux", "x86_64"):
        return False
    try:
        ubuntu = 'ID=ubuntu' in Path("/etc/os-release").read_text().splitlines()
        return (ubuntu and "wsl2" in platform.release().lower()
                and shutil.which("wslpath") is not None and windows_probe())
    except (OSError, ValueError, subprocess.SubprocessError):
        return False


def filename(target):
    return "telegram-vacancy-monitor-" + TARGETS[target] + (".zip" if target == "windows" else ".tar.gz")


def provenance(archive):
    member = "telegram-vacancy-monitor/runtime/PROVENANCE.json"
    if zipfile.is_zipfile(archive):
        with zipfile.ZipFile(archive) as stream:
            entries = [i for i in stream.infolist() if i.filename == member]
            if (len(entries) != 1 or entries[0].file_size > 4 * 1024 * 1024
                    or stat.S_IFMT(entries[0].external_attr >> 16) not in (0, stat.S_IFREG)):
                raise ValueError("Некорректный provenance ZIP")
            return json.loads(stream.read(entries[0]))
    with tarfile.open(archive, "r:gz") as stream:
        entries = [i for i in stream if i.name == member]
        if len(entries) != 1 or not entries[0].isfile() or entries[0].size > 4 * 1024 * 1024:
            raise ValueError("Некорректный provenance TAR")
        return json.loads(stream.extractfile(entries[0]).read())


def check_provenance(record, target):
    inputs = json.loads((ROOT / "build/common/inputs.json").read_text())
    if not isinstance(record, dict) or record.get("target") != TARGETS[target] or record.get("inputs") != inputs:
        raise ValueError("Provenance: target/inputs не совпали")


def check_source_sha(expected):
    if not re.fullmatch(r"[0-9a-f]{40}", expected):
        raise ValueError("Нужен полный Git SHA из 40 строчных hex-символов")
    if output(["git", "--no-optional-locks", "-C", ROOT, "rev-parse", "HEAD"]) != expected:
        raise ValueError("HEAD не совпал с выбранным source SHA")
    if output(["git", "--no-optional-locks", "-C", ROOT, "status", "--porcelain", "--untracked-files=all"]):
        raise ValueError("Для привязки к source SHA нужен чистый checkout")


def verify_artifact(archive, target, source_sha=None):
    archive = Path(archive)
    sidecar = archive.with_name(archive.name + ".sha256")
    if archive.name != filename(target) or not archive.is_file() or archive.is_symlink() or sidecar.is_symlink():
        raise ValueError("Неверное имя или тип итогового архива")
    expected = sha256(archive) + "  " + archive.name + "\n"
    if sidecar.read_text(encoding="ascii") != expected:
        raise ValueError("SHA256 sidecar не совпал с архивом/именем")
    record = provenance(archive)
    check_provenance(record, target)
    for key in ("source_sha256", "build_sha256"):
        hashes = record.get(key)
        if not isinstance(hashes, dict) or not hashes:
            raise ValueError("Provenance: отсутствуют хеши " + key)
        for name, digest in hashes.items():
            path = Path(name)
            if path.is_absolute() or ".." in path.parts or "\\" in name or ":" in name:
                raise ValueError("Provenance: небезопасный путь")
            if source_sha:
                data = subprocess.check_output(["git", "--no-optional-locks", "-C", str(ROOT), "show", source_sha + ":" + name], timeout=60)
                actual = hashlib.sha256(data).hexdigest()
            else:
                actual = sha256(ROOT / path)
            if actual != digest:
                raise ValueError("Provenance: исходник не совпал: " + name)
    return record


def linux_libraries():
    pins = json.loads((ROOT / "build/common/linux-libraries.json").read_text())
    for name, digest in pins.items():
        path = Path("/lib64" if name.startswith("ld-linux") else "/lib/x86_64-linux-gnu") / name
        if sha256(path) != digest:
            raise ValueError("Linux-библиотека отличается от pin: " + name)


def required_inputs(target, reuse):
    host = {"Linux": "linux", "Darwin": "macos", "Windows": "windows"}[platform.system()]
    keys = {"go-" + host, "cosign-" + host, "gum-" + target, "gum-checksums", "gum-signature", "sigstore-root", "ca"}
    if target == "windows":
        if not reuse:
            keys.add("php-windows")
    else:
        keys.update({"php", "php-signature", "php-keyring"})
        if target == "linux":
            keys.update({"curl", "curl-signature", "curl-keyring"})
            if not reuse:
                keys.add("sqlite")
    return keys


def preflight(args):
    potential = host_targets(platform.system(), platform.machine(), True)
    if not set(args.targets).issubset(potential):
        raise ValueError("Этот хост не поддерживает запрошенный набор. Используйте единый manual workflow "
                         + WORKFLOW + " после его появления в default branch.")
    cross = "windows" in args.targets and platform.system() == "Linux" and wsl_interop()
    supported = host_targets(platform.system(), platform.machine(), cross)
    if not set(args.targets).issubset(supported):
        raise ValueError("На этом хосте доступны: " + (", ".join(sorted(supported)) or "нет целей")
                         + ". Для другого набора используйте единый manual workflow " + WORKFLOW
                         + " после его появления в default branch.")
    if platform.system() == "Windows" and not windows_probe():
        raise ValueError("Нужна native Windows x64")
    args.output_dir = external(args.output_dir)
    args.cache = external(args.cache) if args.cache else None
    for directory in (args.output_dir, args.cache):
        if directory and directory.exists() and not directory.is_dir():
            raise ValueError("Нужен внешний каталог: " + str(directory))
    if args.source_sha:
        check_source_sha(args.source_sha)
    for target in TARGETS:
        bundle, digest = getattr(args, target + "_runtime_bundle"), getattr(args, target + "_runtime_sha256")
        if (bundle or digest) and target not in args.targets:
            raise ValueError("Runtime задан для невыбранной цели: " + target)
        if bool(bundle) != bool(digest) or (digest and not re.fullmatch(r"[0-9a-f]{64}", digest)):
            raise ValueError("Нужны парные --" + target + "-runtime-bundle/--" + target + "-runtime-sha256")
        if bundle:
            bundle = external(bundle)
            if not bundle.is_file() or sha256(bundle) != digest:
                raise ValueError("Runtime SHA256 не совпал: " + target)
            check_provenance(provenance(bundle), target)
            setattr(args, target + "_runtime_bundle", bundle)
    crt = (args.windows_crt_installer, args.windows_crt_license)
    if "windows" in args.targets:
        pins = json.loads((ROOT / "build/common/windows-crt.json").read_text())
        for value, key in zip(crt, ("installer", "license")):
            if value is None:
                raise ValueError("Для Windows нужны --windows-crt-installer и --windows-crt-license")
            path = external(value)
            if not path.is_file() or sha256(path) != pins[key]["sha256"] or ("size" in pins[key] and path.stat().st_size != pins[key]["size"]):
                raise ValueError("Windows CRT: SHA256/размер не совпал: " + key)
        extractor = executable("7z") if shutil.which("7z") else executable("7z.exe")
        if "7-Zip 23.01" not in output([extractor, "i"]):
            raise ValueError("Для CRT нужен именно 7-Zip 23.01")
    elif any(crt):
        raise ValueError("CRT-параметры допустимы только при выборе windows")
    tools = {"git", "curl"}
    if set(args.targets) & {"linux", "macos"}:
        tools.update({"gpg", "gpgv"})
    for target in args.targets:
        if target != "windows" and not getattr(args, target + "_runtime_bundle"):
            tools.update({"cc", "make", "pkg-config"})
            if target == "linux":
                linux_libraries()
                for name in ("stdio.h", "openssl/ssl.h"):
                    if not (Path("/usr/include") / name).is_file():
                        raise ValueError("Нет Linux dev header: " + name)
                for name in ("libc6", "libssl3t64", "libgcc-s1"):
                    path = Path("/usr/share/doc") / name / "copyright"
                    if not path.is_file() or path.is_symlink():
                        raise ValueError("Нет обычного файла notices: " + str(path))
            else:
                tools.update({"xcrun", "otool", "codesign"})
                sdk = Path(output(["xcrun", "--show-sdk-path"]))
                for name in ("curl/curlver.h", "sqlite3.h"):
                    if not (sdk / "usr/include" / name).is_file():
                        raise ValueError("В Mac SDK нет заголовка: " + name)
        if target == "linux":
            for name in ("Apache-2.0", "LGPL-2.1", "GPL-3", "GPL-2", "LGPL-3", "MPL-2.0"):
                path = Path("/usr/share/common-licenses") / name
                if not path.is_file() or path.is_symlink():
                    raise ValueError("Нет обычного файла лицензии: " + str(path))
    for tool in sorted(tools):
        executable(tool)
    args.build_php = executable(args.build_php)
    args.composer = str(Path(args.composer).resolve())
    if not Path(args.composer).is_file():
        raise ValueError("Не найден Composer PHAR")
    output([args.build_php, "--version"])
    probe = json.loads(output([args.build_php, "-r", 'echo json_encode([PHP_VERSION_ID,PHP_SAPI,PHP_INT_SIZE,extension_loaded("phar"),extension_loaded("json"),extension_loaded("filter"),extension_loaded("hash"),php_uname("m")]);']))
    if not (80500 <= probe[0] < 80600 and probe[1:] == ["cli", 8, True, True, True, True, platform.machine()]):
        raise ValueError("Нужен сборочный PHP 8.5 CLI x64/arm64 с phar/json/filter/hash")
    if not output([args.build_php, args.composer, "--no-plugins", "--version"]).startswith("Composer version 2."):
        raise ValueError("Нужен Composer 2")
    if args.offline and args.cache is None:
        raise ValueError("Для --offline нужен явный --cache")
    if args.cache:
        inputs = json.loads((ROOT / "build/common/inputs.json").read_text())
        for target in args.targets:
            for key in required_inputs(target, getattr(args, target + "_runtime_bundle")):
                spec = inputs[key]
                path = args.cache / spec["url"].rsplit("/", 1)[1]
                if not path.exists() and not path.is_symlink():
                    if args.offline:
                        raise ValueError("Offline: нет проверенного входа " + key)
                    continue
                if path.is_symlink() or not path.is_file() or sha256(path) != spec["sha256"]:
                    raise ValueError("Cache: SHA256/тип входа не совпал: " + key)
                if "sha3_256" in spec and hashlib.sha3_256(path.read_bytes()).hexdigest() != spec["sha3_256"]:
                    raise ValueError("Cache: SHA3-256 не совпал: " + key)
    return cross


def package_command(args, target, cache, destination, cross):
    command = [sys.executable, str(ROOT / "build/common/package.py"), "--target", TARGETS[target],
               "--build-php", args.build_php, "--composer", args.composer,
               "--cache", str(cache), "--output-dir", str(destination)]
    if args.offline:
        command.append("--offline")
    if target == "windows":
        command += ["--windows-crt-installer", str(args.windows_crt_installer.resolve()),
                    "--windows-crt-license", str(args.windows_crt_license.resolve())]
        if cross:
            command.append("--cross-windows")
    bundle = getattr(args, target + "_runtime_bundle")
    if bundle:
        command += ["--runtime-bundle", str(bundle), "--runtime-sha256", getattr(args, target + "_runtime_sha256")]
    return command


def package_failure_context(log):
    # RU: Читаем ограниченный хвост traceback. Выводим только известный тип и номер строки.
    # EN: Read a bounded traceback tail. Emit only a known type and line number.
    try:
        with log.open("rb") as stream:
            stream.seek(max(0, stream.seek(0, os.SEEK_END) - 8192))
            tail = stream.read(8192)
    except OSError:
        return ""
    traceback = tail.rsplit(b"Traceback (most recent call last):", 1)[-1]
    frames = re.findall(rb'^  File "[^"\r\n]*[/\\]common[/\\]package\.py", line ([0-9]{1,6}), in ',
                        traceback, re.MULTILINE)
    if not frames:
        return ""
    last_line = traceback.rstrip().rsplit(b"\n", 1)[-1]
    error = re.match(rb"^(?:json\.decoder\.|subprocess\.|zipfile\.|tarfile\.)?"
                     rb"(UnicodeDecodeError|UnicodeEncodeError|JSONDecodeError|CalledProcessError|TimeoutExpired|"
                     rb"ValueError|KeyError|TypeError|FileNotFoundError|PermissionError|OSError|BadZipFile|ReadError)"
                     rb"(?::|$)", last_line)
    if error is None:
        return " (package.py:" + frames[-1].decode("ascii") + ")"
    return " (" + error[1].decode("ascii") + ", package.py:" + frames[-1].decode("ascii") + ")"


def parser():
    result = argparse.ArgumentParser(description="Сборка выбранных standalone-поставок. Полный выпуск требует всех трёх ОС.")
    result.add_argument("--targets", type=selection, required=True, metavar="linux,windows,macos|all")
    result.add_argument("--build-php", required=True, help="Исполняемый сборочный PHP 8.5 CLI")
    result.add_argument("--composer", required=True, type=Path, help="Доверенный Composer 2 PHAR")
    result.add_argument("--cache", type=Path, help="Внешний cache; по умолчанию новый cache внутри сессии")
    result.add_argument("--output-dir", type=Path, default=Path(tempfile.gettempdir()).resolve(), help="Внешний родитель уникальной сессии")
    result.add_argument("--offline", action="store_true", help="Только проверенные cache inputs; Authenticode может обращаться к Windows trust services")
    result.add_argument("--preflight", action="store_true", help="Проверить условия без записи, загрузок и сборки")
    result.add_argument("--source-sha", help="Точный Git SHA; требует чистый checkout и сверяет provenance с commit")
    result.add_argument("--windows-crt-installer", type=Path)
    result.add_argument("--windows-crt-license", type=Path)
    for target in TARGETS:
        result.add_argument("--" + target + "-runtime-bundle", type=Path)
        result.add_argument("--" + target + "-runtime-sha256")
    return result


def main(argv=None):
    cli = parser()
    args = cli.parse_args(argv)
    succeeded = []
    try:
        cross = preflight(args)
        if args.preflight:
            print("Preflight пройден: " + ",".join(args.targets) + "; сборка не запускалась.")
            return 0
        args.output_dir.mkdir(parents=True, exist_ok=True)
        session = Path(tempfile.mkdtemp(prefix="tvm-release-", dir=args.output_dir))
        artifacts = session / "artifacts"
        artifacts.mkdir()
        cache = args.cache or session / "cache"
        cache.mkdir(parents=True, exist_ok=True)
        env = os.environ.copy()
        for name in ("COMPOSER_AUTH", "COMPOSER", "PHPRC", "PHP_INI_SCAN_DIR", "BASH_ENV", "ENV",
                     "CFLAGS", "CPPFLAGS", "LDFLAGS", "LIBS", "CPATH", "LIBRARY_PATH", "C_INCLUDE_PATH",
                     "PKG_CONFIG_LIBDIR", "PKG_CONFIG_SYSROOT_DIR", "DYLD_LIBRARY_PATH", "LD_PRELOAD", "LD_LIBRARY_PATH"):
            env.pop(name, None)
        for name in ("TMPDIR", "TMP", "TEMP", "COMPOSER_HOME", "GNUPGHOME"):
            directory = session / name.lower()
            directory.mkdir(mode=0o700)
            env[name] = str(directory)
        env["COMPOSER_DISABLE_NETWORK"] = "1"
        env["PYTHONDONTWRITEBYTECODE"] = "1"
        env["GIT_OPTIONAL_LOCKS"] = "0"
        for target in args.targets:
            work = session / target
            work.mkdir()
            log = session / (target + ".log")
            print("Сборка " + target + "; журнал: " + str(log), flush=True)
            try:
                with log.open("wb") as stream:
                    subprocess.run(package_command(args, target, cache, work, cross), check=True, env=env,
                                   stdout=stream, stderr=subprocess.STDOUT)
            except subprocess.CalledProcessError as error:
                print(f"Ошибка упаковки {target}: код {error.returncode}{package_failure_context(log)}. "
                      "Завершены цели: " + (",".join(succeeded) or "нет"), file=sys.stderr)
                return 1
            found = list(work.rglob(filename(target)))
            if len(found) != 1:
                raise ValueError("Ожидался ровно один архив: " + target)
            verify_artifact(found[0], target, args.source_sha)
            for path in (found[0], found[0].with_name(found[0].name + ".sha256")):
                shutil.copyfile(path, artifacts / path.name)
            verify_artifact(artifacts / filename(target), target, args.source_sha)
            succeeded.append(target)
        print("Проверены выбранные цели: " + ",".join(succeeded) + ". Архивы: " + str(artifacts))
        print("Native smoke и release gates проверяются отдельно; набор из части платформ не является полным выпуском.")
        return 0
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError, tarfile.TarError, zipfile.BadZipFile) as error:
        print("Ошибка: " + str(error) + ". Завершены цели: " + (",".join(succeeded) or "нет"), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
