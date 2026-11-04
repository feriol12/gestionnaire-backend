$ErrorActionPreference='Stop'
$taskRoot=Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if ($taskRoot -notlike '*\development\FM-ADMIN-MFA-12') { throw 'Unexpected checkout' }
Set-Location -LiteralPath $taskRoot
$php='C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe'
$options=@('-n','-d','extension_dir=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\ext','-d','extension=mbstring','-d','extension=openssl','-d','extension=fileinfo','-d','extension=pdo_sqlite','-d',"sys_temp_dir=$taskRoot\storage\app\admin-mfa-12\runtime\tmp")
function Invoke-MfaPhp([string[]]$Arguments) {
    & $php @options @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Local MFA test failed: $Arguments" }
}
Invoke-MfaPhp @('tests/AdminMfa/prepare.php')
if (!(Test-Path storage/app/admin-mfa-12/runtime/test.sqlite)) { New-Item -ItemType File storage/app/admin-mfa-12/runtime/test.sqlite | Out-Null }
try {
    Invoke-MfaPhp @('tests/AdminMfa/console.php','config:clear')
    Invoke-MfaPhp @('tests/AdminMfa/console.php','route:clear')
    Invoke-MfaPhp @('tests/AdminMfa/run.php','uncached')
    Invoke-MfaPhp @('tests/AdminMfa/console.php','config:cache')
    Invoke-MfaPhp @('tests/AdminMfa/console.php','route:cache')
    Invoke-MfaPhp @('tests/AdminMfa/run.php','cached')
} finally {
    Invoke-MfaPhp @('tests/AdminMfa/console.php','config:clear')
    Invoke-MfaPhp @('tests/AdminMfa/console.php','route:clear')
}
