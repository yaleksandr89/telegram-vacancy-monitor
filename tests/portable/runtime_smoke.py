import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import platform
import pty
import select
import shutil
import stat
import struct
import subprocess
import sys
import tempfile
import termios
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("packaging", ROOT / "build/common/package.py")
packaging = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packaging)


def real_menu(command, env):
    pid, fd = pty.fork()
    if pid == 0:
        os.execvpe(command[0], command, env)
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", 40, 100, 0, 0))
    transcript = bytearray()
    # RU: Реальные Gum/TTY: главная -> справка -> тема -> назад -> главная -> выход.
    # EN: Real Gum/TTY: main -> help -> topic -> back -> main -> exit.
    stages = [(b"Start monitoring", b"jjj\r"),
              ("Быстрый старт".encode(), b"\r"),
              ("Распакуйте".encode(), b"\r"),
              ("Выберите тему".encode(), b"jjjjj\r"),
              ("Запустить мониторинг".encode(), b"jjjjj\r")]
    # RU: Первый экран русский; текст появляется до запуска choose, даём Gum открыть TTY.
    # EN: The first screen is Russian; text precedes choose, allowing Gum to open its TTY.
    stages[0] = ("Запустить мониторинг".encode(), b"jjj\r")
    stage = 0
    screen = bytearray()
    deadline = time.monotonic() + 40
    status = None
    try:
        while time.monotonic() < deadline:
            readable, _, _ = select.select([fd], [], [], 0.1)
            if readable:
                try:
                    data = os.read(fd, 65536)
                except OSError:
                    break
                if not data:
                    break
                transcript.extend(data)
                screen.extend(data)
                # RU: Отвечаем на запросы terminal capabilities без выдуманной поддержки цвета.
                # EN: Answer terminal capability queries without claiming color support.
                if b"\x1b[6n" in data:
                    os.write(fd, b"\x1b[1;1R")
                if stage < len(stages) and stages[stage][0] in screen:
                    time.sleep(0.5)
                    os.write(fd, stages[stage][1])
                    stage += 1
                    screen.clear()
            waited, code = os.waitpid(pid, os.WNOHANG)
            if waited:
                status = os.waitstatus_to_exitcode(code)
                break
        if status is None:
            waited, code = os.waitpid(pid, os.WNOHANG)
            if waited:
                status = os.waitstatus_to_exitcode(code)
        if status != 0 or stage != len(stages):
            raise RuntimeError("Real Gum navigation failed: stage=" + str(stage) + ", status=" + str(status)
                               + "\n" + transcript[-2500:].decode(errors="replace"))
    finally:
        if status is None:
            os.kill(pid, 9)
            os.waitpid(pid, 0)
        os.close(fd)
    return bytes(transcript)


def linux_staging():
    parent = Path("/tmp")
    parent_info = parent.lstat()
    root_info = parent.parent.stat()
    mode = parent_info.st_mode
    if (not stat.S_ISDIR(mode) or parent_info.st_uid != root_info.st_uid
            or not mode & stat.S_IXOTH or not root_info.st_mode & stat.S_IXOTH
            or (mode & 0o022 and not mode & stat.S_ISVTX)):
        raise ValueError("Linux smoke: /tmp не подходит для безопасного bind source.")
    return tempfile.TemporaryDirectory(prefix="tvm-portable-", dir=parent)


@contextmanager
def linux_mount_source(app):
    staging = app.parent
    owner = os.geteuid()
    stage_info = staging.lstat()
    if (staging.parent != Path("/tmp") or not staging.name.startswith("tvm-portable-")
            or not stat.S_ISDIR(stage_info.st_mode) or stage_info.st_uid != owner
            or stat.S_IMODE(stage_info.st_mode) != 0o700 or set(staging.iterdir()) != {app}):
        raise ValueError("Linux smoke: ожидался отдельный приватный каталог с одним приложением.")
    directories = []
    for path in [app, *app.rglob("*")]:
        info = path.lstat()
        parts = path.relative_to(app).parts
        if parts and (parts[0] in ("var", "public", ".git", "tests")
                      or parts[:2] == ("config", "bot.ini") or parts[:3] == ("config", "profile", "local")):
            raise ValueError("Linux smoke: в bind source обнаружен приватный или серверный путь.")
        if (info.st_uid != owner
                or not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode))
                or (stat.S_ISREG(info.st_mode) and info.st_mode & 0o022)):
            raise ValueError("Linux smoke: неожиданный владелец, тип или права файла bind source.")
        if stat.S_ISDIR(info.st_mode):
            directories.append(path)
    if app not in directories:
        raise ValueError("Linux smoke: bind source должен быть каталогом.")
    # RU: Открываем проход только к проверенному дереву, сохраняя запрет на просмотр staging и запись другими.
    # EN: Allow traversal only to the verified tree, keeping staging unlistable and unwritable by others.
    for directory in directories:
        directory.chmod(0o755)
    staging.chmod(0o711)
    try:
        yield
    finally:
        staging.chmod(0o700)


def linux_sandbox(app, bwrap, executable):
    # RU: Пустое mount namespace: ни PHP, ни Composer, ни расширений хоста.
    # EN: Empty mount namespace: no host PHP, Composer or PHP extensions.
    command = [str(bwrap), "--unshare-user", "--unshare-pid", "--unshare-net", "--die-with-parent",
               "--ro-bind", str(app), "/app", "--proc", "/proc", "--dev", "/dev", "--tmpfs", "/tmp",
               "--ro-bind", "/bin/bash", "/bin/bash", "--ro-bind", "/usr/bin/dirname", "/usr/bin/dirname"]
    command += ['--ro-bind', '/usr/bin/tput', '/usr/bin/tput', '--ro-bind', '/usr/share/terminfo', '/usr/share/terminfo']
    for name in ["/lib/x86_64-linux-gnu/libtinfo.so.6", "/lib/x86_64-linux-gnu/libc.so.6",
                 "/lib64/ld-linux-x86-64.so.2"]:
        command += ["--ro-bind", name, name]
    command += ["--chdir", "/tmp", "/bin/bash", "-c",
                f'if command -v php || command -v composer || command -v gum; then exit 77; fi; exec {executable}']
    clean_env = dict(os.environ, TERM="xterm-256color", PATH="/usr/bin:/bin", LANG="C", LC_ALL="C")
    return command, clean_env


def linux_preflight(bwrap):
    with linux_staging() as directory:
        app = Path(directory) / "Приложение with spaces"
        app.mkdir()
        probe = app / "probe"
        probe.write_text("""#!/bin/bash
set -eu
[[ $(dirname /app/probe) == /app && -d /proc/self && -c /dev/null ]]
screen=$(tput clear)
[[ "$screen" == *$'\\e[2J'* ]]
""", encoding="utf-8")
        probe.chmod(0o755)
        with linux_mount_source(app):
            command, env = linux_sandbox(app, bwrap, "/app/probe")
            subprocess.run(command, env=env, check=True, timeout=30)
    print("PASS: Linux bubblewrap bind preflight", flush=True)


def linux_menu(app, bwrap):
    with linux_mount_source(app):
        transcript = real_menu(*linux_sandbox(app, bwrap, "/app/vacancy-monitor"))
    assert b'\x1b[2J' in transcript, 'Terminal screen was not cleared'


def main(argv=None):
    parser = argparse.ArgumentParser()
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("archive", type=Path, nargs="?")
    source.add_argument("--preflight", type=Path, metavar="SESSION")
    parser.add_argument("--bwrap", type=Path, help="Абсолютный путь к bwrap из сессии Linux CI")
    args = parser.parse_args(argv)
    system = platform.system()
    if args.preflight is not None and system != "Linux":
        parser.error("--preflight применяется только в Linux.")
    archive = args.archive.resolve() if args.archive is not None else None
    if system == "Linux":
        if args.bwrap is None:
            parser.error("Для Linux требуется --bwrap.")
        if sys.flags.optimize:
            parser.error("Для Linux smoke требуются включённые проверки assert.")
        if args.preflight is not None:
            session = args.preflight.resolve()
        else:
            release_dir = archive.parent.parent
            if archive.parent.name != "artifacts" or not release_dir.name.startswith("tvm-release-"):
                parser.error("Для Linux требуется архив из tvm-release-*/artifacts.")
            session = release_dir.parent
        expected = session / "smoke-tools/usr/bin/bwrap"
        if args.bwrap != expected:
            parser.error("--bwrap должен указывать на абсолютный путь к smoke-tools/usr/bin/bwrap в той же CI-сессии.")
        if args.bwrap.resolve() != expected or not args.bwrap.is_file() or not os.access(args.bwrap, os.X_OK):
            parser.error("--bwrap должен быть обычным исполняемым файлом без символьных ссылок.")
        if args.preflight is not None:
            linux_preflight(args.bwrap)
            return
    elif args.bwrap is not None:
        parser.error("--bwrap применяется только в Linux.")
    sidecar = archive.with_name(archive.name + ".sha256")
    assert packaging.digest(archive) == sidecar.read_text().split()[0], "Archive SHA256 mismatch"
    staging = linux_staging() if system == "Linux" else tempfile.TemporaryDirectory(prefix="tvm-portable-")
    with staging as temporary:
        temp = Path(temporary)
        packaging.extract(archive, temp)
        original = temp / "telegram-vacancy-monitor"
        app = temp / "Приложение with spaces"
        original.rename(app)
        manifest = json.loads((app / "SHA256SUMS.json").read_text())
        actual = {str(p.relative_to(app)): packaging.digest(p) for p in app.rglob("*")
                  if p.is_file() and (p != app / "SHA256SUMS.json" if system == "Linux" else p.name != "SHA256SUMS.json")}
        assert actual == manifest, "Payload hashes differ"
        for name in actual:
            parts = Path(name).parts
            assert not (parts[0] in ("var", "public", ".git", "tests") or name == "config/bot.ini"
                        or name.startswith("config/profile/local/")), "Private/server payload"
        provenance = json.loads((app / "runtime/PROVENANCE.json").read_text())
        target = {"Linux": "linux-x86_64", "Darwin": "macos-arm64"}.get(system)
        assert target == provenance["target"], "Wrong target"
        assert (platform.machine() == ("x86_64" if target == "linux-x86_64" else "arm64")), "Wrong architecture"
        runtime = app / "runtime"
        php = [str(runtime / "php"), "-n"]
        if target == "linux-x86_64":
            php = [str(runtime / "ld-linux-x86-64.so.2"), "--inhibit-cache",
                   "--library-path", str(runtime / "lib")] + php
            assert (runtime / "php").read_bytes()[:5] == b"\x7fELF\x02"
            assert struct.unpack("<H", (runtime / "php").read_bytes()[18:20])[0] == 62
        env = dict(os.environ, PATH="/nonexistent", PHPRC="/nonexistent", PHP_INI_SCAN_DIR="/nonexistent")
        subprocess.run(php + [str(app / "src/ui/windows/inspect.php"), "runtime"], env=env, check=True)
        code = 'require $argv[1] . "/vendor/autoload.php"; $p = new PDO("sqlite::memory:"); $p->exec("CREATE TABLE smoke (id INTEGER)"); echo PHP_VERSION, " ", implode(",", PDO::getAvailableDrivers()), PHP_EOL;'
        subprocess.run(php + ["-r", code, str(app)], env=env, check=True)
        version = subprocess.check_output([runtime / "gum", "--version"], env=env)
        assert b"v2.0.2" in version
        assert (app / "vacancy-monitor").is_file()
        if target == "linux-x86_64":
            linux_menu(app, args.bwrap)
        else:
            real_menu([str(app / "vacancy-monitor")], dict(os.environ, TERM="xterm-256color", PATH="/usr/bin:/bin"))
        assert not (app / "config/bot.ini").exists()
        assert not (app / "var").exists()
        (runtime / 'php').rename(runtime / 'php.removed')
        damaged = subprocess.run([str(app / 'vacancy-monitor')], env=dict(os.environ, PHP_BINARY='/usr/bin/php'),
                                 capture_output=True)
        assert damaged.returncode == 1 and b'App files are missing or corrupt' in damaged.stderr
        print("PASS: hashes, native bundled PHP/extensions/autoload/SQLite, Gum Help/Back/Exit; " + target)


if __name__ == "__main__":
    main()
