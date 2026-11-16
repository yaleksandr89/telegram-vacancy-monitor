param(
    [Parameter(Mandatory=$true)][string]$BuildPhp,
    [Parameter(Mandatory=$true)][string]$Cache,
    [Parameter(Mandatory=$true)][string]$OutputDir,
    [Parameter(Mandatory=$true)][string]$WindowsCrtInstaller,
    [Parameter(Mandatory=$true)][string]$WindowsCrtLicense,
    [string]$Composer = 'composer.phar'
)
$ErrorActionPreference = 'Stop'
& python (Join-Path $PSScriptRoot '../common/package.py') --target windows-x64 --build-php $BuildPhp --cache $Cache --output-dir $OutputDir --composer $Composer --windows-crt-installer $WindowsCrtInstaller --windows-crt-license $WindowsCrtLicense
exit $LASTEXITCODE
