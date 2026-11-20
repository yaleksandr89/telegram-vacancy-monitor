param([Parameter(Mandatory=$true)][string]$Archive)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object Text.UTF8Encoding($false)
$OutputEncoding = [Console]::OutputEncoding
if (-not [Environment]::Is64BitProcess -or [Environment]::OSVersion.Platform -ne 'Win32NT') {
    throw 'NOT VERIFIED: нужен native Windows x64'
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Expected = ((Get-Content -LiteralPath ($Archive + '.sha256') -Raw).Trim() -split '\s+')[0]
if ((Get-FileHash -LiteralPath $Archive -Algorithm SHA256).Hash.ToLowerInvariant() -cne $Expected) { throw 'Archive hash' }
$Directory = Join-Path ([IO.Path]::GetTempPath()) ('tvm-smoke-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Directory) | Out-Null
function Assert-X64Pe([string]$Base, [string]$Name) {
    $Path = Join-Path $Base $Name
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { throw "В поставке отсутствует $Name." }
    $Bytes = [IO.File]::ReadAllBytes($Path)
    $Offset = if ($Bytes.Length -ge 64) { [BitConverter]::ToInt32($Bytes, 60) } else { -1 }
    if ($Offset -lt 64 -or $Offset -gt $Bytes.Length - 6 -or
        [BitConverter]::ToUInt16($Bytes, 0) -ne 0x5A4D -or
        [BitConverter]::ToUInt32($Bytes, $Offset) -ne 0x4550 -or
        [BitConverter]::ToUInt16($Bytes, $Offset + 4) -ne 0x8664) { throw "Файл $Name не является PE x64." }
}
function Invoke-Launcher {
    $Process = New-Object System.Diagnostics.Process
    try {
        $Process.StartInfo.FileName = Join-Path $App 'vacancy-monitor.exe'
        $Process.StartInfo.WorkingDirectory = $Directory
        $Process.StartInfo.UseShellExecute = $false
        $Process.StartInfo.RedirectStandardInput = $true
        $Process.StartInfo.RedirectStandardOutput = $true
        $Process.StartInfo.RedirectStandardError = $true
        $Process.Start() | Out-Null
        $Process.StandardInput.Close()
        $OutputTask = $Process.StandardOutput.ReadToEndAsync()
        $ErrorTask = $Process.StandardError.ReadToEndAsync()
        if (-not $Process.WaitForExit(15000)) { $Process.Kill(); throw 'Истекло время ожидания launcher.' }
        if (-not [Threading.Tasks.Task]::WaitAll(@($OutputTask, $ErrorTask), 3000)) {
            throw 'Launcher не закрыл потоки вывода.'
        }
        return @{ Code = $Process.ExitCode; ErrorText = $ErrorTask.Result }
    } finally {
        $Process.Dispose()
    }
}
try {
    $Zip = [IO.Compression.ZipFile]::OpenRead($Archive)
    try {
        $Seen = @{}
        foreach ($Entry in $Zip.Entries) {
            $Name = $Entry.FullName
            if ($Name -match '(^/|\\|:|(^|/)\.\.(/|$)|[\x00-\x1f])' -or $Seen.ContainsKey($Name) -or
                (($Entry.ExternalAttributes -shr 16) -band 0xF000) -eq 0xA000 -or
                $Name -match '(^|/)(var|public|\.git|tests)(/|$)|config/bot\.ini$|config/profile/local(/|$)') {
                throw 'Unsafe archive member'
            }
            $Seen[$Name] = $true
        }
    } finally { $Zip.Dispose() }
    [IO.Compression.ZipFile]::ExtractToDirectory($Archive, $Directory)
    $Source = Join-Path $Directory 'telegram-vacancy-monitor'
    # RU: Проверяем также родительский путь с символом вне западной и кириллической ANSI-кодировок.
    # EN: Also test a parent path with a character outside Western and Cyrillic ANSI code pages.
    $UnicodeParent = Join-Path $Directory '試験'
    [IO.Directory]::CreateDirectory($UnicodeParent) | Out-Null
    $App = Join-Path $UnicodeParent 'Приложение with spaces'
    Move-Item -LiteralPath $Source -Destination $App
    $Manifest = Get-Content -LiteralPath (Join-Path $App 'SHA256SUMS.json') -Encoding UTF8 -Raw | ConvertFrom-Json
    foreach ($Property in $Manifest.PSObject.Properties) {
        if ((Get-FileHash -LiteralPath (Join-Path $App $Property.Name) -Algorithm SHA256).Hash.ToLowerInvariant() -cne $Property.Value) { throw 'Payload hash' }
    }
    $Files = @(Get-ChildItem -LiteralPath $App -Recurse -File)
    if ($Files.Count -ne @($Manifest.PSObject.Properties).Count + 1) { throw 'Unexpected payload' }
    $Runtime = Join-Path $App 'runtime/php'
    $CrtPin = Get-Content -LiteralPath (Join-Path $PSScriptRoot '../build/common/windows-crt.json') -Raw | ConvertFrom-Json
    $CrtRecord = Get-Content -LiteralPath (Join-Path $App 'runtime/WINDOWS-CRT.json') -Raw | ConvertFrom-Json
    $Provenance = Get-Content -LiteralPath (Join-Path $App 'runtime/PROVENANCE.json') -Raw | ConvertFrom-Json
    $ExpectedPin = $CrtPin | ConvertTo-Json -Depth 20 -Compress
    if (($CrtRecord.pin | ConvertTo-Json -Depth 20 -Compress) -cne $ExpectedPin -or
        ($Provenance.windows_crt | ConvertTo-Json -Depth 20 -Compress) -cne ($CrtRecord | ConvertTo-Json -Depth 20 -Compress)) { throw 'CRT provenance mismatch' }
    $LocalDlls = @('ext/php_curl.dll', 'ext/php_mbstring.dll', 'ext/php_pdo_sqlite.dll',
        'vcruntime140.dll', 'php8.dll', 'libsqlite3.dll', 'libcrypto-3-x64.dll',
        'libssl-3-x64.dll', 'libssh2.dll', 'nghttp2.dll', 'brotlidec.dll', 'brotlicommon.dll')
    foreach ($Name in $LocalDlls + 'php.exe') { Assert-X64Pe $Runtime $Name }
    foreach ($Name in @('vacancy-monitor.exe', 'runtime/gum.exe')) { Assert-X64Pe $App $Name }
    $Crt = Join-Path $Runtime 'vcruntime140.dll'
    $Signature = Get-AuthenticodeSignature -LiteralPath $Crt
    if ($Signature.Status -ne 'Valid' -or $Signature.SignerCertificate.Subject -cne $CrtPin.dll.subject -or
        $Signature.SignerCertificate.Thumbprint -cne $CrtPin.dll.thumbprint -or
        (Get-FileHash -LiteralPath $Crt -Algorithm SHA256).Hash.ToLowerInvariant() -cne $CrtPin.dll.sha256 -or
        (Get-Item -LiteralPath $Crt).VersionInfo.FileVersion -cne $CrtPin.dll.version) { throw 'CRT signature/version/hash' }
    $Php = Join-Path $Runtime 'php.exe'
    $env:PATH = Join-Path $env:SystemRoot 'System32'
    $env:PHPRC = Join-Path $Directory 'nonexistent'
    $env:PHP_INI_SCAN_DIR = $env:PHPRC
    foreach ($Key in @('PHP_BINARY', 'OPENSSL_CONF', 'OPENSSL_MODULES')) {
        [Environment]::SetEnvironmentVariable($Key, $null, 'Process')
    }
    $env:TMP = $UnicodeParent
    $env:TEMP = $UnicodeParent
    $Inspector = 'src/ui/windows/inspect.php'
    $Arguments = @('-n', '-d', 'extension_dir=runtime/php/ext',
        '-d', 'extension=curl', '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite',
        '-d', 'curl.cainfo=runtime/cacert.pem')
    Push-Location -LiteralPath $App
    try {
        $ErrorActionPreference = 'Continue'
        $Version = & $Php -n -v 2>&1
        $VersionExit = $LASTEXITCODE
        if ($VersionExit -ne 0 -or ($Version -join "`n") -notmatch '(?m)^PHP 8\.5\.\d+ \(cli\)') {
            throw "PHP CLI не запустился из Unicode-пути или не загрузил основные DLL (код $VersionExit)."
        }
        $PathProbe = '<?php exit(is_file("src/ui/windows/inspect.php") && is_dir("runtime/php/ext") && is_file("runtime/cacert.pem") ? 0 : 2);'
        $PathProbe | & $Php -n
        if ($LASTEXITCODE -ne 0) { throw "PHP CLI не разрешил относительные пути из корня приложения (код $LASTEXITCODE)." }
        $Startup = & $Php @Arguments $Inspector runtime bundle 2>&1
        $RuntimeExit = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($RuntimeExit -ne 0 -or $Startup) {
            throw "Проверка PHP/расширений не пройдена (код $RuntimeExit). DLL есть в поставке. Проверьте загрузчик и транзитивные зависимости."
        }
        $Code = '<?php require "vendor/autoload.php"; $p = new PDO("sqlite::memory:"); $p->exec("CREATE TABLE smoke (id INTEGER)"); exit(is_readable(ini_get("curl.cainfo")) ? 0 : 2);'
        $Code | & $Php @Arguments
        if ($LASTEXITCODE -ne 0) { throw "Сбой загрузки классов, SQLite или CA (код $LASTEXITCODE)." }

        # RU: Без bot.ini настоящий дочерний bot.php завершается до сети и создания состояния.
        # EN: Without bot.ini the real child bot.php exits before network access or state creation.
        if (Test-Path -LiteralPath (Join-Path $App 'config/bot.ini')) { throw 'В пакете найден приватный bot.ini.' }
        $Diagnostic = & $Php @Arguments $Inspector diagnostic-create
        if ($LASTEXITCODE -ne 0 -or -not $Diagnostic) { throw 'Не удалось создать временный файл диагностики PHP.' }
        try {
            & $Php @Arguments $Inspector execute run $Diagnostic $Php @Arguments
            $ChildExit = $LASTEXITCODE
            $Result = & $Php @Arguments $Inspector diagnostic-read $Diagnostic
            if ($LASTEXITCODE -ne 0 -or $ChildExit -ne 1 -or $Result -cne "config`t1") {
                throw "Дочерний bot.php не дошёл до проверки конфигурации (код $ChildExit, диагностика: $Result)."
            }
        } finally {
            & $Php @Arguments $Inspector diagnostic-delete $Diagnostic
            if ($LASTEXITCODE -ne 0) { throw 'Не удалось удалить временный файл диагностики PHP.' }
        }
    } finally {
        Pop-Location
    }
    $Probe = Start-Process -FilePath $Php -WorkingDirectory $App -ArgumentList ($Arguments + @('-r', 'sleep(30);')) -PassThru -NoNewWindow
    try {
        for ($Attempt = 0; $Attempt -lt 20; $Attempt++) {
            Start-Sleep -Milliseconds 100
            $Probe.Refresh()
            if ($Probe.HasExited) { throw "PHP завершился до проверки загруженных DLL (код $($Probe.ExitCode))." }
            $Loaded = @{}
            foreach ($Module in $Probe.Modules) { $Loaded[$Module.ModuleName] = $Module.FileName }
            $Unresolved = @($LocalDlls | Where-Object {
                $ExpectedPath = [IO.Path]::GetFullPath((Join-Path $Runtime $_))
                $Loaded[[IO.Path]::GetFileName($_)] -ine $ExpectedPath
            })
            if ($Unresolved.Count -eq 0) { break }
        }
        if ($Unresolved.Count -ne 0) { throw "DLL не загружены из поставки: $($Unresolved -join ', ')." }
    } finally {
        if (-not $Probe.HasExited) { $Probe.Kill(); $Probe.WaitForExit() }
        $Probe.Dispose()
    }
    & (Join-Path $App 'runtime/gum.exe') --version
    if ($LASTEXITCODE -ne 0) { throw 'Gum unavailable' }
    $Result = Invoke-Launcher
    if ($Result.Code -ne 1 -or $Result.ErrorText -notmatch 'An interactive terminal is required') {
        throw "Launcher не дошёл до проверки интерактивного терминала (код $($Result.Code))."
    }
    foreach ($Missing in @('vcruntime140.dll', 'ext/php_curl.dll')) {
        $MissingPath = Join-Path $Runtime $Missing
        Move-Item -LiteralPath $MissingPath -Destination ($MissingPath + '.removed')
        try {
            $Result = Invoke-Launcher
            if ($Result.Code -ne 1 -or $Result.ErrorText -notmatch 'App files are missing or corrupt') {
                throw "Launcher не отклонил поставку без $Missing (код $($Result.Code))."
            }
        } finally {
            Move-Item -LiteralPath ($MissingPath + '.removed') -Destination $MissingPath
        }
    }
    if ((Test-Path -LiteralPath (Join-Path $App 'config/bot.ini')) -or (Test-Path -LiteralPath (Join-Path $App 'var'))) { throw 'Private state' }
    Write-Host 'PASS: Windows x64, Unicode-путь, PHP/расширения/autoload/SQLite/CA/Gum и реальный дочерний bot.php.'
    Write-Host 'PASS: CRT и зависимости PHP загружены из поставки, отсутствие CRT или расширения отклоняется.'
    Write-Host 'NOT VERIFIED: интерактивное меню, Ctrl+C и чистая Windows 10/11 VM без системного CRT.'
} finally {
    # RU: Удаляется только созданный этим тестом уникальный временный каталог.
    # EN: Remove only the unique temporary directory created by this test.
    Remove-Item -LiteralPath $Directory -Recurse -Force
}
