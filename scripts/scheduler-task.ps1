param(
    [ValidateSet('Plan', 'Install', 'Status')][string]$Action = 'Plan',
    [string]$Php = ''
)
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Split-Path -Parent $PSScriptRoot)).Path
if (-not $Php) { $Php = Join-Path $projectRoot '.runtime\php83\php.exe' }
$Php = (Resolve-Path -LiteralPath $Php).Path
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'artisan'))) { throw 'Invalid BShop project.' }
$taskPhp = Join-Path (Split-Path -Parent $Php) 'php-win.exe'
if (-not (Test-Path -LiteralPath $taskPhp)) { throw 'php-win.exe is required beside PHP to run the Windows task without a visible console.' }
$taskHash = [System.Security.Cryptography.SHA256]::Create()
try { $suffix = ([BitConverter]::ToString($taskHash.ComputeHash([Text.Encoding]::UTF8.GetBytes($projectRoot.ToLowerInvariant())))).Replace('-', '').Substring(0, 12) }
finally { $taskHash.Dispose() }
$taskName = "BShop-Scheduler-$suffix"
if ($Action -eq 'Status') {
    Get-ScheduledTask -TaskName $taskName -ErrorAction Stop | Select-Object TaskName, State, Principal, Actions
    Get-ScheduledTaskInfo -TaskName $taskName | Select-Object LastRunTime, LastTaskResult, NextRunTime
    & $Php (Join-Path $projectRoot 'artisan') bshop:scheduler
    exit $LASTEXITCODE
}
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$escape = { param([string]$value) [System.Security.SecurityElement]::Escape($value) }
$start = (Get-Date).AddMinutes(1).ToString('yyyy-MM-ddTHH:mm:ss')
$xml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>BShop scheduler: local status, expiry and inventory review.</Description></RegistrationInfo>
  <Triggers><TimeTrigger><Repetition><Interval>PT1M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition><StartBoundary>$start</StartBoundary><Enabled>true</Enabled></TimeTrigger></Triggers>
  <Principals><Principal id="BShopUser"><UserId>$identity</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>true</StartWhenAvailable><ExecutionTimeLimit>PT0S</ExecutionTimeLimit><Enabled>true</Enabled></Settings>
  <Actions Context="BShopUser"><Exec><Command>$(& $escape $taskPhp)</Command><Arguments>$(& $escape ('"' + (Join-Path $projectRoot 'artisan') + '" bshop:scheduler --run'))</Arguments><WorkingDirectory>$(& $escape $projectRoot)</WorkingDirectory></Exec></Actions>
</Task>
"@
# Default produces a reviewable plan only; installing is an explicit action.
if ($Action -eq 'Plan') {
    $outputRoot = Join-Path $projectRoot 'storage\app\private\operations'
    $null = New-Item -ItemType Directory -Path $outputRoot -Force
    $output = Join-Path $outputRoot 'scheduler-task.xml'
    $xml | Set-Content -LiteralPath $output -Encoding Unicode
    [pscustomobject]@{ TaskName = $taskName; Plan = $output; Interval = '1 minute'; Account = $identity; RequiresLoggedInUser = $true; Installed = $false }
    return
}
if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { throw 'Task already exists. Inspect it with -Action Status; it was not overwritten.' }
Register-ScheduledTask -TaskName $taskName -Xml $xml | Select-Object TaskName, State
Write-Host 'Installed for the current logged-in user. Verify execution and bshop:scheduler status before considering it operational.'
