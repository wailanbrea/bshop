param([int]$ServerProcessId = 33232)
$ErrorActionPreference = 'Continue'
if (-not ('BShopNetworkToken' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class BShopNetworkToken {
 [DllImport("kernel32.dll", SetLastError=true)] static extern IntPtr OpenProcess(uint a, bool i, int p);
 [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
 [DllImport("advapi32.dll", SetLastError=true)] static extern bool OpenProcessToken(IntPtr p, uint a, out IntPtr t);
 [DllImport("advapi32.dll", SetLastError=true)] static extern bool GetTokenInformation(IntPtr t, int k, out int v, int n, out int r);
 public static string Inspect(int id) {
  IntPtr p=OpenProcess(0x1000,false,id), t;
  if(p==IntPtr.Zero) return "PID "+id+": OpenProcess error "+Marshal.GetLastWin32Error();
  if(!OpenProcessToken(p,8,out t)){int e=Marshal.GetLastWin32Error();CloseHandle(p);return "PID "+id+": token error "+e;}
  int value,length; bool ok=GetTokenInformation(t,29,out value,4,out length);
  int error=Marshal.GetLastWin32Error();CloseHandle(t);CloseHandle(p);
  return "PID "+id+": "+(ok ? "AppContainer="+value : "token information error "+error);
 }
}
'@
}
Write-Output '--- AISLAMIENTO (0=normal, 1=aislado) ---'
[BShopNetworkToken]::Inspect($PID)
[BShopNetworkToken]::Inspect($ServerProcessId)
Write-Output '--- PROCESO Y PADRE ---'
$serverProcess = Get-CimInstance Win32_Process -Filter "ProcessId = $ServerProcessId"
$serverProcess | Select-Object ProcessId,ParentProcessId,ExecutablePath,CommandLine | Format-List
if ($serverProcess) {
    Get-CimInstance Win32_Process -Filter "ProcessId = $($serverProcess.ParentProcessId)" | Select-Object ProcessId,Name,ExecutablePath | Format-List
}
Write-Output '--- PUERTO TCP 8017 ---'
Get-NetTCPConnection -LocalPort 8017 -ErrorAction Continue | Select-Object LocalAddress,LocalPort,State,OwningProcess | Format-Table
Write-Output '--- HTTP DIRECTO (sin proxy) ---'
try {
    $request = [System.Net.HttpWebRequest]::Create('http://127.0.0.1:8017/up')
    $request.Proxy = $null
    $request.Timeout = 5000
    $response = $request.GetResponse()
    Write-Output "HTTP $([int]$response.StatusCode)"
    $response.Close()
} catch {
    $cause = $_.Exception
    while ($cause) {
        Write-Output ($cause.GetType().FullName + ': ' + $cause.Message)
        if ($cause -is [System.Net.Sockets.SocketException]) { Write-Output "SocketError=$($cause.SocketErrorCode) NativeError=$($cause.NativeErrorCode)" }
        $cause = $cause.InnerException
    }
}
Write-Output '--- BIND DE COMPROBACION (se cierra inmediatamente) ---'
$probe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback,8017)
try {
    $probe.Start()
    Write-Output 'El puerto 8017 se puede reservar desde esta ventana. PHP no lo mantiene ocupado en este contexto.'
} catch {
    $cause = $_.Exception
    while ($cause) {
        Write-Output ($cause.GetType().FullName + ': ' + $cause.Message)
        if ($cause -is [System.Net.Sockets.SocketException]) { Write-Output "SocketError=$($cause.SocketErrorCode) NativeError=$($cause.NativeErrorCode)" }
        $cause = $cause.InnerException
    }
} finally { $probe.Stop() }
