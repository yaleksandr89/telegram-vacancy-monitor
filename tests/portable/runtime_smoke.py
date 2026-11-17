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


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Использование: PortableRuntimeSmoke.sh /path/bundle.tar.gz")
    archive = Path(sys.argv[1]).resolve()
    sidecar = archive.with_name(archive.name + ".sha256")
    assert packaging.digest(archive) == sidecar.read_text().split()[0], "Archive SHA256 mismatch"
    with tempfile.TemporaryDirectory(prefix="tvm-portable-") as temporary:
        temp = Path(temporary)
        packaging.extract(archive, temp)
        original = temp / "telegram-vacancy-monitor"
        app = temp / "Приложение with spaces"
        original.rename(app)
        manifest = json.loads((app / "SHA256SUMS.json").read_text())
        actual = {str(p.relative_to(app)): packaging.digest(p) for p in app.rglob("*") if p.is_file() and p.name != "SHA256SUMS.json"}
        assert actual == manifest, "Payload hashes differ"
        for name in actual:
            parts = Path(name).parts
            assert not (parts[0] in ("var", "public", ".git", "tests") or name == "config/bot.ini"
                        or name.startswith("config/profile/local/")), "Private/server payload"
        provenance = json.loads((app / "runtime/PROVENANCE.json").read_text())
        target = {"Linux": "linux-x86_64", "Darwin": "macos-arm64"}.get(platform.system())
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
            # RU: Пустое mount namespace: ни PHP, ни Composer, ни расширений хоста.
            # EN: Empty mount namespace: no host PHP, Composer or PHP extensions.
            command = ["bwrap", "--unshare-user", "--unshare-pid", "--unshare-net", "--die-with-parent",
                       "--ro-bind", str(app), "/app", "--proc", "/proc", "--dev", "/dev", "--tmpfs", "/tmp",
                       "--ro-bind", "/bin/bash", "/bin/bash", "--ro-bind", "/usr/bin/dirname", "/usr/bin/dirname"]
            command += ['--ro-bind', '/usr/bin/tput', '/usr/bin/tput', '--ro-bind', '/usr/share/terminfo', '/usr/share/terminfo']
            for name in ["/lib/x86_64-linux-gnu/libtinfo.so.6", "/lib/x86_64-linux-gnu/libc.so.6",
                         "/lib64/ld-linux-x86-64.so.2"]:
                command += ["--ro-bind", name, name]
            command += ["--chdir", "/tmp", "/bin/bash", "-c",
                        'if command -v php || command -v composer || command -v gum; then exit 77; fi; exec /app/vacancy-monitor']
            clean_env = dict(os.environ, TERM="xterm-256color", PATH="/usr/bin:/bin", LANG="C", LC_ALL="C")
            transcript = real_menu(command, clean_env)
            assert b'\x1b[2J' in transcript, 'Terminal screen was not cleared'
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
