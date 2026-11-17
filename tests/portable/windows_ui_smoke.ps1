param([Parameter(Mandatory=$true)][string]$PhpBinary)
$ErrorActionPreference = 'Stop'
$RootSource = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$Directory = Join-Path ([IO.Path]::GetTempPath()) ('tvm-ui-' + [Guid]::NewGuid().ToString('N'))
$Root = Join-Path $Directory 'Приложение with spaces'
$Utf8 = New-Object Text.UTF8Encoding($false)
function Verify([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}
try {
    [IO.Directory]::CreateDirectory((Join-Path $Root 'src/ui/windows')) | Out-Null
    [IO.Directory]::CreateDirectory((Join-Path $Root 'bin')) | Out-Null
    [IO.Directory]::CreateDirectory((Join-Path $Root 'config')) | Out-Null
    foreach ($Name in @('inspect.php', 'messages.json')) {
        Copy-Item -LiteralPath (Join-Path $RootSource ('src/ui/windows/' + $Name)) -Destination (Join-Path $Root ('src/ui/windows/' + $Name))
    }
    [IO.File]::WriteAllText((Join-Path $Root 'config/bot.ini'), 'TELEGRAM_BOT_TOKEN="123:synthetic-token"', $Utf8)
    $Fixture = @'
<?php
$case = getenv('FIXTURE_CASE') ?: 'unknown';
if (($argv[1] ?? '') === 'identify') {
    echo '{"status":"error","ids":[]}';
    exit(7);
}
echo "LIVE synthetic stdout\n";
flush();
if ($case === 'stream') {
    fflush(STDOUT);
    usleep(750000);
    file_put_contents(getenv('FINISHED'), 'done');
}
$messages = [
    'config' => 'Ошибка: Укажите AI_MODEL.',
    'lock' => 'Ошибка: Бот уже запущен либо недоступна блокировка var/bot.lock.',
    '409' => 'Ошибка Bot API (HTTP 409, API 409).',
    'tls' => 'Сетевая ошибка Bot API (cURL 60).',
    'network' => 'Сетевая ошибка Bot API (cURL 6).',
    'auth' => 'Ошибка Bot API (HTTP 401, API 401).',
    'storage' => 'SQLSTATE[HY000]',
];
fwrite(STDERR, str_repeat("synthetic-secret-marker\n", 20000) . ($messages[$case] ?? ''));
exit($case === 'clean' ? 0 : 7);
'@
    [IO.File]::WriteAllText((Join-Path $Root 'bin/bot.php'), $Fixture, $Utf8)
    $Errors = $null
    $Tokens = $null
    $Ast = [Management.Automation.Language.Parser]::ParseFile((Join-Path $RootSource 'src/ui/windows/main.ps1'), [ref]$Tokens, [ref]$Errors)
    Verify ($Errors.Count -eq 0) 'PowerShell syntax'
    $Names = @('Text', 'AppPhp', 'InvokeBot', 'DiagnosticText', 'Guard', 'RunFlow', 'IdentifyFlow')
    foreach ($Function in $Ast.FindAll({ param($Node) $Node -is [Management.Automation.Language.FunctionDefinitionAst] }, $true)) {
        if ($Names -contains $Function.Name) { . ([scriptblock]::Create($Function.Extent.Text)) }
    }
    $Php = $PhpBinary
    $DiagnosticDirectory = Join-Path $Directory 'diagnostics'
    [IO.Directory]::CreateDirectory($DiagnosticDirectory) | Out-Null
    $script:PhpArgs = @('-d', 'display_errors=0', '-d', ('sys_temp_dir=' + $DiagnosticDirectory))
    $Inspector = Join-Path $Root 'src/ui/windows/inspect.php'
    $Texts = Get-Content -LiteralPath (Join-Path $Root 'src/ui/windows/messages.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $script:Language = 0
    function Screen([string]$Title, [string]$Body) {
        $script:Screens.Add($Body)
        $script:Phases.Add($Title + ':' + $script:DiagnosticCategory + ':' + $script:AppStatus)
    }
    function Choose([string[]]$Keys) {
        Verify ($script:Queue.Count -gt 0) 'Navigation did not return'
        $Action = $script:Queue.Dequeue()
        Verify ($Keys -contains $Action) 'Unexpected navigation'
        return $Action
    }
    foreach ($Case in @('config', 'lock', '409', 'tls', 'network', 'auth', 'storage', 'unknown', 'clean')) {
        $env:FIXTURE_CASE = $Case
        $script:Screens = New-Object 'Collections.Generic.List[string]'
        $script:Phases = New-Object 'Collections.Generic.List[string]'
        $script:Queue = New-Object 'Collections.Generic.Queue[string]'
        $script:Queue.Enqueue('again')
        $script:Queue.Enqueue('main')
        RunFlow | Out-Null
        $OutputText = $script:Screens -join "`n"
        Verify (-not $OutputText.Contains('synthetic-secret-marker')) 'Secret was displayed'
        if ($Case -ne 'clean') {
            Verify ($OutputText -match 'Код завершения: 7(\r?\n|$)') 'Exit code missing'
            $Category = switch ($Case) { 'lock' { 'conflict' }; '409' { 'conflict' }; default { $Case } }
            Verify ($OutputText.Contains((Text ('diagnostic_' + $Category)))) ('Safe category missing: case=' + $Case + ', phases=' + ($script:Phases -join ','))
        }
        Verify ($script:Queue.Count -eq 0) 'Again/Main navigation'
    }
    $script:Language = 1
    $script:Screens = New-Object 'Collections.Generic.List[string]'
    $script:Queue = New-Object 'Collections.Generic.Queue[string]'
    $script:Queue.Enqueue('check_now')
    $script:Queue.Enqueue('main')
    IdentifyFlow
    Verify (($script:Screens -join "`n").Contains('Exit code: 7')) 'Identify diagnostic'
    $env:FIXTURE_CASE = 'stream'
    $env:FINISHED = Join-Path $Directory 'finished'
    $script:ObservedLive = $false
    InvokeBot 'run' | ForEach-Object {
        if ($_ -match 'LIVE') { $script:ObservedLive = -not (Test-Path -LiteralPath $env:FINISHED) }
    }
    Verify $script:ObservedLive 'Foreground stdout was buffered'
    Verify ($script:AppStatus -eq 7) 'Foreground exit code'
    $Diagnostics = @(Get-ChildItem -LiteralPath $DiagnosticDirectory -File)
    Verify ($Diagnostics.Count -eq 0) 'Temporary diagnostic was not cleaned'
    Write-Host 'PASS: native PowerShell function scenarios, bounded diagnostics, secrecy, Again/Main, identify, RU/EN and live stdout.'
    Write-Host 'NOT VERIFIED: real Windows console Gum navigation, Ctrl+C and clean Windows CRT.'
} finally {
    $env:FIXTURE_CASE = $null
    $env:FINISHED = $null
    if (Test-Path -LiteralPath $Directory) { Remove-Item -LiteralPath $Directory -Recurse -Force }
}
