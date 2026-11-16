param([Parameter(Mandatory=$true)][string]$Archive)
$ErrorActionPreference = 'Stop'
if (-not [Environment]::Is64BitProcess -or [Environment]::OSVersion.Platform -ne 'Win32NT') {
    throw 'NOT VERIFIED: нужен native Windows x64'
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Expected = ((Get-Content -LiteralPath ($Archive + '.sha256') -Raw).Trim() -split '\s+')[0]
if ((Get-FileHash -LiteralPath $Archive -Algorithm SHA256).Hash.ToLowerInvariant() -cne $Expected) { throw 'Archive hash' }
$Directory = Join-Path ([IO.Path]::GetTempPath()) ('tvm-smoke-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($Directory) | Out-Null
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
    $App = Join-Path $Directory 'Приложение with spaces'
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
    $Crt = Join-Path $Runtime 'vcruntime140.dll'
    $Signature = Get-AuthenticodeSignature -LiteralPath $Crt
    if ($Signature.Status -ne 'Valid' -or $Signature.SignerCertificate.Subject -cne $CrtPin.dll.subject -or
        $Signature.SignerCertificate.Thumbprint -cne $CrtPin.dll.thumbprint -or
        (Get-FileHash -LiteralPath $Crt -Algorithm SHA256).Hash.ToLowerInvariant() -cne $CrtPin.dll.sha256 -or
        (Get-Item -LiteralPath $Crt).VersionInfo.FileVersion -cne $CrtPin.dll.version) { throw 'CRT signature/version/hash' }
    $Php = Join-Path $Runtime 'php.exe'
    $Bytes = [IO.File]::ReadAllBytes($Php)
    $PeOffset = [BitConverter]::ToInt32($Bytes, 60)
    if ([BitConverter]::ToUInt16($Bytes, $PeOffset + 4) -ne 0x8664) { throw 'Wrong PHP architecture' }
    foreach ($Name in @('vacancy-monitor.exe', 'runtime/gum.exe')) {
        $Bytes = [IO.File]::ReadAllBytes((Join-Path $App $Name))
        $PeOffset = [BitConverter]::ToInt32($Bytes, 60)
        if ([BitConverter]::ToUInt16($Bytes, $PeOffset + 4) -ne 0x8664) { throw 'Wrong executable architecture' }
    }
    $env:PATH = Join-Path $env:SystemRoot 'System32'
    $env:PHPRC = Join-Path $Directory 'nonexistent'
    $env:PHP_INI_SCAN_DIR = $env:PHPRC
    $Arguments = @('-n', '-d', ('extension_dir=' + (Join-Path $Runtime 'ext')),
        '-d', 'extension=curl', '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite')
    & $Php @Arguments (Join-Path $App 'src/ui/windows/inspect.php') runtime bundle
    if ($LASTEXITCODE -ne 0) { throw 'PHP/extensions unavailable; clean-system standalone is not verified' }
    $Probe = Start-Process -FilePath $Php -ArgumentList @('-n', '-r', 'sleep(30);') -PassThru -NoNewWindow
    try {
        $LoadedCrt = $null
        for ($Attempt = 0; $Attempt -lt 20 -and -not $LoadedCrt; $Attempt++) {
            Start-Sleep -Milliseconds 100
            $Probe.Refresh()
            $LoadedCrt = @($Probe.Modules | Where-Object { $_.ModuleName -ieq 'vcruntime140.dll' })
        }
        if ($LoadedCrt.Count -ne 1 -or $LoadedCrt[0].FileName -ine $Crt) { throw 'CRT loaded outside app directory' }
    } finally {
        if (-not $Probe.HasExited) { $Probe.Kill(); $Probe.WaitForExit() }
        $Probe.Dispose()
    }
    $Code = 'require $argv[1] . "/vendor/autoload.php"; $p = new PDO("sqlite::memory:"); $p->exec("CREATE TABLE smoke (id INTEGER)"); echo PHP_VERSION, PHP_EOL;'
    # RU: Передаём программу через stdin, избегая потери кавычек в Windows PowerShell 5.1.
    # EN: Send the program through stdin to avoid quote loss in Windows PowerShell 5.1.
    $Check = Join-Path $Directory 'autoload.php'
    [IO.File]::WriteAllText($Check, ("<?php " + $Code), (New-Object Text.UTF8Encoding($false)))
    & $Php @Arguments $Check $App
    if ($LASTEXITCODE -ne 0) { throw 'Autoload/SQLite' }
    & (Join-Path $App 'runtime/gum.exe') --version
    if ($LASTEXITCODE -ne 0) { throw 'Gum unavailable' }
    $Process = New-Object System.Diagnostics.Process
    $Process.StartInfo.FileName = Join-Path $App 'vacancy-monitor.exe'
    $Process.StartInfo.UseShellExecute = $false
    $Process.StartInfo.RedirectStandardInput = $true
    $Process.StartInfo.RedirectStandardOutput = $true
    $Process.StartInfo.RedirectStandardError = $true
    $Process.StartInfo.WorkingDirectory = $Directory
    $Process.Start() | Out-Null
    $Process.StandardInput.Close()
    if (-not $Process.WaitForExit(15000)) { $Process.Kill(); throw 'Launcher timeout' }
    if ($Process.ExitCode -ne 1) { throw ('Launcher non-TTY exit: ' + $Process.ExitCode) }
    $Process.Dispose()
    foreach ($Missing in @('vcruntime140.dll', 'ext/php_curl.dll')) {
        $MissingPath = Join-Path $Runtime $Missing
        Move-Item -LiteralPath $MissingPath -Destination ($MissingPath + '.removed')
        $Process = New-Object System.Diagnostics.Process
        $Process.StartInfo.FileName = Join-Path $App 'vacancy-monitor.exe'
        $Process.StartInfo.UseShellExecute = $false
        $Process.StartInfo.RedirectStandardError = $true
        $Process.Start() | Out-Null
        $ErrorText = $Process.StandardError.ReadToEnd()
        $Process.WaitForExit()
        if ($Process.ExitCode -ne 1 -or $ErrorText -notmatch 'App files are missing or corrupt') { throw 'Runtime fallback' }
        $Process.Dispose()
        Move-Item -LiteralPath ($MissingPath + '.removed') -Destination $MissingPath
    }
    if ((Test-Path -LiteralPath (Join-Path $App 'config/bot.ini')) -or (Test-Path -LiteralPath (Join-Path $App 'var'))) { throw 'Private state' }
    Write-Host 'PASS: native Windows x64 PHP/extensions/autoload/SQLite/Gum; system PHP absent from PATH.'
    Write-Host 'PASS: pinned Microsoft CRT loaded from app directory; missing CRT/extension rejected.'
    Write-Host 'NOT VERIFIED: clean Windows 10/11 VM without host CRT and Windows Ctrl+C. Owner previously passed Windows 11 RU/EN/Help/readiness/identify/live smoke.'
} finally {
    # RU: Удаляется только созданный этим тестом уникальный временный каталог.
    # EN: Remove only the unique temporary directory created by this test.
    Remove-Item -LiteralPath $Directory -Recurse -Force
}
