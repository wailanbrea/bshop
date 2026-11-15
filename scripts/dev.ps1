param([string]$Php = '', [ValidateRange(1024,65535)][int]$Port = 8017, [switch]$Open)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
if (!$Php) { $Php = "$root\.runtime\php83\php.exe" }
if (!(Test-Path -LiteralPath $Php)) { throw 'Specify a PHP 8.3+ binary with -Php.' }
if (!(Test-Path -LiteralPath "$root\vendor\autoload.php")) { throw 'Install Composer dependencies first.' }
$Php = (Resolve-Path -LiteralPath $Php).Path
$baseUrl = "http://127.0.0.1:$Port"
$pageUrl = "$baseUrl/app/apartados"
function Open-BShop {
    if (-not $Open) { return }
    $chrome = @("$env:ProgramFiles\Google\Chrome\Application\chrome.exe", "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe", "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe") | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    if ($chrome) { Start-Process -FilePath $chrome -ArgumentList $pageUrl }
    else { Write-Host "Abre esta direccion en Chrome: $pageUrl" }
}
function Test-BShopResponse {
    try {
        $response = Invoke-WebRequest -Uri $pageUrl -UseBasicParsing -TimeoutSec 2
        return $response.StatusCode -eq 200 -and $response.Content.Contains('id="app"') -and $response.Content.Contains('/build/assets/')
    } catch { return $false }
}

if (Test-BShopResponse) {
    Write-Host "BShop confirmado: HTTP 200 en $pageUrl"
    Open-BShop
    return
}
$listener = Get-NetTCPConnection -LocalPort $Port -ErrorAction SilentlyContinue | Where-Object { $_.OwningProcess -ne 0 }
if ($listener) { throw "El puerto $Port esta ocupado (PID $($listener.OwningProcess -join ', ')), pero BShop no responde. Usa -Port con otro puerto libre." }

$public = Join-Path $root 'public'
$router = Join-Path $root 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
if (!(Test-Path -LiteralPath $router)) { throw "No se encontro el router Laravel: $router" }
if (!(Test-Path -LiteralPath "$public\build\manifest.json")) { throw 'Falta la compilacion. Ejecuta npm run build.' }
$logRoot = Join-Path $root 'storage\logs'
$null = New-Item -ItemType Directory -Path $logRoot -Force
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
$stdout = Join-Path $logRoot "dev-server-$stamp.stdout.log"
$stderr = Join-Path $logRoot "dev-server-$stamp.stderr.log"
# Start PHP directly, without artisan's intermediate server process or environment filtering.
$previousAppUrl = $env:APP_URL
try {
    $env:APP_URL = $baseUrl
    $server = Start-Process -FilePath $Php -WorkingDirectory $public -ArgumentList @('-S', "127.0.0.1:$Port", ('"' + $router + '"')) -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
} finally { $env:APP_URL = $previousAppUrl }
Write-Host "Iniciando PHP (PID $($server.Id)). Registro: $stderr"
for ($attempt = 0; $attempt -lt 20; $attempt++) {
    $server.Refresh()
    if ($server.HasExited) {
        Get-Content -LiteralPath $stderr -ErrorAction SilentlyContinue | Write-Host
        throw "PHP termino antes de responder (codigo $($server.ExitCode)). Registro: $stderr"
    }
    if (Test-BShopResponse) {
        Write-Host "BShop confirmado: HTTP 200 en $pageUrl"
        Write-Host "El servidor permanece en segundo plano. PID: $($server.Id)"
        Open-BShop
        return
    }
    Start-Sleep -Milliseconds 250
}
Get-Content -LiteralPath $stderr -ErrorAction SilentlyContinue | Write-Host
throw "No se pudo verificar HTTP en el puerto $Port. PID PHP: $($server.Id). Registros: $stderr y $stdout"
