import errno
import fcntl
import hashlib
import json
import os
from pathlib import Path
import pty
import select
import shutil
import signal
import subprocess
import sys
import tempfile
import termios
import time
import unittest

ROOT, PHP = Path(sys.argv[1]), sys.argv[2]
sys.argv = sys.argv[:1]


class Screens(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="tvm-ui-")
        self.base = Path(self.temp.name)
        self.app = self.base / "Приложение with spaces"
        shutil.copytree(ROOT / "src", self.app / "src")
        (self.app / "bin").mkdir()
        shutil.copyfile(ROOT / "bin/vacancy-monitor.sh", self.app / "bin/vacancy-monitor.sh")
        (self.app / "vendor").symlink_to(ROOT / "vendor", target_is_directory=True)
        (self.app / "config/profile/local").mkdir(parents=True)
        self.config = self.app / "config/bot.ini"
        self.config.write_text('TELEGRAM_BOT_TOKEN="123:synthetic-secret-marker"\nTELEGRAM_ALLOWED_USER_ID="123"\n'
                               'AI_BASE_URL="http://127.0.0.1:1234/v1"\nAI_MODEL="fixture"\n'
                               'AI_API_KEY="synthetic-key-marker"\n', encoding="utf-8")
        for name in ["candidate", "instructions"]:
            (self.app / ("config/profile/local/" + name + ".md")).write_text("private-marker")
        self.fakes = self.base / "fakes"
        self.fakes.mkdir()
        (self.fakes / "gum").write_text("""#!/usr/bin/env python3
import os, sys
from pathlib import Path
with open(os.environ['LOG'], 'a') as log: log.write(' '.join(sys.argv[1:])+'\\n')
if sys.argv[1] in ['format', 'style']:
    print(sys.stdin.read() if sys.argv[1] == 'format' else sys.argv[-1])
    sys.exit(0)
queue = Path(os.environ['CHOICES'])
lines = queue.read_text().splitlines()
if not lines: sys.exit(2)
choice = lines.pop(0)
queue.write_text('\\n'.join(lines)+'\\n')
if choice == 'cancel': sys.exit(1)
if choice == 'interrupt': sys.exit(130)
if choice == 'error': sys.exit(2)
labels = sys.argv[sys.argv.index('--')+1:]
print(labels[int(choice)-1])
""")
        (self.fakes / "php").write_text("""#!/usr/bin/env python3
import os, sys, time, json
from pathlib import Path
if sys.argv[-1] == 'runtime' and os.environ.get('BAD_RUNTIME') == '1': sys.exit(1)
if sys.argv[-1] == 'check' and os.environ.get('BROKEN_CHECK') == '1': sys.exit(1)
if any(x.endswith('/bin/bot.php') for x in sys.argv):
    with open(os.environ['BOT_LOG'], 'a') as log: log.write(' '.join(sys.argv[1:])+'\\n')
    sys.stderr.write(os.environ.get('BOT_STDERR', ''))
    if 'identify' in sys.argv:
        queue = Path(os.environ['IDS'])
        lines = queue.read_text().splitlines()
        print(lines.pop(0))
        queue.write_text('\\n'.join(lines)+'\\n')
        sys.exit(int(os.environ.get('BOT_EXIT', '0')))
    if os.environ.get('WAIT') == '1':
        ready = Path(os.environ['PIDFILE'])
        ready.with_suffix('.tmp').write_text(str(os.getpid()))
        ready.with_suffix('.tmp').replace(ready)
        while True: time.sleep(1)
    if os.environ.get('BOT_STREAM') == '1':
        print('LIVE streaming', flush=True)
        time.sleep(1)
    print('fixture monitoring')
    sys.exit(int(os.environ.get('BOT_EXIT', '0')))
os.execv(os.environ['REAL_PHP'], [os.environ['REAL_PHP']]+sys.argv[1:])
""")
        for path in self.fakes.iterdir():
            path.chmod(0o700)
        self.env = dict(os.environ, PHP_BINARY=str(self.fakes / "php"), REAL_PHP=PHP,
                        PATH=str(self.fakes) + os.pathsep + os.environ["PATH"], TERM="dumb",
                        CHOICES=str(self.base / "choices"), LOG=str(self.base / "log"),
                        BOT_LOG=str(self.base / "botlog"), IDS=str(self.base / "ids"),
                        PIDFILE=str(self.base / "pid"))
        (self.base / "ids").write_text('{"status":"empty","ids":[]}\n{"status":"found","ids":[123456]}\n')
        self.snapshot = self.private_hash()

    def tearDown(self):
        self.assertEqual(self.snapshot, self.private_hash())
        self.assertFalse((self.app / "var").exists())
        self.temp.cleanup()

    def private_hash(self):
        return {str(p): hashlib.sha256(p.read_bytes()).hexdigest() for p in (self.app / "config").rglob("*") if p.is_file()}

    def launch(self, choices, status=0, interrupt=False):
        import shlex
        (self.base / "choices").write_text("\n".join(str(c) for c in choices)+"\n")
        if interrupt:
            returncode, output = self.interrupt_terminal()
        else:
            cmd = "bash " + shlex.quote(str(self.app / "bin/vacancy-monitor.sh"))
            result = subprocess.run(["script", "--quiet", "--return", "--command", cmd, "/dev/null"],
                                    env=self.env, cwd=self.base, stdin=subprocess.DEVNULL,
                                    stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=15)
            returncode, output = result.returncode, result.stdout
        self.output = output.decode(errors="replace")
        self.assertEqual(status, returncode, self.output[-2000:])
        for marker in ["synthetic-secret-marker", "synthetic-key-marker", "private-marker", str(self.app)]:
            self.assertNotIn(marker, self.output)
        return self.output

    def interrupt_terminal(self):
        pid, terminal = pty.fork()
        if pid == 0:
            try:
                # RU: Новый терминал моделирует запуск из интерактивной оболочки.
                # EN: The new terminal models a launch from an interactive shell.
                signal.signal(signal.SIGINT, signal.SIG_DFL)
                os.chdir(self.base)
                os.execvpe('bash', ['bash', str(self.app / 'bin/vacancy-monitor.sh')], self.env)
            except BaseException:
                os.write(2, 'Не удалось запустить UI в PTY.\n'.encode())
                os._exit(127)
        output = bytearray()
        status = None
        child = None

        def diagnostic():
            return (f'UI PID/PGID={pid}, child={child}, status={status}; '
                    + output.decode(errors='replace')[-2000:])

        def poll():
            nonlocal status
            if select.select([terminal], [], [], 0.02)[0]:
                try:
                    output.extend(os.read(terminal, 65536))
                    del output[:-65536]
                except OSError as error:
                    if error.errno != errno.EIO:
                        raise
            if status is None:
                waited, value = os.waitpid(pid, os.WNOHANG)
                if waited:
                    status = value

        try:
            deadline = time.monotonic() + 10
            ready = self.base / 'pid'
            while not ready.exists():
                poll()
                self.assertIsNone(status, 'UI завершился до готовности child: ' + diagnostic())
                self.assertLess(time.monotonic(), deadline, 'Child не готов: ' + diagnostic())
            child = int(ready.read_text())
            self.assertIsNone(status, diagnostic())
            self.assertEqual(pid, os.getsid(child), diagnostic())
            self.assertEqual(pid, os.getpgid(child), diagnostic())
            self.assertEqual(pid, os.tcgetpgrp(terminal), diagnostic())
            attributes = termios.tcgetattr(terminal)
            self.assertTrue(attributes[3] & termios.ISIG, diagnostic())
            self.assertEqual(b'\x03', attributes[6][termios.VINTR], diagnostic())
            os.write(terminal, b'\x03')
            deadline = time.monotonic() + 5
            while status is None:
                poll()
                self.assertLess(time.monotonic(), deadline, 'UI не завершился после Ctrl+C: ' + diagnostic())
            self.assertEqual(130, os.waitstatus_to_exitcode(status), diagnostic())
            deadline = time.monotonic() + 3
            while True:
                try:
                    os.kill(child, 0)
                except ProcessLookupError:
                    break
                self.assertLess(time.monotonic(), deadline, 'Child остался после Ctrl+C: ' + diagnostic())
                poll()
            return os.waitstatus_to_exitcode(status), bytes(output)
        finally:
            # RU: Очищаем только отдельную группу теста, в том числе при провале проверки.
            # EN: Clean only the isolated test group, including after a failed assertion.
            try:
                os.killpg(pid, signal.SIGINT)
                deadline = time.monotonic() + 1
                while time.monotonic() < deadline:
                    poll()
                    try:
                        os.killpg(pid, 0)
                    except ProcessLookupError:
                        break
                try:
                    os.killpg(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
            except ProcessLookupError:
                pass
            finally:
                if status is None:
                    os.waitpid(pid, 0)
                os.close(terminal)

    def test_help_and_languages(self):
        out = self.launch([4, 1, 1, 2, 1, 3, 1, 4, 1, 5, 2, 5, 2, 4, 1, 2, 6])
        self.assertIn("Быстрый старт", out)
        self.assertIn("Quick start", out)
        self.assertIn("Goodbye.", out)
        log = (self.base / "log").read_text()
        self.assertIn("format --type markdown", log)
        self.assertIn("style --border", log)
        self.assertFalse((self.base / "botlog").exists())

    def test_readiness_navigation(self):
        out = self.launch([2, 1, 1, 1, 2, 1, 3, 1, 4, 1, 5, 2, 3, 6])
        self.assertIn("Локальные предпосылки готовы", out)
        self.assertIn("Сеть, ключи", out)
        self.assertFalse((self.base / "botlog").exists())

    def test_unchecked_groups(self):
        self.env['BROKEN_CHECK'] = '1'
        out = self.launch([2, 3, 6])
        self.assertIn('Настройки: НЕ ПРОВЕРЕНО', out)
        self.assertIn('Подготовка не завершена', out)

    def test_identify_retry(self):
        out = self.launch([3, 1, 2, 3, 6])
        self.assertIn("пока нет", out)
        self.assertIn("123456", out)
        self.assertEqual(2, (self.base / "botlog").read_text().count("identify --json"))

    def test_id_pages_and_error(self):
        (self.base / 'ids').write_text(json.dumps({'status': 'found', 'ids': list(range(100001, 100013))}) + '\n')
        out = self.launch([3, 1, 3, 4, 5, 6])
        self.assertIn('100001', out)
        self.assertIn('100006', out)
        (self.base / 'ids').write_text('{"status":"error","ids":[]}\n')
        out = self.launch([3, 1, 3, 6])
        self.assertIn('Не удалось получить ID', out)

    def test_bad_runtime(self):
        self.env['BAD_RUNTIME'] = '1'
        out = self.launch([1, 2, 6])
        self.assertIn('Среда PHP приложения', out)
        self.assertFalse((self.base / 'botlog').exists())

    def test_live_stdout_and_bounded_record(self):
        self.env['BOT_STREAM'] = '1'
        self.env['BOT_STDERR'] = 'synthetic-secret-marker' * 3000
        inspector = self.app / 'src/ui/windows/inspect.php'
        diagnostic = subprocess.check_output([PHP, inspector, 'diagnostic-create'], env=self.env).decode()
        try:
            command = [PHP, '-d', 'memory_limit=16M', inspector, 'execute', 'run', diagnostic, self.fakes / 'php']
            process = subprocess.Popen(command, env=self.env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(b'LIVE streaming\n', process.stdout.readline())
            self.assertIsNone(process.poll())
            output, error = process.communicate(timeout=5)
            self.assertEqual(0, process.returncode)
            self.assertNotIn(b'synthetic-secret-marker', output + error)
            record = Path(diagnostic).read_text()
            self.assertLess(len(record), 256)
            self.assertNotIn('synthetic-secret-marker', record)
        finally:
            subprocess.run([PHP, inspector, 'diagnostic-delete', diagnostic], env=self.env, check=True)

    def test_no_diagnostic_in_app(self):
        inspector = self.app / 'src/ui/windows/inspect.php'
        result = subprocess.run([PHP, '-d', 'sys_temp_dir=' + str(self.app), inspector, 'diagnostic-create'], capture_output=True)
        self.assertEqual(1, result.returncode)
        self.assertEqual(b'', result.stdout)
        self.assertEqual([], list(self.app.glob('tvm-diag-*')))

    def test_bundle_marker_keeps_security_floor(self):
        (self.app / 'runtime').mkdir()
        (self.app / 'runtime/PROVENANCE.json').write_text('{}')
        out = self.launch([2, 1, 1, 2, 6])
        self.assertIn('PHP CLI поставки: 8.5.12+', out)

    def test_webhook(self):
        self.config.write_text(self.config.read_text() + 'TELEGRAM_RECEIVE_MODE="webhook"\n')
        self.snapshot = self.private_hash()
        out = self.launch([1, 2, 3, 1, 3, 6])
        self.assertIn("Выбран webhook", out)
        self.assertFalse((self.base / "botlog").exists())

    def test_bad_token(self):
        self.config.write_text('TELEGRAM_BOT_TOKEN=""\n')
        self.snapshot = self.private_hash()
        out = self.launch([3, 1, 3, 6])
        self.assertIn("Проверьте config/bot.ini", out)
        self.assertFalse((self.base / "botlog").exists())

    def test_run_returns_and_restarts(self):
        out = self.launch([1, 1, 2, 6])
        self.assertIn("Мониторинг завершён", out)
        self.assertEqual(2, (self.base / "botlog").read_text().count("run"))
        self.env["BOT_EXIT"] = "7"
        out = self.launch([1, 2, 6])
        self.assertIn("завершился с ошибкой", out)

    def test_failure_categories_and_secrecy(self):
        self.env['BOT_EXIT'] = '7'
        cases = [
            ('Ошибка: Укажите AI_MODEL.', 'Проверьте настройки'),
            ('Ошибка: Бот уже запущен либо недоступна блокировка var/bot.lock.', 'другой polling-процесс'),
            ('Ошибка Bot API (HTTP 409, API 409).', 'другой polling-процесс'),
            ('Сетевая ошибка Bot API (cURL 60).', 'Ошибка TLS'),
            ('Сетевая ошибка Bot API (cURL 6).', 'Ошибка сети'),
            ('Ошибка Bot API (HTTP 401, API 401).', 'отклонил авторизацию'),
            ('SQLSTATE[HY000]', 'Недоступно хранилище'),
        ]
        for raw, expected in cases:
            self.env['BOT_STDERR'] = 'synthetic-secret-marker private-marker' * 3000 + '\n' + raw
            out = self.launch([1, 2, 6])
            self.assertIn(expected, out)
            self.assertIn('Код завершения: 7', out)
            self.assertLess(len(out), 20000)
        self.env['BOT_STDERR'] = 'synthetic-key-marker'
        out = self.launch([3, 1, 3, 6])
        self.assertIn('Причина не уточнена', out)
        self.assertIn('Код завершения: 7', out)
        out = self.launch([5, 2, 1, 2, 6])
        self.assertIn('Exit code: 7', out)

    def test_cancellation(self):
        self.launch([5, "cancel", 6])
        self.launch(["interrupt"], 130)
        self.launch(["error"], 2)
        self.launch(["cancel", 6])

    def test_tty(self):
        result = subprocess.run(["bash", self.app / "bin/vacancy-monitor.sh"], env=self.env,
                                capture_output=True)
        self.assertEqual(1, result.returncode)
        self.assertIn("интерактивный терминал", result.stderr.decode())

    def test_ctrl_c(self):
        self.env["WAIT"] = "1"
        self.launch([1], 130, interrupt=True)
        pid = int((self.base / "pid").read_text())
        with self.assertRaises(ProcessLookupError):
            os.kill(pid, 0)

    def test_identify_cli_json(self):
        app = self.base / "identify"
        for name in ["bin", "config", "vendor"]:
            (app / name).mkdir(parents=True)
        shutil.copyfile(ROOT / "bin/bot.php", app / "bin/bot.php")
        (app / "config/bot.ini").write_text('TELEGRAM_BOT_TOKEN="123:synthetic"\n')
        (app / "vendor/autoload.php").write_text(r"""<?php
namespace Yaleksandr\TelegramVacancyMonitor\Telegram\Http;
require getenv('PROJECT_AUTOLOAD');
final class CurlTransport implements HttpTransport {
    public function postJson(string $url, string $json, int $timeoutSeconds): HttpResponse {
        file_put_contents(getenv('TRACE'), "poll\n", FILE_APPEND);
        $request = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $lock = fopen(dirname(__DIR__) . '/var/bot.lock', 'r+');
        try {
            if ($lock === false || flock($lock, LOCK_EX | LOCK_NB)
                || !str_ends_with($url, '/getUpdates') || $request['offset'] !== 0 || $request['timeout'] !== 0) {
                throw new \LogicException('Неверный запрос или отсутствует блокировка.');
            }
            if (getenv('CASE') === 'error') { throw new \RuntimeException('synthetic-secret-marker'); }
            return new HttpResponse(200, getenv('RESPONSE'));
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }
}
""")
        command_entity = {"type": "bot_command", "offset": 0, "length": 6}
        non_commands = [
            {"text": "/starter"}, {"text": "/startfoo"}, {"text": "/start payload", "entities": [command_entity]},
            {"text": "/start", "forward_origin": {"type": "hidden_user"}},
            {"caption": "/start", "caption_entities": [command_entity]},
            {"text": "/start", "entities": [{"type": "code", "offset": 0, "length": 6}]},
            {"text": "/start", "chat": {"id": -1, "type": "group"}},
            {"text": "/start", "from": {}},
        ]

        def invoke(case, messages, json_output=True):
            updates = [{"update_id": i, "message": {
                "message_id": i, "chat": {"id": i, "type": "private"}, "from": {"id": i}, **message,
            }} for i, message in enumerate(messages, start=100)]
            args = [PHP, app / "bin/bot.php", "identify"]
            if json_output:
                args.append("--json")
            return subprocess.run(args, env=dict(os.environ, CASE=case, TRACE=str(self.base / "identify-log"),
                                                 PROJECT_AUTOLOAD=str(ROOT / "vendor/autoload.php"),
                                                 RESPONSE=json.dumps({"ok": True, "result": updates})),
                                  capture_output=True, timeout=5)

        lock = app / "var/bot.lock"
        trace = self.base / "identify-log"
        for case, messages, expected_ids, status in [
            ("empty", non_commands, [], 0),
            ("error", [], [], 1),
            ("found", non_commands + [{"text": "/start"}, {"text": "/start", "entities": [command_entity]}],
             [108, 109], 0),
        ]:
            with self.subTest(case=case):
                result = invoke(case, messages)
                self.assertEqual(status, result.returncode, result.stderr.decode())
                self.assertEqual({"status": case, "ids": expected_ids}, json.loads(result.stdout))
                self.assertNotIn(b"synthetic-secret-marker", result.stdout + result.stderr)
                self.assertEqual([lock], list(lock.parent.iterdir()))
                self.assertEqual(0o700, lock.parent.stat().st_mode & 0o777)
                self.assertEqual(0o600, lock.stat().st_mode & 0o777)
                with lock.open("r+") as descriptor:
                    fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        self.assertEqual(b"poll\npoll\npoll\n", trace.read_bytes())
        with lock.open("r+") as descriptor:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = invoke("found", [{"text": "/start"}])
            self.assertEqual(1, result.returncode)
            self.assertEqual({"status": "error", "ids": []}, json.loads(result.stdout))
            result = invoke("found", [{"text": "/start"}], json_output=False)
            self.assertEqual(1, result.returncode)
            self.assertIn("Занята блокировка var/bot.lock.", result.stderr.decode())
            self.assertEqual(b"poll\npoll\npoll\n", trace.read_bytes(), "Занятый identify вызвал Bot API.")
            self.assertEqual([lock], list(lock.parent.iterdir()), "identify открыл SQLite или удалил lock.")


unittest.main()
