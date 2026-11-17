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
import shutil
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
