<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [$root . '/.php-cs-fixer.dist.php'];
foreach (['bin', 'public', 'src', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') {
            continue;
        }
        $paths[] = $file->getPathname();
    }
}
foreach ($paths as $path) {
    $process = proc_open([PHP_BINARY, '-l', $path], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "Не удалось запустить проверку синтаксиса.\n");
        exit(1);
    }
    $code = proc_close($process);
    if ($code !== 0) {
        exit($code);
    }
}
