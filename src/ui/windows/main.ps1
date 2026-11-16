$ErrorActionPreference = 'Stop'
$Utf8 = New-Object System.Text.UTF8Encoding($false)
[Console]::InputEncoding = $Utf8
[Console]::OutputEncoding = $Utf8
$OutputEncoding = $Utf8
$Root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../..'))
Set-Location -LiteralPath $Root
if ([Console]::IsInputRedirected -or [Console]::IsOutputRedirected -or [Console]::IsErrorRedirected) {
    [Console]::Error.WriteLine('Нужен интерактивный терминал / An interactive terminal is required.')
    exit 1
}
$script:Language = 0
$Texts = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'messages.json') -Raw -Encoding UTF8 | ConvertFrom-Json
$Gum = Join-Path $Root 'runtime/gum.exe'
$Php = Join-Path $Root 'runtime/php/php.exe'
$Inspector = Join-Path $PSScriptRoot 'inspect.php'
$script:PhpArgs = @('-n', '-d', ('extension_dir=' + (Join-Path $Root 'runtime/php/ext')),
    '-d', 'extension=curl', '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite',
    '-d', ('curl.cainfo=' + (Join-Path $Root 'runtime/cacert.pem')),
    '-d', 'display_errors=0', '-d', 'log_errors=0')
foreach ($Key in @('PHPRC', 'PHP_INI_SCAN_DIR', 'PHP_BINARY', 'OPENSSL_CONF', 'OPENSSL_MODULES')) {
    [Environment]::SetEnvironmentVariable($Key, $null, 'Process')
}
$env:PATH = (Join-Path $Root 'runtime/php') + ';' + (Join-Path $env:SystemRoot 'System32')
function Text([string]$Key) { return $Texts.$Key[$script:Language] }
function AppPhp([string[]]$Arguments) {
    $ErrorActionPreference = 'Continue'
    & $Php -d display_startup_errors=0 @script:PhpArgs @Arguments 2>$null
    $script:AppStatus = $LASTEXITCODE
}
function InvokeBot([string]$Action) {
    $script:DiagnosticCategory = 'runtime'
    $script:AppStatus = 70
    $Diagnostic = AppPhp @($Inspector, 'diagnostic-create')
    if ($script:AppStatus -ne 0 -or -not $Diagnostic) { $script:AppStatus = 70; return }
    try {
        AppPhp (@($Inspector, 'execute', $Action, [string]$Diagnostic, $Php) + $script:PhpArgs)
        $Code = $script:AppStatus
        $Result = AppPhp @($Inspector, 'diagnostic-read', [string]$Diagnostic)
        if ($script:AppStatus -eq 0) {
            $Parts = $Result -split "`t"
            $script:DiagnosticCategory = $Parts[0]
            $Code = [int]$Parts[1]
        }
        $script:AppStatus = $Code
    } finally {
        $Code = $script:AppStatus
        AppPhp @($Inspector, 'diagnostic-delete', [string]$Diagnostic) | Out-Null
        $script:AppStatus = $Code
    }
}
function DiagnosticText {
    return "`n" + (Text ('diagnostic_' + $script:DiagnosticCategory)) + "`n" + (Text 'exit_code') + ': ' + $script:AppStatus
}
function Screen([string]$Title, [string]$Body) {
    [Console]::Clear()
    & $Gum style --border rounded --padding '0 2' -- (Text $Title) | Out-Host
    $Body | & $Gum format --type markdown | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'Render' }
}
function Choose([string[]]$Keys) {
    $Labels = @($Keys | ForEach-Object { Text $_ })
    $Value = & $Gum choose --header (Text 'next') --height $Labels.Count -- @Labels
    switch ($LASTEXITCODE) {
        1 { return 'back' }
        130 { exit 130 }
        0 {
            for ($Index = 0; $Index -lt $Labels.Count; $Index++) {
                if ($Value -ceq $Labels[$Index]) { return $Keys[$Index] }
            }
            throw 'Selection'
        }
        default { throw 'Menu' }
    }
}
function Guard {
    AppPhp @($Inspector, 'runtime', 'bundle') | Out-Null
    if ($script:AppStatus -ne 0) { return 'runtime_invalid' }
    $Mode = AppPhp @($Inspector, 'mode')
    if ($script:AppStatus -ne 0) { return 'config_invalid' }
    if ($Mode -ceq 'webhook') { return 'webhook' }
    if ($Mode -cne 'polling') { return 'config_invalid' }
    return ''
}
function HelpFlow {
    while ($true) {
        Screen 'help' (Text 'help_intro')
        $Action = Choose @('quick', 'processing', 'privacy', 'model', 'vds', 'main', 'exit')
        switch ($Action) {
            'back' { return }
            'main' { return }
            'exit' { exit 0 }
            default {
                Screen 'help' (Text ('help_' + $Action))
                switch (Choose @('back', 'main', 'exit')) { 'main' { return }; 'exit' { exit 0 } }
            }
        }
    }
}
function ReadinessFlow {
    while ($true) {
        $Rows = @(AppPhp @($Inspector, 'check', 'bundle'))
        if ($script:AppStatus -ne 0) { $Rows = @("fail`tphp`truntime") }
        $Summary = @()
        $Ready = $true
        foreach ($Group in @('runtime', 'config', 'profile', 'storage')) {
            $GroupRows = @($Rows | ForEach-Object { ,($_ -split "`t") } | Where-Object { $_[2] -ceq $Group })
            $Ok = $GroupRows.Count -gt 0
            foreach ($Row in $GroupRows) { if ($Row[0] -cne 'ok') { $Ok = $false } }
            $Status = 'ok'
            if (-not $Ok) { $Status = 'fail'; $Ready = $false }
            if ($GroupRows.Count -eq 0) { $Status = 'skip' }
            $Summary += '- ' + (Text ('group_' + $Group)) + ': ' + (Text $Status)
        }
        $Heading = 'ready'
        if (-not $Ready) { $Heading = 'not_ready' }
        Screen 'check' ("## " + (Text $Heading) + "`n" + ($Summary -join "`n") + "`n`n" + (Text 'checks_note'))
        switch (Choose @('details', 'repeat', 'main', 'exit')) {
            'back' { return }; 'main' { return }; 'exit' { exit 0 }
            'details' {
                :categories while ($true) {
                    Screen 'check' (Text 'details_intro')
                    $Action = Choose @('group_runtime', 'group_config', 'group_profile', 'group_storage', 'back', 'main', 'exit')
                    switch ($Action) {
                        'back' { break categories }; 'main' { return }; 'exit' { exit 0 }
                        default {
                            $Group = $Action.Substring(6)
                            $Lines = @()
                            foreach ($Line in $Rows) {
                                $Row = $Line -split "`t"
                                if ($Row[2] -ceq $Group) { $Lines += '- ' + (Text $Row[0]) + ': ' + (Text $Row[1]) }
                            }
                            Screen 'check' ($Lines -join "`n")
                            switch (Choose @('back', 'main', 'exit')) { 'main' { return }; 'exit' { exit 0 } }
                        }
                    }
                }
            }
        }
    }
}
function IdentifyFlow {
    $Body = 'identify_intro'
    $Ids = ''
    $IdList = @()
    $Page = 0
    $FailureText = ''
    while ($true) {
        $Ids = ($IdList | Select-Object -Skip ($Page * 5) -First 5) -join "`n"
        Screen 'identify' ((Text $Body) + "`n" + $Ids + $FailureText)
        $Keys = @('check_now', 'repeat', 'main', 'exit')
        if ($IdList.Count -gt 5) { $Keys = @('check_now', 'repeat', 'next_ids', 'prev_ids', 'main', 'exit') }
        $Action = Choose $Keys
        if ($Action -eq 'next_ids' -or $Action -eq 'prev_ids') {
            $Pages = [int][Math]::Ceiling($IdList.Count / 5.0)
            if ($Action -eq 'next_ids') { $Page = ($Page + 1) % $Pages }
            else { $Page = ($Page + $Pages - 1) % $Pages }
            continue
        }
        switch ($Action) {
            'back' { return }; 'main' { return }; 'exit' { exit 0 }
        }
        $Ids = ''; $IdList = @(); $Page = 0; $FailureText = ''
        $Failure = Guard
        if ($Failure) { $Body = $Failure; continue }
        $Raw = InvokeBot 'identify'
        if ($script:AppStatus -ne 0) { $Body = 'identify_error'; $FailureText = DiagnosticText; continue }
        try {
            $Result = ($Raw -join "`n") | ConvertFrom-Json
            if ($Result.status -notin @('found', 'empty') -or $Result.ids -isnot [array] -or $Result.ids.Count -gt 100) { throw 'Result' }
            foreach ($Id in $Result.ids) {
                if (($Id -isnot [int] -and $Id -isnot [long]) -or $Id -lt 1) { throw 'ID' }
            }
            if (($Result.status -ceq 'found') -ne ($Result.ids.Count -gt 0)) { throw 'Status' }
            $Body = 'identify_empty'
            if ($Result.status -ceq 'found') { $Body = 'identified'; $IdList = @($Result.ids) }
        } catch { $Body = 'identify_error'; $script:DiagnosticCategory = 'unknown'; $script:AppStatus = 65; $FailureText = DiagnosticText }
    }
}
function RunFlow {
    while ($true) {
        $FailureText = ''
        $Body = Guard
        if (-not $Body) {
            Screen 'run' (Text 'starting')
            InvokeBot 'run' | Out-Host
            if ($script:AppStatus -eq 130 -or $script:AppStatus -eq -1073741510) { exit 130 }
            $Body = 'run_done'
            if ($script:AppStatus -ne 0) { $Body = 'run_error'; $FailureText = DiagnosticText }
        }
        Screen 'run' ((Text $Body) + $FailureText)
        switch (Choose @('again', 'main', 'exit')) { 'back' { return }; 'main' { return }; 'exit' { exit 0 } }
    }
}
try {
    if (-not (Test-Path -LiteralPath $Php -PathType Leaf) -or -not (Test-Path -LiteralPath $Gum -PathType Leaf)) { throw 'Runtime' }
    while ($true) {
        Screen 'title' (Text 'welcome')
        switch (Choose @('run', 'check', 'identify', 'help', 'language', 'exit')) {
            'run' { RunFlow }
            'check' { ReadinessFlow }
            'identify' { IdentifyFlow }
            'help' { HelpFlow }
            'language' {
                Screen 'language' (Text 'language_note')
                switch (Choose @('ru', 'en', 'back')) { 'ru' { $script:Language = 0 }; 'en' { $script:Language = 1 } }
            }
            'exit' { Write-Host (Text 'bye'); exit 0 }
        }
    }
} catch {
    [Console]::Error.WriteLine('Не удалось открыть меню. Проверьте целостность архива / Check archive integrity.')
    exit 1
}
