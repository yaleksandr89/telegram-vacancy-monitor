<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$inspector = $root . '/src/ui/windows/inspect.php';
foreach ([80499, 80500, 80511, 80512, 80599, 80600, 89999, 90000] as $version) {
    $process = proc_open([PHP_BINARY, $inspector, 'version-policy', (string) $version], [STDIN, ['pipe', 'w'], STDERR], $pipes);
    if (!is_resource($process)) {
        exit(1);
    }
    $actual = json_decode(stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $actual !== [
        'developer' => $version >= 80500 && $version < 90000,
        'bundle' => $version >= 80512 && $version < 80600,
    ]) {
        throw new RuntimeException('Нарушена политика совместимости PHP.');
    }
}
fwrite(STDOUT, "Границы Composer ^8.5 и отдельного bundle runtime: PASS.\n");
putenv('PHP_BINARY=' . PHP_BINARY);
$command = match (PHP_OS_FAMILY) {
    'Linux' => ['bash', $root . '/tests/GumShellSmoke.sh'],
    'Windows' => [
        (getenv('SystemRoot') ?: 'C:\\Windows') . '/System32/WindowsPowerShell/v1.0/powershell.exe',
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $root . '/tests/portable/windows_ui_smoke.ps1',
        '-PhpBinary', PHP_BINARY,
    ],
    default => null,
};
if ($command === null) {
    fwrite(STDOUT, "NOT VERIFIED: UI suite для этой ОС не выполнялась. Native archive smoke запускается отдельно.\n");
    exit(0);
}
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root);
if (!is_resource($process)) {
    exit(1);
}
exit(proc_close($process));
