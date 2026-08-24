<#
    Stops the supervised dev stack started by scripts/dev-server.ps1.

    The stop file is what tells the supervisor this was deliberate, so it exits
    instead of restarting the stack five seconds later.

    Processes are matched on command line and on port ownership, never on image
    name alone: the stack runs as plain php.exe / node.exe and so does VS Code's
    extension host (Intelephense), which must not be killed here.

    Port ownership is the part that matters. `artisan serve` spawns a
    `php -S 127.0.0.1:8000 ...` grandchild whose command line matches none of the
    obvious patterns, so a pattern-only sweep leaves it holding :8000 -- still
    answering requests while everything behind it is dead.
#>

$root = Split-Path -Parent $PSScriptRoot
$stop = Join-Path $root 'storage\framework\dev-server.stop'
$task = 'GiftCoves Dev Server'

New-Item -ItemType File -Force -Path $stop | Out-Null
Write-Host "stop file written: $stop"

$targets = New-Object System.Collections.Generic.HashSet[int]

Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='node.exe'" -ErrorAction SilentlyContinue |
    Where-Object {
        $cl = $_.CommandLine
        if (-not $cl) { return $false }
        if ($cl -like "*$root*") { return $true }
        return ($cl -match 'concurrently|ssr\.js|artisan serve|queue:listen|queue:work')
    } |
    ForEach-Object { [void]$targets.Add([int]$_.ProcessId) }

foreach ($port in @(8000, 5173, 13714)) {
    Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue |
        ForEach-Object {
            $owner = Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue
            if ($owner -and @('php', 'node') -contains $owner.ProcessName) {
                [void]$targets.Add([int]$owner.Id)
            }
        }
}

Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -match 'dev-server\.ps1' } |
    ForEach-Object { [void]$targets.Add([int]$_.ProcessId) }

foreach ($id in $targets) {
    Stop-Process -Id $id -Force -Confirm:$false -ErrorAction SilentlyContinue
}

Write-Host "stopped $($targets.Count) process(es)"

if (Get-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
    Write-Host "scheduled task '$task' ended (it starts again at next logon; Disable-ScheduledTask stops that)"
}

Start-Sleep -Seconds 2
$still = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue |
           Where-Object { $_.LocalPort -in 8000, 5173, 13714 })
if ($still.Count -gt 0) {
    Write-Warning "still bound: $(($still | Select-Object -ExpandProperty LocalPort -Unique) -join ', ')"
} else {
    Write-Host "all dev ports released"
}
