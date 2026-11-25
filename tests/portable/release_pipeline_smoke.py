import argparse
import contextlib
import copy
import hashlib
import importlib.util
import io
import itertools
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest import mock
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


release = load("release", ROOT / "build/release.py")
bootstrap = load("bootstrap", ROOT / "build/ci/bootstrap.py")


class ReleasePipeline(unittest.TestCase):
    def arguments(self, targets="linux"):
        return release.parser().parse_args(["--targets", targets, "--build-php", "php", "--composer", "/external/composer.phar"])

    def test_all_seven_selections_and_orders(self):
        names = list(release.TARGETS)
        for count in (1, 2, 3):
            for subset in itertools.combinations(names, count):
                for order in itertools.permutations(subset):
                    self.assertEqual(list(subset), release.selection(",".join(order)))
        self.assertEqual(names, release.selection("all"))
        for value in ("", ",", "linux,", " linux", "Linux", "linux,linux", "all,linux", "linux,,macos", "plan9"):
            with self.subTest(value=value), self.assertRaises(argparse.ArgumentTypeError):
                release.selection(value)
        with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
            release.parser().parse_args([])

    def test_host_mapping(self):
        for system, machine, wsl, expected in (
            ("Linux", "x86_64", False, {"linux"}), ("Linux", "x86_64", True, {"linux", "windows"}),
            ("Windows", "AMD64", False, {"windows"}), ("Darwin", "arm64", False, {"macos"}),
            ("Darwin", "x86_64", False, set()), ("Linux", "aarch64", False, set()),
            ("Windows", "ARM64", False, set()),
        ):
            self.assertEqual(expected, release.host_targets(system, machine, wsl))

    def test_unsupported_request_precedes_tools_and_writes(self):
        for system, machine in (("Darwin", "arm64"), ("Linux", "x86_64"), ("Windows", "AMD64")):
            with mock.patch.object(release.platform, "system", return_value=system), \
                 mock.patch.object(release.platform, "machine", return_value=machine), \
                 mock.patch.object(release.subprocess, "check_output", side_effect=AssertionError("process")), \
                 mock.patch.object(release.tempfile, "mkdtemp", side_effect=AssertionError("write")):
                with self.assertRaisesRegex(ValueError, "standalone-bundles.yml"):
                    release.preflight(self.arguments("all"))

    def test_external_paths_and_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            checkout = base / "checkout"
            checkout.mkdir()
            with mock.patch.object(release, "ROOT", checkout):
                self.assertEqual(base / "new", release.external(base / "new"))
                for path in (checkout, checkout / "child"):
                    with self.assertRaises(ValueError):
                        release.external(path)
                alias = base / "alias"
                alias.symlink_to(checkout, target_is_directory=True)
                with self.assertRaises(ValueError):
                    release.external(alias / "child")

    def test_crt_cross_and_reuse_arguments(self):
        args = self.arguments("linux,windows")
        args.windows_crt_installer = Path("/outside/crt.exe")
        args.windows_crt_license = Path("/outside/license.docx")
        args.windows_runtime_bundle = Path("/outside/old.zip")
        args.windows_runtime_sha256 = "a" * 64
        args.offline = True
        command = release.package_command(args, "windows", Path("/cache"), Path("/out"), True)
        self.assertIn("--cross-windows", command)
        self.assertIn("--offline", command)
        self.assertEqual("windows-x64", command[command.index("--target") + 1])
        for flag in ("--windows-crt-installer", "--windows-crt-license", "--runtime-bundle", "--runtime-sha256"):
            self.assertIn(flag, command)
        self.assertNotIn("--cross-windows", release.package_command(args, "linux", Path("/cache"), Path("/out"), True))

    def fixture(self, base, target):
        root = base / "checkout"
        root.mkdir()
        (root / "build/common").mkdir(parents=True)
        inputs = {"synthetic": {"sha256": "a" * 64}}
        (root / "build/common/inputs.json").write_text(json.dumps(inputs))
        (root / "source.txt").write_bytes(b"public source")
        (root / "build/code.py").write_bytes(b"public build")
        record = {"target": release.TARGETS[target], "inputs": inputs,
                  "source_sha256": {"source.txt": release.sha256(root / "source.txt")},
                  "build_sha256": {"build/code.py": release.sha256(root / "build/code.py")}}
        archive = base / release.filename(target)
        member = "telegram-vacancy-monitor/runtime/PROVENANCE.json"
        data = json.dumps(record).encode()
        if target == "windows":
            with zipfile.ZipFile(archive, "w") as stream:
                stream.writestr(member, data)
        else:
            with tarfile.open(archive, "w:gz") as stream:
                info = tarfile.TarInfo(member)
                info.size = len(data)
                stream.addfile(info, io.BytesIO(data))
        archive.with_name(archive.name + ".sha256").write_text(release.sha256(archive) + "  " + archive.name + "\n")
        return root, archive, record

    def test_archive_sidecar_target_and_source_binding(self):
        for target in release.TARGETS:
            with self.subTest(target=target), tempfile.TemporaryDirectory() as directory:
                root, archive, record = self.fixture(Path(directory), target)
                with mock.patch.object(release, "ROOT", root):
                    self.assertEqual(record, release.verify_artifact(archive, target))
                    def git_show(command, **kwargs):
                        self.assertIn("b" * 40 + ":", command[-1])
                        return (root / command[-1].split(":", 1)[1]).read_bytes()
                    with mock.patch.object(release.subprocess, "check_output", side_effect=git_show):
                        release.verify_artifact(archive, target, "b" * 40)
                    (root / "source.txt").write_bytes(b"wrong source")
                    with self.assertRaisesRegex(ValueError, "исходник"):
                        release.verify_artifact(archive, target)
                    sidecar = archive.with_name(archive.name + ".sha256")
                    sidecar.write_text(release.sha256(archive) + "  wrong.zip\n")
                    with self.assertRaisesRegex(ValueError, "sidecar"):
                        release.verify_artifact(archive, target)
                    with self.assertRaisesRegex(ValueError, "target/inputs"):
                        release.check_provenance(record, "windows" if target != "windows" else "linux")

    def test_bad_download_cannot_reach_execution(self):
        with tempfile.TemporaryDirectory() as directory:
            def fetch(command):
                self.assertEqual("curl", command[0])
                self.assertIn("--proto-redir", command)
                Path(command[command.index("--output") + 1]).write_bytes(b"corrupt bytes")
            with mock.patch.object(bootstrap, "run", side_effect=fetch):
                with self.assertRaisesRegex(ValueError, "SHA256"):
                    bootstrap.download({"url": "https://official.example/file", "sha256": "0" * 64}, Path(directory) / "download")

    def test_subset_success_and_partial_failure(self):
        for fail_windows in (False, True):
            with self.subTest(fail_windows=fail_windows), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                fixtures = {}
                for target in ("linux", "windows"):
                    parent = base / ("fixture-" + target)
                    parent.mkdir()
                    root, archive, record = self.fixture(parent, target)
                    fixtures[target] = archive
                invoked = []
                def package(command, **kwargs):
                    target = command[command.index("--target") + 1].split("-")[0]
                    invoked.append(target)
                    if target == "windows" and fail_windows:
                        raise subprocess.CalledProcessError(1, "synthetic package")
                    output = Path(command[command.index("--output-dir") + 1])
                    for path in (fixtures[target], fixtures[target].with_name(fixtures[target].name + ".sha256")):
                        shutil.copyfile(path, output / path.name)
                args = ["--targets", "linux,windows", "--build-php", "php", "--composer", "/external/composer.phar",
                        "--output-dir", str(base / "output"), "--windows-crt-installer", "/external/crt.exe", "--windows-crt-license", "/external/license.docx"]
                stdout, stderr = io.StringIO(), io.StringIO()
                with mock.patch.object(release, "ROOT", root), mock.patch.object(release, "preflight", return_value=True), \
                     mock.patch.object(release.subprocess, "run", side_effect=package), \
                     contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                    self.assertEqual(1 if fail_windows else 0, release.main(args))
                self.assertEqual(["linux", "windows"], invoked)
                files = list((base / "output").glob("tvm-release-*/artifacts/*"))
                self.assertEqual(2 if fail_windows else 4, len(files))
                if fail_windows:
                    self.assertIn("Завершены цели: linux", stderr.getvalue())

    def test_child_failure_reports_bounded_context_and_retains_log(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            log_data = (b"X" * 20000 + b'\n::error::fixture-output\nTraceback (most recent call last):\n'
                        b'  File "C:\\fixture\\build\\common\\package.py", line 415, in validate_composer\n'
                        b'UnicodeDecodeError: fixture-exception-message\n')
            command = [sys.executable, "-c", f"import sys; sys.stdout.buffer.write({log_data!r}); sys.exit(23)"]
            args = ["--targets", "windows", "--build-php", "php", "--composer", "/external/composer.phar",
                    "--output-dir", str(base)]
            stdout, stderr = io.StringIO(), io.StringIO()
            with mock.patch.object(release, "preflight", return_value=False), \
                 mock.patch.object(release, "package_command", return_value=command), \
                 contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                self.assertEqual(1, release.main(args))

            diagnostic = stderr.getvalue()
            self.assertIn("windows", diagnostic)
            self.assertIn("23", diagnostic)
            self.assertIn("UnicodeDecodeError", diagnostic)
            self.assertIn("package.py:415", diagnostic)
            self.assertIn("Завершены цели: нет", diagnostic)
            self.assertLess(len(diagnostic.encode("utf-8")), 400)
            for untrusted in ("::error::", "fixture-output", "fixture-exception-message", "C:\\fixture"):
                self.assertNotIn(untrusted, stdout.getvalue() + diagnostic)
            log, = base.glob("tvm-release-*/windows.log")
            self.assertEqual(log_data, log.read_bytes())
            self.assertEqual([], list(base.glob("tvm-release-*/artifacts/*")))

    def test_failed_native_smoke_never_publishes(self):
        with tempfile.TemporaryDirectory() as directory:
            session = Path(directory)
            with mock.patch.object(bootstrap, "composer", return_value=session / "composer.phar"), \
                 mock.patch.object(bootstrap.release, "executable", side_effect=lambda value: value), \
                 mock.patch.object(bootstrap.release, "output", return_value="Composer version " + bootstrap.TOOLS["composer"]["version"] + " synthetic"), \
                 mock.patch.object(bootstrap, "locate_artifact", return_value=session / "archive.tar.gz"), \
                 mock.patch.object(bootstrap, "publish_verified") as publish, \
                 mock.patch.object(bootstrap, "run", side_effect=[None, subprocess.CalledProcessError(1, "smoke")]):
                with self.assertRaises(subprocess.CalledProcessError):
                    bootstrap.native("macos", session, "a" * 40)
                publish.assert_not_called()

    def test_linux_build_uses_runner_identity_before_host_smoke(self):
        for fail_at in (None, "preflight", "smoke"):
            with self.subTest(fail_at=fail_at):
                self.check_linux_host_smoke(fail_at)

    def check_linux_host_smoke(self, fail_at):
        with tempfile.TemporaryDirectory(prefix="tvm-ci-linux-") as directory:
            session = Path(directory)
            name = "tvm-" + session.name.lower()
            source_sha = "a" * 40
            archive = session / "tvm-release-fixture/artifacts" / release.filename("linux")
            bwrap = session / "smoke-tools/usr/bin/bwrap"
            smoke_env = ["env", "PYTHONDONTWRITEBYTECODE=1", "PATH=/usr/bin:/bin"]
            smoke = ["sudo", "-n", *smoke_env, sys.executable, ROOT / "tests/portable/runtime_smoke.py", "--bwrap", bwrap]
            events = mock.Mock()

            def run(command):
                if "/src/build/release.py" in command:
                    home = session / "build-home"
                    self.assertEqual(0o700, home.stat().st_mode & 0o777)
                    (home / "fixture").touch()
                    (session / "cache").mkdir()
                    archive.parent.parent.mkdir(mode=0o700)
                    archive.parent.mkdir()
                    archive.touch()
                if ROOT / "tests/portable/runtime_smoke.py" in command:
                    step = "preflight" if "--preflight" in command else "smoke"
                    self.assertEqual(step == "smoke", archive.is_file())
                    if step == fail_at:
                        raise subprocess.CalledProcessError(1, command)

            def output(command):
                if command[0] == "git":
                    return bootstrap.TOOLS["setup_php"]["commit"]
                return "Composer version " + bootstrap.TOOLS["composer"]["version"] + " synthetic"

            events.run.side_effect = run
            bubble = bootstrap.TOOLS["linux"]["packages"]["bubblewrap"]
            with (
                mock.patch.object(bootstrap, "Path", side_effect=lambda value:
                                  mock.Mock(exists=lambda: True) if value.startswith("/") else Path(value)),
                mock.patch.object(bootstrap.os, "openpty", return_value=(), create=True),
                mock.patch.object(bootstrap.os, "getuid", return_value=2345, create=True),
                mock.patch.object(bootstrap.os, "getgid", return_value=6789, create=True),
                mock.patch.object(bootstrap.release, "executable"),
                mock.patch.object(bootstrap.release, "output", side_effect=output),
                mock.patch.object(bootstrap.release, "sha256", return_value=bubble["sha256"]),
                mock.patch.object(bootstrap, "composer"),
                mock.patch.object(bootstrap, "run", events.run),
                mock.patch.object(bootstrap, "locate_artifact", events.locate),
                mock.patch.object(bootstrap, "publish_verified", events.publish),
            ):
                events.locate.return_value = archive
                outcome = self.assertRaises(subprocess.CalledProcessError) if fail_at else contextlib.nullcontext()
                with outcome:
                    bootstrap.linux(session, source_sha)

            container = next(call.args[0] for call in events.run.call_args_list if call.args[0][:2] == ["docker", "run"])
            self.assertIn(f"type=bind,source={ROOT},target=/src,readonly", container)
            self.assertIn(f"type=bind,source={session},target=/work", container)
            self.assertIn("GIT_CONFIG_VALUE_0=/src", container)
            self.assertNotIn("--user", container)
            self.assertEqual(int(fail_at != "preflight"), sum("--user" in call.args[0] for call in events.run.call_args_list))
            events.assert_has_calls([mock.call.run(["docker", "exec", name, "bash", "/src/build/ci/linux-snapshot.sh"])])
            expected = [
                mock.call.run(["dpkg-deb", "--extract", session / "snapshot-inputs" / Path(bubble["path"]).name,
                               session / "smoke-tools"]),
                mock.call.run(["ldd", bwrap]),
                mock.call.run([*smoke, "--preflight", session]),
                mock.call.run(["docker", "exec", "--user", "2345:6789", "--env", "HOME=/work/build-home",
                               "--env", "CC=gcc-13", name, "python3", "/src/build/release.py", "--targets", "linux",
                               "--source-sha", source_sha, "--build-php", "/usr/bin/php8.5",
                               "--composer", "/work/composer.phar", "--cache", "/work/cache", "--output-dir", "/work"]),
                mock.call.locate(session, "linux", source_sha),
                mock.call.run([*smoke, archive]),
                mock.call.publish(session, archive, "linux", source_sha),
            ]
            expected = expected[:{"preflight": 3, "smoke": 6, None: 7}[fail_at]]
            expected.append(mock.call.run(["docker", "rm", "--force", name]))
            self.assertEqual(expected, events.mock_calls[-len(expected):])
            if fail_at:
                events.publish.assert_not_called()

    @unittest.skipUnless(sys.platform == "linux", "Нужен Linux для проверки PTY smoke")
    def test_linux_runtime_smoke_executes_explicit_bwrap_with_clean_path(self):
        smoke = load("runtime_smoke", ROOT / "tests/portable/runtime_smoke.py")
        real_menu = smoke.real_menu
        with tempfile.TemporaryDirectory(prefix="tvm-ci-linux-") as directory:
            session = Path(directory).resolve()
            archive = session / "tvm-release-fixture/artifacts" / release.filename("linux")
            archive.parent.mkdir(parents=True)
            files = {
                "runtime/PROVENANCE.json": b'{"target":"linux-x86_64"}',
                "runtime/php": b"\x7fELF\x02" + bytes(13) + b"\x3e\x00",
                "runtime/ld-linux-x86-64.so.2": b"#!/bin/sh\nexit 0\n",
                "runtime/gum": b"#!/bin/sh\nprintf 'gum v2.0.2\\n'\n",
                "vacancy-monitor": b"#!/bin/sh\nprintf 'App files are missing or corrupt\\n' >&2\nexit 1\n",
            }
            manifest = {name: hashlib.sha256(data).hexdigest() for name, data in files.items()}
            files["SHA256SUMS.json"] = json.dumps(manifest).encode()
            with tarfile.open(archive, "w:gz") as stream:
                for name, data in files.items():
                    member = tarfile.TarInfo("telegram-vacancy-monitor/" + name)
                    member.size = len(data)
                    member.mode = 0o755 if data.startswith(b"#!") else 0o644
                    stream.addfile(member, io.BytesIO(data))
            archive.with_name(archive.name + ".sha256").write_text(release.sha256(archive) + "  " + archive.name + "\n")

            bwrap = session / "smoke-tools/usr/bin/bwrap"
            bwrap.parent.mkdir(parents=True)
            bwrap.write_text("""#!/bin/sh
set -eu
[ "$PATH" = /usr/bin:/bin ]
printf '\\033[2J'
printf 'Запустить мониторинг\\n'
IFS= read -r keys; [ "$keys" = jjj ]
printf 'Быстрый старт\\n'
IFS= read -r keys; [ -z "$keys" ]
printf 'Распакуйте\\n'
IFS= read -r keys; [ -z "$keys" ]
printf 'Выберите тему\\n'
IFS= read -r keys; [ "$keys" = jjjjj ]
printf 'Запустить мониторинг\\n'
IFS= read -r keys; [ "$keys" = jjjjj ]
""", encoding="utf-8")
            bwrap.chmod(0o700)

            staged = []
            extract = smoke.packaging.extract

            def checked_extract(path, stage):
                self.assertEqual(0o700, stat.S_IMODE(stage.stat().st_mode))
                staged.append(stage)
                extract(path, stage)

            def checked_menu(command, env):
                self.assertEqual(str(bwrap), command[0])
                self.assertEqual("/usr/bin:/bin", env["PATH"])
                self.assertIn("if command -v php || command -v composer || command -v gum; then exit 77; fi; exec /app/vacancy-monitor",
                              command)
                app = Path(command[command.index("--ro-bind") + 1])
                self.assertEqual(staged[0], app.parent)
                self.assertEqual(Path("/tmp"), app.parent.parent)
                self.assertEqual(0o711, stat.S_IMODE(app.parent.stat().st_mode))
                self.assertTrue(all(path.stat().st_mode & stat.S_IXOTH for path in (app, *app.parents)))
                self.assertEqual(0o755, stat.S_IMODE((app / "runtime").stat().st_mode))
                return real_menu(command, env)

            umask = os.umask(0o077)
            try:
                with (
                    mock.patch.object(smoke.tempfile, "tempdir", str(session)),
                    mock.patch.object(smoke.packaging, "extract", side_effect=checked_extract),
                    mock.patch.object(smoke, "real_menu", side_effect=checked_menu) as menu,
                    contextlib.redirect_stdout(io.StringIO()),
                ):
                    smoke.main([str(archive), "--bwrap", str(bwrap)])
            finally:
                os.umask(umask)
            menu.assert_called_once()
            self.assertFalse(staged[0].exists())
            self.assertEqual(0o700, stat.S_IMODE(session.stat().st_mode))

    @unittest.skipUnless(sys.platform == "linux", "Нужен Linux для проверки bind source")
    def test_linux_bind_preflight_and_private_staging(self):
        smoke = load("runtime_smoke", ROOT / "tests/portable/runtime_smoke.py")
        umask = os.umask(0o077)
        try:
            with tempfile.TemporaryDirectory(dir="/tmp") as private:
                old_source = Path(private) / "Приложение with spaces"
                old_source.mkdir(mode=0o755)
                self.assertEqual(0o700, stat.S_IMODE(Path(private).stat().st_mode))
                self.assertFalse(all(path.stat().st_mode & stat.S_IXOTH for path in (old_source, *old_source.parents)))
                bwrap = Path(private) / "smoke-tools/usr/bin/bwrap"
                bwrap.parent.mkdir(parents=True)
                bwrap.touch(mode=0o700)
                staged = []

                def bind(command, *, env, check, timeout):
                    self.assertEqual(str(bwrap), command[0])
                    self.assertEqual("/usr/bin:/bin", env["PATH"])
                    self.assertEqual(["--unshare-user", "--unshare-pid", "--unshare-net", "--die-with-parent"], command[1:5])
                    self.assertEqual("/app", command[7])
                    self.assertTrue(command[-1].endswith("exec /app/probe"))
                    self.assertTrue(check)
                    app = Path(command[6])
                    self.assertEqual(old_source.name, app.name)
                    self.assertTrue(all(path.stat().st_mode & stat.S_IXOTH for path in (app, *app.parents)))
                    self.assertEqual(0o711, stat.S_IMODE(app.parent.stat().st_mode))
                    self.assertEqual(0o755, stat.S_IMODE((app / "probe").stat().st_mode))
                    staged.append(app.parent)
                    raise subprocess.CalledProcessError(1, command)

                with mock.patch.object(smoke.tempfile, "tempdir", private), mock.patch.object(smoke.subprocess, "run", side_effect=bind):
                    with self.assertRaises(subprocess.CalledProcessError):
                        smoke.main(["--preflight", private, "--bwrap", str(bwrap)])
                self.assertFalse(staged[0].exists())

            with smoke.linux_staging() as directory:
                staging = Path(directory)
                app = staging / "Приложение with spaces"
                app.mkdir()
                (app / "config").mkdir()
                fixture = app / "config/bot.ini"
                fixture.touch()
                with self.assertRaisesRegex(ValueError, "приватный"):
                    with smoke.linux_mount_source(app):
                        self.fail("private payload")
                fixture.unlink()
                (app / "link").symlink_to("config")
                with self.assertRaisesRegex(ValueError, "тип"):
                    with smoke.linux_mount_source(app):
                        self.fail("symlink payload")
                self.assertEqual(0o700, stat.S_IMODE(staging.stat().st_mode))
        finally:
            os.umask(umask)

    @unittest.skipUnless(os.name == "posix", "Нужен POSIX для проверки аргументов Unix smoke")
    def test_runtime_smoke_bwrap_path_contract(self):
        smoke = load("runtime_smoke", ROOT / "tests/portable/runtime_smoke.py")
        with tempfile.TemporaryDirectory(prefix="tvm-ci-linux-") as directory:
            session = Path(directory).resolve()
            archive = session / "tvm-release-fixture/artifacts" / release.filename("linux")
            bwrap = session / "smoke-tools/usr/bin/bwrap"
            bwrap.parent.mkdir(parents=True)
            unrelated = session / "unrelated/bwrap"
            unrelated.parent.mkdir()
            unrelated.touch(mode=0o700)
            with mock.patch.object(smoke.platform, "system", return_value="Linux"):
                for args, diagnostic in (
                    ([], "Для Linux требуется --bwrap"),
                    (["--bwrap", str(bwrap.relative_to(session))], "абсолютный путь"),
                    (["--bwrap", str(unrelated)], "в той же CI-сессии"),
                    (["--bwrap", str(bwrap)], "обычным исполняемым файлом"),
                ):
                    with self.subTest(args=args), contextlib.redirect_stderr(io.StringIO()) as stderr:
                        with self.assertRaises(SystemExit) as error:
                            smoke.main([str(archive), *args])
                        self.assertEqual(2, error.exception.code)
                        self.assertIn(diagnostic, stderr.getvalue())
                bwrap.symlink_to(unrelated)
                with contextlib.redirect_stderr(io.StringIO()) as stderr, self.assertRaises(SystemExit) as error:
                    smoke.main([str(archive), "--bwrap", str(bwrap)])
                self.assertEqual(2, error.exception.code)
                self.assertIn("без символьных ссылок", stderr.getvalue())
                with mock.patch.object(smoke, "linux_preflight") as preflight, contextlib.redirect_stderr(io.StringIO()):
                    with self.assertRaises(SystemExit) as error:
                        smoke.main(["--preflight", str(session), "--bwrap", str(bwrap)])
                    self.assertEqual(2, error.exception.code)
                    preflight.assert_not_called()
            with mock.patch.object(smoke.platform, "system", return_value="Darwin"), \
                 mock.patch.object(smoke.packaging, "digest", side_effect=FileNotFoundError("archive fixture")) as digest:
                with self.assertRaisesRegex(FileNotFoundError, "archive fixture"):
                    smoke.main([str(archive)])
                digest.assert_called_once_with(archive)

    def test_locate_linux_artifact_requires_one_verified_archive(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            artifacts = base / "tvm-release-first/artifacts"
            artifacts.mkdir(parents=True)
            source_sha = "b" * 40
            with self.assertRaisesRegex(ValueError, "ровно один итоговый архив"):
                bootstrap.locate_artifact(base, "linux", source_sha)
            root, archive, _ = self.fixture(artifacts, "linux")
            sidecar = archive.with_name(archive.name + ".sha256")
            sidecar_bytes = sidecar.read_bytes()
            sidecar.unlink()
            with self.assertRaises(FileNotFoundError):
                bootstrap.locate_artifact(base, "linux", source_sha)
            sidecar.write_bytes(sidecar_bytes)
            with mock.patch.object(bootstrap.release, "ROOT", root), \
                 mock.patch.object(bootstrap.release.subprocess, "check_output",
                                   side_effect=lambda command, **_: (root / command[-1].removeprefix(source_sha + ":")).read_bytes()):
                self.assertEqual(archive, bootstrap.locate_artifact(base, "linux", source_sha))
            second = base / "tvm-release-second/artifacts"
            second.mkdir(parents=True)
            shutil.copyfile(archive, second / archive.name)
            with self.assertRaisesRegex(ValueError, "ровно один итоговый архив"):
                bootstrap.locate_artifact(base, "linux", source_sha)

    def test_snapshot_wrong_signer_prevents_package_downloads(self):
        with tempfile.TemporaryDirectory() as directory:
            with mock.patch.object(bootstrap, "download", return_value=Path(directory) / "InRelease") as fetch, \
                 mock.patch.object(bootstrap.release, "output", return_value="[GNUPG:] VALIDSIG wrong "):
                with self.assertRaisesRegex(ValueError, "подписант"):
                    bootstrap.snapshot_evidence(Path(directory) / "evidence")
                self.assertEqual(1, fetch.call_count)

    def test_source_identity_fails_closed(self):
        with mock.patch.object(release, "output", return_value="b" * 40):
            with self.assertRaisesRegex(ValueError, "HEAD"):
                release.check_source_sha("a" * 40)
        with mock.patch.object(release, "output", side_effect=["a" * 40, " M source"]):
            with self.assertRaisesRegex(ValueError, "чистый"):
                release.check_source_sha("a" * 40)

    def test_workflow_windows_python_streams(self):
        workflow = (ROOT / release.WORKFLOW).read_text(encoding="utf-8")
        match = re.search(r"^        id: bundles\n(?:.*\n)*?^        run: \|\n((?: {10}.*\n)+)",
                          workflow, re.MULTILINE)
        self.assertIsNotNone(match)
        step = "\n".join(line[10:] for line in match[1].splitlines())
        bash = release.executable("bash")
        commands = 'python() { "$TEST_PYTHON" "$@"; }\npython3() { python "$@"; }\n'

        fixtures = {
            "build/ci/bootstrap.py": (
                "import subprocess, sys\n"
                "raise SystemExit(subprocess.run([sys.executable, 'build/release.py']).returncode)\n"
            ),
            "build/release.py": (
                "import subprocess, sys\n"
                "print('Сборка windows; журнал: fixture.log', flush=True)\n"
                "raise SystemExit(subprocess.run([sys.executable, 'build/common/package.py']).returncode)\n"
            ),
            "build/common/package.py": (
                "import os, sys\n"
                "print('Архив: fixture.zip')\n"
                "print('Диагностика упаковки', file=sys.stderr)\n"
                "raise SystemExit(int(os.environ['TEST_EXIT']))\n"
            ),
        }
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            for name, code in fixtures.items():
                path = base / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(code, encoding="utf-8")

            def run(runner_os, status):
                env = dict(RUNNER_OS=runner_os, BUILD_TARGET=runner_os.lower(), SOURCE_SHA="a" * 40,
                           PYTHONIOENCODING="cp1252", PYTHONDONTWRITEBYTECODE="1",
                           TEST_PYTHON=sys.executable, TEST_EXIT=str(status), PATH=str(base / "no-tools"))
                return subprocess.run([bash, "--noprofile", "--norc", "-eo", "pipefail", "-c", commands + step],
                                      cwd=base, env=env, capture_output=True, timeout=10)

            baseline = run("Linux", 0)
            self.assertEqual(1, baseline.returncode)
            self.assertEqual(b"", baseline.stdout)
            self.assertIn(b"UnicodeEncodeError", baseline.stderr)
            for status in (0, 23):
                with self.subTest(status=status):
                    result = run("Windows", status)
                    self.assertEqual(status, result.returncode, result.stderr)
                    self.assertEqual(["Сборка windows; журнал: fixture.log", "Архив: fixture.zip"],
                                     result.stdout.decode("utf-8").splitlines())
                    self.assertEqual("Диагностика упаковки", result.stderr.decode("utf-8").strip())

    def test_linux_snapshot_guards(self):
        script = (ROOT / "build/ci/linux-snapshot.sh").read_text(encoding="utf-8")
        # RU: Перенаправляем запись в фикстуру и заменяем APT маркером вызова.
        # EN: Redirect the source rewrite to the fixture and replace APT with a call marker.
        prefix, update, _ = script.partition("\napt-get update -o APT::Update::Error-Mode=any\n")
        self.assertTrue(update)
        self.assertIn("cat > /etc/apt/sources.list.d/ubuntu.sources", prefix)
        prefix = re.sub(r"(?<![\w./-])/(?=\.dockerenv\b|(?:etc|src|setup-php)/)", "", prefix)
        sha256 = shutil.which("sha256sum")
        checksum = shlex.quote(sha256) if sha256 else shlex.quote(release.executable("shasum")) + " -a 256"
        mocks = ('id() { printf "%s\\n" "$TEST_UID"; }\nuname() { printf "%s\\n" "$TEST_ARCH"; }\n'
                 f'sha256sum() {{ {checksum}; }}\n'
                 f'cat() {{ : > rewrite-called; {shlex.quote(release.executable("cat"))}; }}\n'
                 'apt-get() { printf "%s\\n" "$*" > apt-called; }\n')
        bash = release.executable("bash")

        allowed_source = "etc/apt/sources.list.d/ubuntu.sources"
        sources_list = "etc/apt/sources.list"
        os_release = "etc/os-release"
        inert = ("# Ubuntu sources have moved to the /etc/apt/sources.list.d/ubuntu.sources\n"
                 "# file, which uses the deb822 format. Use deb822-formatted .sources files\n"
                 "# to manage package sources in the /etc/apt/sources.list.d/ directory.\n"
                 "# See the sources.list(5) manual page for details.\n")
        self.assertEqual(270, len(inert.encode()))
        fixtures = {".dockerenv": "", os_release: "ID=ubuntu\nVERSION_ID=24.04\n",
                    "src/build/common/ci-tools.json": "{}", "setup-php/src/scripts/linux.sh": "",
                    allowed_source: "stock source fixture\n"}
        for diagnostic, env_changes, file_changes in (
            ("TVM_CI_CONTAINER=1", {"TVM_CI_CONTAINER": ""}, {}),
            ("GITHUB_ACTIONS=true", {"GITHUB_ACTIONS": "false"}, {}),
            ("UID 0", {"TEST_UID": "1000"}, {}),
            (".dockerenv", {}, {".dockerenv": None}),
            ("архитектура x86_64", {"TEST_ARCH": "aarch64"}, {}),
            ("ID=ubuntu", {}, {os_release: "ID=debian\nVERSION_ID=24.04\n"}),
            ("VERSION_ID=24.04", {}, {os_release: "ID=ubuntu\nVERSION_ID=22.04\n"}),
            ("VERSION_ID=24.04", {}, {os_release: "ID=ubuntu\n"}),
            ("ci-tools.json", {}, {"src/build/common/ci-tools.json": None}),
            ("setup-php/src/scripts/linux.sh", {}, {"setup-php/src/scripts/linux.sh": None}),
            ("SHA256", {}, {sources_list: "# synthetic comment\n"}),
            ("SHA256", {}, {sources_list: inert.replace("Ubuntu", "ubuntu")}),
            ("SHA256", {}, {sources_list: inert[:-1]}),
            ("SHA256", {}, {sources_list: inert + "deb https://invalid.example noble main\n"}),
            ("SHA256", {}, {sources_list: "deb-src https://invalid.example noble main\n"}),
            ("символьной ссылкой", {}, {sources_list: Path("expected"), "etc/apt/expected": inert}),
            ("символьной ссылкой", {}, {sources_list: Path("absent")}),
            ("обычный файл", {}, {sources_list: False}),
            ("ожидается только ubuntu.sources, получено extra.list", {}, {"etc/apt/sources.list.d/extra.list": ""}),
            ("ожидается только ubuntu.sources, получено .extra", {}, {"etc/apt/sources.list.d/.extra": ""}),
            ("обычный файл", {}, {allowed_source: None}),
            ("обычный файл", {}, {allowed_source: Path("../stock"), "etc/apt/stock": ""}),
            ("обычный файл", {}, {allowed_source: False}),
            (None, {}, {}),
            (None, {}, {sources_list: ""}),
            (None, {}, {sources_list: inert}),
        ):
            with self.subTest(diagnostic=diagnostic, env=env_changes, files=file_changes), \
                    tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                (base / allowed_source).parent.mkdir(parents=True)
                for name, content in (fixtures | file_changes).items():
                    if content is not None:
                        path = base / name
                        path.parent.mkdir(parents=True, exist_ok=True)
                        if isinstance(content, Path):
                            path.symlink_to(content)
                        elif content is False:
                            path.mkdir()
                        else:
                            path.write_bytes(content.encode("utf-8"))
                env = dict(TVM_CI_CONTAINER="1", GITHUB_ACTIONS="true", TEST_UID="0", TEST_ARCH="x86_64",
                           PATH=str(base / "no-tools"), LC_ALL="C") | env_changes
                result = subprocess.run([bash, "--noprofile", "--norc", "-c", mocks + prefix + update],
                                        cwd=base, env=env, capture_output=True, encoding="utf-8", timeout=10)
                if diagnostic is None:
                    self.assertEqual(0, result.returncode, result.stderr)
                    self.assertEqual("", result.stderr)
                    self.assertIn("проверки пройдены, запускается apt-get update", result.stdout)
                    self.assertTrue((base / "rewrite-called").is_file())
                    self.assertEqual("update -o APT::Update::Error-Mode=any\n", (base / "apt-called").read_text())
                    self.assertIn(bootstrap.TOOLS["linux"]["base_url"], (base / allowed_source).read_text())
                else:
                    self.assertNotEqual(0, result.returncode)
                    self.assertEqual("", result.stdout)
                    self.assertTrue(result.stderr.startswith("Linux snapshot: "), result.stderr)
                    self.assertIn(diagnostic, result.stderr)
                    self.assertFalse((base / "rewrite-called").exists())
                    self.assertFalse((base / "apt-called").exists())
                    if allowed_source not in file_changes:
                        self.assertEqual(fixtures[allowed_source], (base / allowed_source).read_text())

    def test_macos_installation_requires_both_verified_assets(self):
        config = copy.deepcopy(bootstrap.TOOLS["macos_build_php"])
        installer = b"""#!/bin/bash
set -eu
[[ $# == 5 && $1 == 8.5 && $2 == release && $3 == nts && -z $5 ]]
[[ ${4##*/} == php_8.5-nts-release+darwin_arm64.tar.zst && -f $4 ]]
[[ $(< "$4.sha256") == "$TEST_ARCHIVE_SHA  ${4##*/}" ]]
[[ $(< "$4") == 'offline archive fixture' ]]
printf 'verified local archive\n' > "$TEST_CALLED"
exit 42
"""
        archive = b"offline archive fixture\n"
        release_base = "https://github.com/shivammathur/php-darwin/releases/download/php-8.5/"
        self.assertEqual(release_base + "install.sh", config["installer"]["url"])
        self.assertEqual(release_base + "php_8.5-nts-release%2Bdarwin_arm64." +
                         config["archive"]["sha256"] + ".tar.zst", config["archive"]["url"])
        for key, data in (("installer", installer), ("archive", archive)):
            self.assertRegex(config[key]["sha256"], r"^[0-9a-f]{64}$")
            config[key]["sha256"] = hashlib.sha256(data).hexdigest()
        bash = release.executable("bash")
        wrapper = ROOT / "build/ci/macos-php.sh"
        for key, variant in (("installer", "missing"), ("installer", "altered"),
                             ("archive", "missing"), ("archive", "altered"), (None, None)):
            with self.subTest(key=key, variant=variant), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                for name in ("ci", "common", "bin", "tmp"):
                    (base / name).mkdir()
                shutil.copyfile(wrapper, base / "ci" / wrapper.name)
                (base / "common/ci-tools.json").write_text(json.dumps({"macos_build_php": config}))
                for asset, data in (("installer", installer), ("archive", archive)):
                    if (key, variant) != (asset, "missing"):
                        (base / asset).write_bytes(data + (b"# altered\n" if (key, variant) == (asset, "altered") else b""))
                for name, code in {
                    "uname": 'case "$1" in -s) echo Darwin;; -m) echo arm64;; *) exit 1;; esac\n',
                    "brew": "exit 99\n",
                    "tar": "exit 99\n",
                    "zstd": "exit 99\n",
                    "curl": """[[ $1 == --disable ]] || exit 98
out=
while [[ $# -gt 1 ]]; do
    if [[ $1 == --output ]]; then out=$2; shift; fi
    shift
done
printf '%s\n' "$1" >> "$TEST_DOWNLOADS"
case "$1" in
    "$TEST_INSTALLER_URL") asset=installer;;
    "$TEST_ARCHIVE_URL") asset=archive;;
    *) exit 97;;
esac
[[ -f $TEST_ROOT/$asset && -n $out ]] || exit 22
cat "$TEST_ROOT/$asset" > "$out"
""",
                }.items():
                    path = base / "bin" / name
                    path.write_text("#!" + bash + "\n" + code)
                    path.chmod(0o700)
                path_file = base / "github-path"
                path_file.touch()
                env = dict(PATH=str(base / "bin") + os.pathsep + os.defpath,
                           GITHUB_ACTIONS="true", RUNNER_OS="macOS", RUNNER_ARCH="ARM64",
                           RUNNER_TEMP=str(base / "tmp"), GITHUB_PATH=str(path_file),
                           PHP_DARWIN_EXTENSIONS="unwanted", INPUT_EXTENSIONS="unwanted",
                           TEST_ROOT=str(base), TEST_CALLED=str(base / "called"),
                           TEST_DOWNLOADS=str(base / "downloads"),
                           TEST_INSTALLER_URL=config["installer"]["url"], TEST_ARCHIVE_URL=config["archive"]["url"],
                           TEST_ARCHIVE_SHA=config["archive"]["sha256"])
                env["PATH"] += os.pathsep + os.pathsep.join(
                    str(Path(release.executable(tool)).parent) for tool in ("jq", "python3"))
                result = subprocess.run([bash, base / "ci" / wrapper.name], env=env, capture_output=True,
                                        encoding="utf-8", timeout=15)
                self.assertEqual(1, result.returncode, result.stderr)
                self.assertEqual("", path_file.read_text())
                self.assertEqual([], list((base / "tmp").iterdir()))
                urls = [config["installer"]["url"]]
                if key != "installer":
                    urls.append(config["archive"]["url"])
                self.assertEqual(urls, (base / "downloads").read_text().splitlines())
                if key is None:
                    self.assertEqual("verified local archive\n", (base / "called").read_text())
                    self.assertIn("Установка проверенного архива завершилась ошибкой", result.stderr)
                else:
                    self.assertFalse((base / "called").exists())
                    diagnostic = "SHA256 не совпал для" if variant == "altered" else "Не удалось скачать"
                    self.assertIn(f"{diagnostic} {key}", result.stderr)

    def test_workflow_contract(self):
        text = (ROOT / release.WORKFLOW).read_text()
        self.assertIn("workflow_dispatch:", text)
        for forbidden in ("pull_request:", "push:", "contents: write", "continue-on-error:", "--privileged", "seccomp=unconfined"):
            self.assertNotIn(forbidden, text)
        self.assertIn("contents: read", text)
        self.assertIn("GIT_CONFIG_KEY_0: core.autocrlf", text)
        self.assertIn("GIT_CONFIG_VALUE_0: 'false'", text)
        self.assertEqual(2, text.count("ref: ${{ github.sha }}"))
        self.assertIn("if-no-files-found: error", text)
        for choice in ("linux", "windows", "macos", "linux,windows", "linux,macos", "windows,macos", "all"):
            self.assertIn("          - " + choice + "\n", text)
        for runner in ("ubuntu-24.04", "windows-2025", "macos-15"):
            self.assertIn(runner, text)
        for line in text.splitlines():
            if "uses:" in line:
                self.assertRegex(line, r"@[0-9a-f]{40}(?: |$)")
        config = bootstrap.TOOLS
        steps = re.split(r"(?m)^      - ", text)
        setup, = [step for step in steps if "uses: shivammathur/setup-php@" in step]
        self.assertIn("if: matrix.target == 'windows'\n", setup)
        self.assertIn("setup-php@" + config["setup_php"]["commit"], setup)
        mac, = [step for step in steps if "bash build/ci/macos-php.sh" in step]
        self.assertIn("if: matrix.target == 'macos'\n", mac)
        self.assertLess(text.index("bash build/ci/macos-php.sh"), text.index("python3 build/ci/bootstrap.py"))
        self.assertRegex(config["linux"]["image"], r"^ubuntu@sha256:[0-9a-f]{64}$")
        self.assertEqual("20261115T000000Z", config["linux"]["snapshot"])
        for key in ("composer", "seven_zip"):
            self.assertRegex(config[key]["sha256"], r"^[0-9a-f]{64}$")
        script = (ROOT / "build/ci/linux-snapshot.sh").read_text()
        self.assertLess(script.index("sha256sum --check"), script.index("apt-get install"))
        for digest in config["linux"]["inrelease_sha256"].values():
            self.assertIn(digest, script)
        for name, pin in config["linux"]["packages"].items():
            self.assertRegex(pin["sha256"], r"^[0-9a-f]{64}$")
            if name != "bubblewrap":
                self.assertIn(name + "=" + pin["version"], script)


if __name__ == "__main__":
    unittest.main()
