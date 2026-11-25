import hashlib
import importlib.util
import io
import json
import copy
import contextlib
import struct
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest import mock
import zipfile

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("packaging", ROOT / "build/common/package.py")
packaging = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packaging)


class BuildBoundary(unittest.TestCase):
    @staticmethod
    def unix_fixture(base, target):
        previous = base / 'previous'
        runtime = previous / 'runtime'
        runtime.mkdir(parents=True)
        metadata = {'target': target, 'inputs': copy.deepcopy(packaging.INPUTS)}
        (runtime / 'PROVENANCE.json').write_text(json.dumps(metadata))
        (runtime / 'php').write_bytes(b'synthetic php')
        if target == 'linux-x86_64':
            pins = {}
            for name in json.loads((ROOT / 'build/common/linux-libraries.json').read_text()):
                data = ('synthetic ' + name).encode()
                path = runtime / (name if name.startswith('ld-linux') else 'lib/' + name)
                path.parent.mkdir(exist_ok=True)
                path.write_bytes(data)
                pins[name] = hashlib.sha256(data).hexdigest()
            (runtime / 'linux-libraries.json').write_text(json.dumps(pins))
            config = base / 'build/common'
            config.mkdir(parents=True)
            (config / 'linux-libraries.json').write_text(json.dumps(pins))
        manifest = {p.relative_to(previous).as_posix(): packaging.digest(p)
                    for p in runtime.rglob('*') if p.is_file()}
        return previous, metadata, manifest

    def test_unix_reuse_provenance(self):
        for target in ['linux-x86_64', 'macos-arm64']:
            with self.subTest(target=target), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                previous, metadata, manifest = self.unix_fixture(base, target)
                with mock.patch.object(packaging, 'ROOT', base):
                    packaging.verify_unix_reuse(previous, metadata, manifest, target)
                    for action in ['empty', 'missing', 'extra', 'changed', 'previous-php', 'target']:
                        bad = copy.deepcopy(metadata)
                        if action == 'empty': bad['inputs'] = {}
                        if action == 'missing': del bad['inputs']['php']
                        if action == 'extra': bad['inputs']['unknown'] = {}
                        if action == 'changed': bad['inputs']['php']['sha256'] = '0' * 64
                        if action == 'previous-php':
                            bad['inputs']['php']['sha256'] = '08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209'
                        if action == 'target': bad['target'] = 'windows-x64'
                        with self.subTest(action=action), self.assertRaisesRegex(ValueError, 'inputs/target'):
                            packaging.verify_unix_reuse(previous, bad, manifest, target)

    def test_unix_reuse_manifest(self):
        for target in ['linux-x86_64', 'macos-arm64']:
            for action in ['missing-file', 'extra-file', 'missing-key', 'extra-key',
                           'hash', 'tamper', 'symlink', 'directory-link', 'fifo']:
                with self.subTest(target=target, action=action), tempfile.TemporaryDirectory() as directory:
                    base = Path(directory)
                    previous, metadata, manifest = self.unix_fixture(base, target)
                    php = previous / 'runtime/php'
                    if action == 'missing-file': php.unlink()
                    if action == 'extra-file': (php.parent / 'extra').write_bytes(b'extra')
                    if action == 'missing-key': del manifest['runtime/php']
                    if action == 'extra-key': manifest['runtime/absent'] = '0' * 64
                    if action == 'hash': manifest['runtime/php'] = '0' * 64
                    if action == 'tamper': php.write_bytes(b'tampered')
                    if action == 'symlink':
                        php.unlink()
                        php.symlink_to('PROVENANCE.json')
                    if action == 'directory-link':
                        (php.parent / 'linked').symlink_to(base, target_is_directory=True)
                    if action == 'fifo':
                        packaging.os.mkfifo(php.parent / 'pipe')
                    with mock.patch.object(packaging, 'ROOT', base), self.assertRaises(ValueError):
                        packaging.verify_unix_reuse(previous, metadata, manifest, target)

    def test_linux_reuse_pins(self):
        for action in ['valid', 'historical', 'json', 'missing-loader', 'misplaced-loader',
                       'extra-library', 'missing-library']:
            with self.subTest(action=action), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                previous, metadata, _ = self.unix_fixture(base, 'linux-x86_64')
                runtime = previous / 'runtime'
                record = runtime / 'linux-libraries.json'
                if action == 'historical':
                    (runtime / 'lib/libc.so.6').write_bytes(b'historical glibc')
                    pins = json.loads(record.read_text())
                    pins['libc.so.6'] = packaging.digest(runtime / 'lib/libc.so.6')
                    record.write_text(json.dumps(pins))
                if action == 'json': record.write_text('{}')
                if action == 'missing-loader': (runtime / 'ld-linux-x86-64.so.2').unlink()
                if action == 'misplaced-loader':
                    (runtime / 'ld-linux-x86-64.so.2').rename(runtime / 'lib/ld-linux-x86-64.so.2')
                if action == 'extra-library': (runtime / 'lib/extra.so').write_bytes(b'extra')
                if action == 'missing-library': (runtime / 'lib/libm.so.6').unlink()
                manifest = {p.relative_to(previous).as_posix(): packaging.digest(p)
                            for p in runtime.rglob('*') if p.is_file()}
                with mock.patch.object(packaging, 'ROOT', base):
                    if action == 'valid':
                        packaging.verify_unix_reuse(previous, metadata, manifest, 'linux-x86_64')
                    else:
                        with self.assertRaises(ValueError):
                            packaging.verify_unix_reuse(previous, metadata, manifest, 'linux-x86_64')

    def test_linux_copied_bytes(self):
        for action in ['valid', 'bad-copy', 'source-changed', 'unapproved-source', 'restored-source']:
            with self.subTest(action=action), tempfile.TemporaryDirectory() as directory:
                base = Path(directory)
                source = base / 'libc.so.6'
                source.write_bytes(b'approved')
                expected = {'libc.so.6': packaging.digest(source)}
                runtime = base / 'runtime'
                runtime.mkdir()

                def copy_library(src, dest):
                    data = src.read_bytes()
                    if action in ['bad-copy', 'unapproved-source']: data = b'wrong'
                    if action == 'restored-source': data = b'approved'
                    dest.write_bytes(data)
                    if action == 'source-changed': src.write_bytes(b'changed')
                    if action in ['unapproved-source', 'restored-source']: src.write_bytes(b'approved')

                if action in ['unapproved-source', 'restored-source']: source.write_bytes(b'wrong')
                with mock.patch.object(packaging.shutil, 'copyfile', side_effect=copy_library):
                    if action == 'valid':
                        packaging.copy_linux_libraries(str(source), runtime, expected)
                        self.assertEqual(expected, json.loads((runtime / 'linux-libraries.json').read_text()))
                        self.assertEqual(expected['libc.so.6'], packaging.digest(runtime / 'lib/libc.so.6'))
                    else:
                        with self.assertRaisesRegex(ValueError, 'libc.so.6: ожидаемый SHA256=.*фактический SHA256='):
                            packaging.copy_linux_libraries(str(source), runtime, expected)
                        self.assertFalse((runtime / 'linux-libraries.json').exists())

    def test_macos_dependencies(self):
        def output(name, suffix=''):
            return '/build path/php:\n\t' + name + ' (compatibility version 1.0.0, current version 2.3.4' + suffix + ')\n'

        for name in ['/usr/lib/libSystem.B.dylib',
                     '/System/Library/Frameworks/CoreFoundation.framework/Versions/A/CoreFoundation']:
            for suffix in ['', ', weak', ', reexport', ', upward', ', lazy']:
                packaging.verify_macos_dependencies(output(name, suffix))
        for name in ['@rpath/libbad.dylib', '@loader_path/libbad.dylib',
                     '@executable_path/libbad.dylib', '/opt/homebrew/lib/libbad.dylib',
                     'relative.dylib', '/usr/library/libbad.dylib', '/Systematic/libbad.dylib',
                     '/usr/lib/../../opt/libbad.dylib', '/usr/lib/./libbad.dylib']:
            with self.subTest(name=name), self.assertRaises(ValueError):
                packaging.verify_macos_dependencies(output(name))
        for malformed in ['', 'php:\n', 'php:\n\t/usr/lib/libSystem.B.dylib\n',
                          'php:\n\tBad offset (24) for name of LC_LOAD_DYLIB command 1\n']:
            with self.subTest(output=malformed), self.assertRaises(ValueError):
                packaging.verify_macos_dependencies(malformed)
        with self.assertRaises(ValueError):
            packaging.verify_macos_dependencies(output('/usr/lib/libSystem.B.dylib')
                                                + output('@rpath/libbad.dylib').split('\n', 1)[1])

    def test_linux_library_diagnostics(self):
        names = ['libc.so.6', 'libm.so.6', 'ld-linux-x86-64.so.2',
                 'libssl.so.3', 'libcrypto.so.3', 'libgcc_s.so.1']
        expected = {name: hashlib.sha256(name.encode()).hexdigest() for name in names}
        packaging.verify_linux_libraries(dict(expected), expected)
        actual = dict(expected)
        for name in names[:3]:
            actual[name] = hashlib.sha256(('changed ' + name).encode()).hexdigest()
        with self.assertRaises(ValueError) as error:
            packaging.verify_linux_libraries(actual, expected)
        message = str(error.exception)
        for name in names[:3]:
            self.assertIn(name + ': ожидаемый SHA256=' + expected[name]
                          + ', фактический SHA256=' + actual[name], message)
        for name in names[3:]:
            self.assertNotIn(name, message)
        for missing in [True, False]:
            actual = dict(expected)
            name = names[0] if missing else 'unexpected.so'
            if missing:
                del actual[name]
            else:
                actual[name] = 'a' * 64
            with self.subTest(missing=missing), self.assertRaises(ValueError) as error:
                packaging.verify_linux_libraries(actual, expected)
            self.assertIn(name + ': ожидаемый SHA256=' + expected.get(name, '<отсутствует>')
                          + ', фактический SHA256=' + actual.get(name, '<отсутствует>'),
                          str(error.exception))

    def test_crt_fail_closed_inputs(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'input'
            path.write_bytes(b'bad')
            for candidate in [None, path, path.parent / 'missing']:
                with self.assertRaises(ValueError):
                    packaging.checked_input(candidate, packaging.WINDOWS_CRT['installer'])
            link = path.parent / 'link'
            link.symlink_to(path)
            with self.assertRaises(ValueError):
                packaging.checked_input(link, {'sha256': packaging.digest(path)})
            linkdir = path.parent / 'linked-directory'
            linkdir.symlink_to(path.parent, target_is_directory=True)
            with self.assertRaises(ValueError):
                packaging.checked_input(linkdir / 'input', {'sha256': packaging.digest(path)})

    def test_crt_signature_and_architecture(self):
        pin = packaging.WINDOWS_CRT['dll']
        valid = {k: pin[k] for k in ['subject', 'thumbprint', 'sha256', 'version']}
        valid['status'] = 'Valid'
        packaging.check_authenticode_result(valid, pin)
        for field in valid:
            with self.subTest(field=field), self.assertRaises(ValueError):
                packaging.check_authenticode_result(dict(valid, **{field: 'wrong'}), pin)
        for data in [b'', b'MZ', self.pe(0x14c), self.pe(0xaa64)]:
            with self.assertRaises(ValueError):
                packaging.check_pe_x64(data)
        packaging.check_pe_x64(self.pe())

    @staticmethod
    def pe(machine=0x8664):
        data = bytearray(256)
        data[:2] = b'MZ'
        struct.pack_into('<I', data, 60, 128)
        data[128:132] = b'PE\0\0'
        struct.pack_into('<H', data, 132, machine)
        struct.pack_into('<H', data, 152, 0x20b)
        return bytes(data)

    def test_windows_fresh_and_old_runtime_reuse(self):
        pin = copy.deepcopy(packaging.WINDOWS_CRT)
        dll, license_data = self.pe(), b'synthetic license'
        pin['dll']['sha256'] = hashlib.sha256(dll).hexdigest()
        pin['license']['sha256'] = hashlib.sha256(license_data).hexdigest()
        evidence = {name: dict({k: spec[k] for k in ['sha256', 'subject', 'thumbprint']},
                              status='Valid', version=spec.get('version'))
                    for name, spec in pin.items() if name in ['installer', 'cab', 'dll']}
        prepared = (dll, license_data, evidence)
        with tempfile.TemporaryDirectory() as directory, mock.patch.object(packaging, 'WINDOWS_CRT', pin):
            base = Path(directory)
            cache, output = base / 'cache', base / 'out'
            cache.mkdir()
            for name, members in [('go-linux', {'go/bin/go': b'go', 'go/LICENSE': b'license'}),
                                  ('gum-windows', {'gum.exe': b'gum', 'LICENSE': b'license'}),
                                  ('php-windows', {'php.exe': self.pe(), 'php8.dll': self.pe()})]:
                with zipfile.ZipFile(cache / name, 'w') as z:
                    for member, data in members.items():
                        z.writestr(member, data)
            (cache / 'ca').write_bytes(b'ca')
            real_output = packaging.subprocess.check_output

            def command_output(args, **kwargs):
                if args[1:] == ['version']:
                    return 'go synthetic\n'
                return real_output(args, **kwargs)

            def run(args, **kwargs):
                if 'dump-autoload' in args:
                    self.assertEqual(['dump-autoload', '--no-dev', '--no-scripts', '--no-plugins',
                                      '--no-interaction', '--optimize'], args[3:])
                    self.assertEqual('1', kwargs['env']['COMPOSER_DISABLE_NETWORK'])
                    self.assertFalse((Path(args[2].removeprefix('--working-dir=')) / 'vendor').exists())
                if 'build' in args:
                    Path(args[args.index('-o') + 1]).write_bytes(self.pe())

            with mock.patch.object(packaging, 'prepare_windows_crt', return_value=prepared) as prepare, \
                    mock.patch.object(packaging, 'fetch', side_effect=lambda key, *args: cache / key), \
                    mock.patch.object(packaging, 'verify_gum'), mock.patch.object(packaging, 'run', side_effect=run), \
                    mock.patch.object(packaging.subprocess, 'check_output', side_effect=command_output), \
                    mock.patch.object(packaging.platform, 'system', return_value='Linux'), \
                    contextlib.redirect_stdout(io.StringIO()):
                args = ['package.py', '--target', 'windows-x64', '--cross-windows', '--offline',
                        '--cache', str(cache), '--output-dir', str(output), '--build-php', 'fake', '--composer', 'fake']
                old = base / 'old.zip'
                old_files = {'runtime/php/php.exe': self.pe(), 'runtime/php/php8.dll': self.pe(),
                             'runtime/PROVENANCE.json': json.dumps({'target': 'windows-x64', 'inputs': packaging.INPUTS}).encode()}
                old_manifest = {name: hashlib.sha256(data).hexdigest() for name, data in old_files.items()}
                with zipfile.ZipFile(old, 'w') as z:
                    for name, data in dict(old_files, **{'SHA256SUMS.json': json.dumps(old_manifest).encode()}).items():
                        z.writestr('telegram-vacancy-monitor/' + name, data)
                previous = None
                for source in [None, old, 'new']:
                    extra = []
                    if source is not None:
                        archive = previous if source == 'new' else source
                        extra = ['--runtime-bundle', str(archive), '--runtime-sha256', packaging.digest(archive)]
                    with mock.patch.object(sys, 'argv', args + extra):
                        packaging.main()
                    archives = list(output.rglob('*.zip'))
                    previous = max(archives, key=lambda p: p.stat().st_mtime_ns)
                    with zipfile.ZipFile(previous) as z:
                        prefix = 'telegram-vacancy-monitor/'
                        manifest = json.loads(z.read(prefix + 'SHA256SUMS.json'))
                        provenance = json.loads(z.read(prefix + 'runtime/PROVENANCE.json'))
                        for name in ['composer.json', 'composer.lock', 'config/profile/README.md',
                                     'docs/langs/ru/local-gum.md', 'docs/langs/ru/vds-webhook.md',
                                     'config/bot.example.ini']:
                            self.assertEqual((ROOT / name).read_bytes(), z.read(prefix + name))
                            self.assertEqual(packaging.digest(ROOT / name), provenance['source_sha256'][name])
                        self.assertFalse(any(name.startswith(('vendor/bin/', 'vendor/phpstan/', 'vendor/friendsofphp/',
                                                              'tests/', 'config/profile/local/')) for name in manifest))
                        project_license = (ROOT / 'LICENSE').read_bytes()
                        self.assertEqual(project_license, z.read(prefix + 'LICENSE'))
                        self.assertEqual(hashlib.sha256(project_license).hexdigest(), manifest['LICENSE'])
                        self.assertEqual(dll, z.read(prefix + 'runtime/php/vcruntime140.dll'))
                        self.assertEqual(pin['dll']['sha256'], manifest['runtime/php/vcruntime140.dll'])
                        record = json.loads(z.read(prefix + 'runtime/PROVENANCE.json'))['windows_crt']
                        self.assertEqual(pin, record['pin'])
                        self.assertEqual(record, json.loads(z.read(prefix + 'runtime/WINDOWS-CRT.json')))
                        self.assertEqual(license_data, z.read(prefix + 'runtime/notices/Microsoft-Visual-C-v14-LICENSE.docx'))
                        for name, sha in manifest.items():
                            self.assertEqual(sha, hashlib.sha256(z.read(prefix + name)).hexdigest())
                        self.assertFalse(any('/config/bot.ini' in name or '/var/' in name for name in z.namelist()))
                self.assertEqual(3, prepare.call_count)
            new_root = base / 'new'
            packaging.extract(previous, new_root)
            new_package = new_root / 'telegram-vacancy-monitor'
            new_metadata = json.loads((new_package / 'runtime/PROVENANCE.json').read_text())
            new_manifest = json.loads((new_package / 'SHA256SUMS.json').read_text())
            packaging.verify_windows_reuse(new_package, new_metadata, new_manifest)
            crt = new_package / 'runtime/php/vcruntime140.dll'
            crt.unlink()
            with self.assertRaises(ValueError):
                packaging.verify_windows_reuse(new_package, new_metadata, new_manifest)
            crt.write_bytes(dll)
            new_metadata['windows_crt']['pin']['dll']['sha256'] = '0' * 64
            with self.assertRaises(ValueError):
                packaging.verify_windows_reuse(new_package, new_metadata, new_manifest)
            with self.assertRaises(ValueError):
                packaging.install_windows_crt(new_package / 'runtime', (dll + b'bad', license_data, evidence))
            root = base / 'previous'
            packaging.extract(old, root)
            previous = root / 'telegram-vacancy-monitor'
            metadata = json.loads((previous / 'runtime/PROVENANCE.json').read_text())
            packaging.verify_windows_reuse(previous, metadata, old_manifest)
            for action in ['tamper', 'missing', 'provenance']:
                php = previous / 'runtime/php/php.exe'
                php.write_bytes(self.pe())
                bad_metadata = copy.deepcopy(metadata)
                if action == 'tamper': php.write_bytes(b'bad')
                if action == 'missing': php.unlink()
                if action == 'provenance': bad_metadata['inputs'] = {}
                with self.subTest(action=action), self.assertRaises(ValueError):
                    packaging.verify_windows_reuse(previous, bad_metadata, old_manifest)

    def test_composer_graph(self):
        with tempfile.TemporaryDirectory() as directory:
            package = Path(directory)
            requirements = {'php': '^8.5', 'ext-curl': '*'}
            root = package / 'composer.json'
            root.write_text(json.dumps({'require': requirements}))
            lock = {'platform': requirements, 'packages': [], 'packages-dev': [{'name': 'phpstan/phpstan', 'version': '2.3.7'}]}
            path = package / 'composer.lock'
            path.write_text(json.dumps(lock))
            packaging.validate_composer(package)
            for key, value in [('packages', [{}]), ('packages', {}), ('packages-dev', {}), ('packages-dev', [{}]),
                               ('platform', {}), ('packages', None), ('packages-dev', None)]:
                bad = dict(lock)
                if value is None:
                    del bad[key]
                else:
                    bad[key] = value
                path.write_text(json.dumps(bad))
                with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                    packaging.validate_composer(package)
            injected = dict(requirements, **{'example/library': '*'})
            root.write_text(json.dumps({'require': injected}))
            path.write_text(json.dumps(dict(lock, platform=injected)))
            with self.assertRaises(ValueError):
                packaging.validate_composer(package)

    def test_composer_utf8_with_cp1252_default(self):
        with tempfile.TemporaryDirectory() as directory:
            package = Path(directory)
            requirements = {'php': '^8.5'}
            manifest = {'require': requirements, 'authors': [{'name': 'Автор'}]}
            lock = {'platform': requirements, 'packages': [], 'packages-dev': [], 'description': 'Автор'}
            for name, document in [('composer.json', manifest), ('composer.lock', lock)]:
                (package / name).write_text(json.dumps(document, ensure_ascii=False), encoding='utf-8')

            read_text = Path.read_text

            def read_with_cp1252_default(path, encoding=None, **kwargs):
                return read_text(path, encoding='cp1252' if encoding is None else encoding, **kwargs)

            with mock.patch.object(Path, 'read_text', read_with_cp1252_default):
                packaging.validate_composer(package)

    def test_php_signer(self):
        with tempfile.TemporaryDirectory() as directory:
            work = Path(directory)
            with mock.patch.object(packaging, 'fetch', return_value=work / 'input'), mock.patch.object(packaging, 'run'), \
                    mock.patch.object(packaging.subprocess, 'check_output') as verifier:
                verifier.return_value = '[GNUPG:] VALIDSIG D95C03BC702BE9515344AE3374E44BC9067701A5 2026-01-01'
                with self.assertRaisesRegex(ValueError, 'release manager'):
                    packaging.verify_pgp('php', packaging.PHP_SIGNER, work, work, True)
                verifier.side_effect = packaging.subprocess.CalledProcessError(1, 'gpgv')
                with self.assertRaises(packaging.subprocess.CalledProcessError):
                    packaging.verify_pgp('php', packaging.PHP_SIGNER, work, work, True)

    def test_pins(self):
        for artifact in packaging.INPUTS.values():
            self.assertEqual(64, len(artifact["sha256"]))
            self.assertTrue(artifact["url"].startswith("https://"))
            self.assertNotIn("latest", artifact["url"])

    def test_corrupt_input(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            (path / "php-8.5.13.tar.xz").write_bytes(b"corrupt")
            with self.assertRaises(ValueError):
                packaging.fetch("php", path, True)

    def test_unsafe_tar(self):
        for name, kind in [("../escape", tarfile.REGTYPE), ("/absolute", tarfile.REGTYPE),
                           ("link", tarfile.SYMTYPE), ("hard", tarfile.LNKTYPE),
                           ("fifo", tarfile.FIFOTYPE)]:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                path = Path(directory)
                archive = path / "bad.tar"
                with tarfile.open(archive, "w") as tar:
                    member = tarfile.TarInfo(name)
                    member.type = kind
                    member.linkname = "/outside"
                    tar.addfile(member)
                with self.assertRaises(ValueError):
                    packaging.extract(archive, path / "output")

    def test_unsafe_zip(self):
        for name in ["../escape", "/absolute", "C:/escape", "back\\slash", "line\nbreak", "a/./b"]:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                path = Path(directory)
                archive = path / "bad.zip"
                with zipfile.ZipFile(archive, "w") as z:
                    z.writestr(name, b"no")
                with self.assertRaises(ValueError):
                    packaging.extract(archive, path / "output")
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            archive = path / 'link.zip'
            with zipfile.ZipFile(archive, 'w') as z:
                member = zipfile.ZipInfo('link')
                member.create_system = 3
                member.external_attr = 0o120777 << 16
                z.writestr(member, '/outside')
            with self.assertRaises(ValueError):
                packaging.extract(archive, path / 'output')

    def test_duplicate(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)
            archive = path / "bad.zip"
            with zipfile.ZipFile(archive, "w") as z:
                z.writestr("file", b"a")
                z.writestr("FILE", b"b")
            with self.assertRaises(ValueError):
                packaging.extract(archive, path / "output")


unittest.main()
