$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$php = Join-Path $root '.runtime\php83\php.exe'
$probeRoot = Join-Path $root ('.runtime\http-probe-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
$null = New-Item -ItemType Directory -Path $probeRoot
[IO.File]::WriteAllText((Join-Path $probeRoot 'index.php'), '<?php echo "BSHOP_HTTP_OK";')

function New-ProbeListener {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start()
    return $listener
}
function New-LocalRequest([int]$Port) {
    $request = [Net.HttpWebRequest]::Create("http://127.0.0.1:$Port/")
    $request.Proxy = $null
    $request.Timeout = 1500
    $request.ReadWriteTimeout = 1500
    $request.KeepAlive = $false
    return $request
}

$netOk = $false
$listener = $null; $client = $null; $response = $null
try {
    $listener = New-ProbeListener
    $port = $listener.LocalEndpoint.Port
    $request = New-LocalRequest $port
    $pending = $request.BeginGetResponse($null,$null)
    $accept = $listener.AcceptTcpClientAsync()
    if (-not $accept.Wait(3000)) { throw 'La conexion TCP local de .NET no llego al servidor de prueba.' }
    $client = $accept.Result
    $bytes = [Text.Encoding]::ASCII.GetBytes("HTTP/1.1 200 OK`r`nContent-Length: 13`r`nConnection: close`r`n`r`nBSHOP_HTTP_OK")
    $client.GetStream().Write($bytes,0,$bytes.Length)
    $client.Close(); $client = $null
    if (-not $pending.AsyncWaitHandle.WaitOne(3000)) { throw 'La respuesta HTTP local de .NET no llego al cliente.' }
    $response = $request.EndGetResponse($pending)
    $netOk = [int]$response.StatusCode -eq 200
    Write-Host ".NET HTTP: $netOk (puerto $port)"
} catch { Write-Host ".NET HTTP: FALLO - $($_.Exception.Message)" }
finally {
    if ($request) { $request.Abort() }
    if ($response) { $response.Close() }
    if ($client) { $client.Close() }
    if ($listener) { $listener.Stop() }
}

function Test-PhpHttp([bool]$Clean) {
    $reservation = New-ProbeListener
    $port = $reservation.LocalEndpoint.Port
    $reservation.Stop()
    $name = if ($Clean) { 'sin-ini' } else { 'con-ini' }
    $stdout = Join-Path $probeRoot "$name.stdout.log"
    $stderr = Join-Path $probeRoot "$name.stderr.log"
    $arguments = @()
    if ($Clean) { $arguments += '-n' }
    $arguments += @('-S', "127.0.0.1:$port", '-t', ('"' + $probeRoot + '"'))
    $process = $null; $ok = $false; $lastError = ''
    try {
        $process = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $probeRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
        for ($attempt = 0; $attempt -lt 4; $attempt++) {
            Start-Sleep -Milliseconds 200
            $process.Refresh()
            if ($process.HasExited) { break }
            $reply = $null
            try {
                $reply = (New-LocalRequest $port).GetResponse()
                $reader = [IO.StreamReader]::new($reply.GetResponseStream())
                $ok = $reader.ReadToEnd().Trim() -eq 'BSHOP_HTTP_OK'
                $reader.Close()
                if ($ok) { break }
            } catch { $lastError = $_.Exception.Message }
            finally { if ($reply) { $reply.Close() } }
        }
        Write-Host "PHP $name HTTP: $ok (puerto $port, PID $($process.Id)) $lastError"
        if (-not $ok) { Get-Content -LiteralPath $stderr -ErrorAction SilentlyContinue | Write-Host }
    } finally {
        # Only stop the temporary process created by this probe.
        if ($process) { $process.Refresh(); if (-not $process.HasExited) { $process.Kill(); $process.WaitForExit() }; $process.Dispose() }
    }
    return $ok
}
$cleanOk = Test-PhpHttp $true
$configuredOk = Test-PhpHttp $false
Write-Host "RESULTADO: NET=$netOk PHP_SIN_INI=$cleanOk PHP_CON_INI=$configuredOk"
Write-Host "Registros: $probeRoot"
