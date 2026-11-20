#!/usr/bin/env python3
"""RU: Подготовка одноразового CI и native smoke; упаковка только через release.py.
EN: Ephemeral CI preparation and native smoke; packaging only through release.py.
"""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import platform
import shutil
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("release", ROOT / "build/release.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
TOOLS = json.loads((ROOT / "build/common/ci-tools.json").read_text())


def run(command, **kwargs):
    subprocess.run([str(a) for a in command], check=True, **kwargs)


def download(specification, destination):
    destination = release.external(destination)
    if destination.exists():
        raise ValueError("CI download не перезаписывает существующие файлы")
    if not specification["url"].startswith("https://"):
        raise ValueError("CI download требует HTTPS")
    run(["curl", "--fail", "--location", "--proto", "=https", "--proto-redir", "=https",
         "--tlsv1.2", "--max-time", "300", "--max-filesize", "67108864", "--output", destination, specification["url"]])
    if (release.sha256(destination) != specification["sha256"]
            or ("size" in specification and destination.stat().st_size != specification["size"])):
        raise ValueError("CI download: SHA256/размер не совпал: " + destination.name)
    return destination


def snapshot_evidence(directory):
    config = TOOLS["linux"]
    directory.mkdir()
    for suite, digest in config["inrelease_sha256"].items():
        path = download({"url": config["base_url"] + "dists/" + suite + "/InRelease", "sha256": digest}, directory / (suite + "-InRelease"))
        result = release.output(["gpgv", "--status-fd", "1", "--keyring", "/usr/share/keyrings/ubuntu-archive-keyring.gpg", path])
        if "[GNUPG:] VALIDSIG " + config["archive_fingerprint"] + " " not in result:
            raise ValueError("Snapshot: неверный подписант")
    for package in config["packages"].values():
        download({"url": config["base_url"] + package["path"], "sha256": package["sha256"]}, directory / Path(package["path"]).name)


def composer(session):
    return download(TOOLS["composer"], session / "composer.phar")


def locate_artifact(base, target, source_sha):
    candidates = list(base.glob("tvm-release-*/artifacts/" + release.filename(target)))
    if len(candidates) != 1:
        raise ValueError("CI: ожидался ровно один итоговый архив")
    release.verify_artifact(candidates[0], target, source_sha)
    return candidates[0]


def publish_verified(session, archive, target, source_sha):
    release.verify_artifact(archive, target, source_sha)
    destination = session / "verified"
    destination.mkdir()
    for path in (archive, archive.with_name(archive.name + ".sha256")):
        shutil.copyfile(path, destination / path.name)
    release.verify_artifact(destination / archive.name, target, source_sha)
    with Path(os.environ["GITHUB_OUTPUT"]).open("a", encoding="utf-8") as stream:
        stream.write("artifact-path=" + str(destination) + "\n")


def native(target, session, source_sha):
    phar = composer(session)
    php = release.executable("php")
    if not release.output([php, phar, "--no-plugins", "--version"]).startswith("Composer version " + TOOLS["composer"]["version"] + " "):
        raise ValueError("CI: версия Composer не совпала")
    env = os.environ.copy()
    temporary = session / "native-smoke"
    temporary.mkdir()
    for key in ("TMPDIR", "TMP", "TEMP"):
        env[key] = str(temporary)
    extra = []
    if target == "windows":
        extractor = release.executable("7z")
        installer = download(TOOLS["seven_zip"], session / "7zip-installer.exe")
        seven = session / "7zip"
        run([extractor, "x", "-y", "-o" + str(seven), installer, "7z.exe", "7z.dll"])
        if not (seven / "7z.exe").is_file() or not (seven / "7z.dll").is_file():
            raise ValueError("CI: нет полного 7-Zip из проверенного installer")
        env["PATH"] = str(seven) + os.pathsep + env["PATH"]
        pins = json.loads((ROOT / "build/common/windows-crt.json").read_text())
        crt = download(pins["installer"], session / "vc_redist.x64.exe")
        license_file = download(pins["license"], session / "Microsoft-CRT-LICENSE.docx")
        extra = ["--windows-crt-installer", crt, "--windows-crt-license", license_file]
        smoke = [release.executable("powershell.exe"), "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
                 "-File", ROOT / "tests/WindowsPortableSmoke.ps1", "-Archive"]
    else:
        for tool in ("xcrun", "cc", "make", "pkg-config", "gpg", "gpgv", "otool", "codesign", "bash", "tput", "dirname"):
            release.executable(tool)
        descriptors = os.openpty()
        for descriptor in descriptors:
            os.close(descriptor)
        smoke = ["bash", ROOT / "tests/MacOSPortableSmoke.sh"]
    command = [sys.executable, ROOT / "build/release.py", "--targets", target, "--source-sha", source_sha,
               "--build-php", php, "--composer", phar, "--cache", session / "cache", "--output-dir", session, *extra]
    run(command, env=env)
    artifact = locate_artifact(session, target, source_sha)
    run([*smoke, artifact], env=env)
    publish_verified(session, artifact, target, source_sha)


def linux(session, source_sha):
    for tool in ("docker", "git", "sudo", "dpkg-deb", "ldd", "bash", "tput", "dirname"):
        release.executable(tool)
    for name in ("/usr/share/terminfo", "/lib/x86_64-linux-gnu/libtinfo.so.6", "/usr/bin/tput", "/usr/bin/dirname"):
        if not Path(name).exists():
            raise ValueError("CI: нет prerequisite native smoke: " + name)
    for descriptor in os.openpty():
        os.close(descriptor)
    composer(session)
    action = session / "setup-php"
    run(["git", "init", action])
    run(["git", "-C", action, "fetch", "--depth=1", TOOLS["setup_php"]["repository"], TOOLS["setup_php"]["commit"]])
    run(["git", "-C", action, "checkout", "--detach", "FETCH_HEAD"])
    if release.output(["git", "-C", action, "rev-parse", "HEAD"]) != TOOLS["setup_php"]["commit"]:
        raise ValueError("CI: setup-php commit не совпал")
    name = "tvm-" + session.name.lower()
    image = TOOLS["linux"]["image"]
    run(["docker", "pull", "--platform", "linux/amd64", image])
    started = False
    try:
        run(["docker", "run", "--detach", "--name", name, "--platform", "linux/amd64",
             "--mount", "type=bind,source=" + str(ROOT) + ",target=/src,readonly",
             "--mount", "type=bind,source=" + str(session) + ",target=/work",
             "--mount", "type=bind,source=" + str(action) + ",target=/setup-php,readonly",
             "--env", "TVM_CI_CONTAINER=1", "--env", "GITHUB_ACTIONS=true", "--env", "PYTHONDONTWRITEBYTECODE=1",
             "--env", "GIT_CONFIG_COUNT=1", "--env", "GIT_CONFIG_KEY_0=safe.directory", "--env", "GIT_CONFIG_VALUE_0=/src",
             image, "sleep", "infinity"])
        started = True
        run(["docker", "exec", name, "mkdir", "-p", "/etc/ssl/certs"])
        run(["docker", "cp", "/etc/ssl/certs/ca-certificates.crt", name + ":/etc/ssl/certs/ca-certificates.crt"])
        run(["docker", "exec", name, "bash", "/src/build/ci/linux-snapshot.sh"])
        bubble = TOOLS["linux"]["packages"]["bubblewrap"]
        deb = session / "snapshot-inputs" / Path(bubble["path"]).name
        if release.sha256(deb) != bubble["sha256"]:
            raise ValueError("CI: bubblewrap package SHA256")
        smoke_tools = session / "smoke-tools"
        run(["dpkg-deb", "--extract", deb, smoke_tools])
        bwrap = smoke_tools / "usr/bin/bwrap"
        run(["ldd", bwrap])
        smoke_env = ["env", "PYTHONDONTWRITEBYTECODE=1", "PATH=/usr/bin:/bin"]
        # RU: Native smoke вне Docker: без изменения seccomp, AppArmor или пакетов хоста.
        # EN: Native smoke outside Docker: no host seccomp, AppArmor or package changes.
        run(["sudo", "-n", *smoke_env, bwrap, "--unshare-user", "--unshare-pid", "--unshare-net", "--ro-bind", "/", "/", "/usr/bin/true"])
        version = release.output(["docker", "exec", name, "/usr/bin/php8.5", "/work/composer.phar", "--no-plugins", "--version"])
        if not version.startswith("Composer version " + TOOLS["composer"]["version"] + " "):
            raise ValueError("CI: версия Composer не совпала")
        build_user = f"{os.getuid()}:{os.getgid()}"
        (session / "build-home").mkdir(mode=0o700)
        run(["docker", "exec", "--user", build_user, "--env", "HOME=/work/build-home", "--env", "CC=gcc-13",
             name, "python3", "/src/build/release.py", "--targets", "linux",
             "--source-sha", source_sha, "--build-php", "/usr/bin/php8.5", "--composer", "/work/composer.phar",
             "--cache", "/work/cache", "--output-dir", "/work"])
        artifact = locate_artifact(session, "linux", source_sha)
        temporary = session / "native-smoke"
        temporary.mkdir()
        run(["sudo", "-n", *smoke_env, "TMPDIR=" + str(temporary), sys.executable,
             ROOT / "tests/portable/runtime_smoke.py", artifact, "--bwrap", bwrap])
        publish_verified(session, artifact, "linux", source_sha)
    finally:
        if started:
            run(["docker", "rm", "--force", name])


def main():
    parser = argparse.ArgumentParser(description="Только одноразовый GitHub Actions CI")
    parser.add_argument("target", choices=release.TARGETS)
    parser.add_argument("--source-sha", required=True)
    args = parser.parse_args()
    if os.environ.get("GITHUB_ACTIONS") != "true" or os.environ.get("GITHUB_SHA") != args.source_sha:
        raise ValueError("Bootstrap разрешён только в CI для точного GITHUB_SHA")
    expected = {"linux": ("Linux", "x86_64"), "windows": ("Windows", "AMD64"), "macos": ("Darwin", "arm64")}
    if (platform.system(), platform.machine()) != expected[args.target]:
        raise ValueError("CI: неверная native ОС/архитектура")
    release.check_source_sha(args.source_sha)
    release.executable("curl")
    temporary = release.external(os.environ["RUNNER_TEMP"])
    session = Path(tempfile.mkdtemp(prefix="tvm-ci-" + args.target + "-", dir=temporary))
    if args.target == "linux":
        linux(session, args.source_sha)
    else:
        native(args.target, session, args.source_sha)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, subprocess.SubprocessError) as error:
        sys.exit("CI остановлен: " + str(error))
