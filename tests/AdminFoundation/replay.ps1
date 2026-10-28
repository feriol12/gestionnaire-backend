$ErrorActionPreference='Stop'
$checkoutRoot=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if ($checkoutRoot -notlike '*\development\FM-ADMIN-DEV-07') { throw 'Unexpected development checkout; review paths before adapting this runner.' }
Set-Location -LiteralPath $checkoutRoot
$phpExe='C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$options=@('-n','-d','extension_dir=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\ext','-d','extension=mbstring','-d','extension=openssl','-d','extension=fileinfo','-d','extension=pdo_sqlite', '-d',"sys_temp_dir=$checkoutRoot\storage\app\admin-harden-09\tmp")
function Invoke-FoundationPhp([string[]]$Arguments) {
    & $phpExe @options @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Command failed: $Arguments" }
}
Invoke-FoundationPhp @('tests/AdminFoundation/prepare.php')
$database=Join-Path $checkoutRoot 'storage/app/admin-harden-09/test.sqlite'
if (-not (Test-Path -LiteralPath $database)) { New-Item -ItemType File -Path $database | Out-Null }
try {
    Invoke-FoundationPhp @('tests/AdminFoundation/console.php','route:clear')
    Invoke-FoundationPhp @('tests/AdminFoundation/console.php','config:clear')
    Invoke-FoundationPhp @('tests/AdminFoundation/run.php','uncached')
    Invoke-FoundationPhp @('tests/AdminFoundation/console.php','config:cache')
    Invoke-FoundationPhp @('tests/AdminFoundation/console.php','route:cache')
    Invoke-FoundationPhp @('tests/AdminFoundation/run.php','cached')
} finally {
    Invoke-FoundationPhp @('tests/AdminFoundation/console.php','route:clear')
    Invoke-FoundationPhp @('tests/AdminFoundation/console.php','config:clear')
}
