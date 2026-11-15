$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$binary = "$root\.runtime\mysql\mysql-8.4.13-winx64\bin\mysqld.exe"
$config = "$root\.runtime\mysql-test.ini"
if (!(Test-Path -LiteralPath $binary) -or !(Test-Path -LiteralPath "$root\.runtime\mysql-data\auto.cnf") -or !(Test-Path -LiteralPath $config)) {
    throw 'The dedicated BShop MySQL test instance must be initialized first. No files were changed.'
}
'BShop testing only: 127.0.0.1:33078; current schema bshop_testing_single.'
& $binary "--defaults-file=$config" --console
