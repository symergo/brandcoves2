<#
    Supervises the local dev stack (composer dev = serve + queue + vite + ssr).

    Why this exists rather than just running `composer dev`:
    the stack used to live in a bare console window, so a stray Ctrl+C, a closed
    window, or a reboot silently took localhost down and nothing brought it back.
    Three outages in one afternoon on 2026-08-16 came from exactly that.

    Two things here are less obvious than they look:

    1. composer's output is redirected to a FILE, never piped through a
       PowerShell pipeline. `artisan serve` spawns a `php -S` grandchild that
       inherits the stdout handle, so a pipeline stays open long after composer
       itself has exited -- the supervisor then blocks forever and never
       restarts. Waiting on the process handle avoids that entirely.

    2. Orphans are killed before each restart. `concurrently --kill-others` does
       not reliably reap that `php -S` grandchild, and a surviving one holds
       port 8000, so the replacement `artisan serve` cannot bind. Worse, the
       orphan keeps answering requests, so the site looks healthy while vite,
       ssr and the queue are all dead.

    Health is judged on all three ports, not just 8000, for that same reason.

    Docker is started here instead of relying on Docker Desktop's own autostart,
    because that setting is off (AutoStart=false in settings-store.json) and
    postgres must be listening before `artisan serve` boots.

    Registered as the "GiftCoves Dev Server" scheduled task, triggered at logon.
    Stop it with scripts/dev-stop.ps1. See docs/local-dev.md.
#>
param(
    [switch]$Once,                  # run the stack once, do not supervise
    [int]$DockerTimeoutSec = 600,   # how long to wait for engine + postgres
    [int]$StartupGraceSec  = 120,   # ssr builds before it binds; do not judge it early
    [int]$HealthEverySec   = 15
)

$ErrorActionPreference = 'Continue'

$root    = Split-Path -Parent $PSScriptRoot
$logDir  = Join-Path $root 'storage\logs'
$log     = Join-Path $logDir 'devserver.log'        # supervisor events
$outLog  = Join-Path $logDir 'devserver.out.log'    # composer dev stdout
$errLog  = Join-Path $logDir 'devserver.err.log'    # composer dev stderr
$stop    = Join-Path $root 'storage\framework\dev-server.stop'

# Herd's PHP, not the WinGet build: that one was thread-safe and Smart App
# Control blocks its unsigned php8ts.dll, killing php.exe with no output at all.
$herd = Join-Path $env:USERPROFILE '.config\herd\bin'
$env:PATH = "$herd;$herd\php84;$env:PATH"

$ports = @{ server = 8000; vite = 5173; ssr = 13714 }

function Write-Log {
    param([string]$Message)
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Host $line
    Add-Content -Path $log -Value $line -Encoding utf8
}

function Test-Port {
    param([int]$Port)
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $async  = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        $opened = $async.AsyncWaitHandle.WaitOne(1000)
        if ($opened) { $client.EndConnect($async) }
        $client.Close()
        return $opened
    } catch {
        return $false
    }
}

function Test-DockerEngine {
    docker info *> $null
    return ($LASTEXITCODE -eq 0)
}

# Only ever kills processes belonging to THIS project. VS Code's extension host
# is also node.exe, so matching on image name alone would take Intelephense out.
#
# Freeing the three ports by owner is the part that actually matters: the ssr
# process runs as `node bootstrap/ssr/ssr.js` with a RELATIVE path, so it never
# matches the project root and used to survive cleanup, holding :13714 and
# making every restart die with EADDRINUSE.
function Stop-StackOrphan {
    $targets = New-Object System.Collections.Generic.HashSet[int]

    Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='node.exe'" -ErrorAction SilentlyContinue |
        Where-Object {
            $cl = $_.CommandLine
            if (-not $cl) { return $false }
            if ($cl -like "*$root*") { return $true }
            return ($cl -match 'concurrently|ssr\.js|artisan serve|queue:listen')
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

    foreach ($id in $targets) {
        Stop-Process -Id $id -Force -Confirm:$false -ErrorAction SilentlyContinue
    }

    if ($targets.Count -gt 0) {
        Write-Log "cleaned up $($targets.Count) orphaned stack process(es)"
        Start-Sleep -Seconds 2   # let the ports actually release before rebinding
    }
}

New-Item -ItemType Directory -Force -Path $logDir | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path $stop) | Out-Null
if (Test-Path $stop) { Remove-Item $stop -Force }

if ((Test-Path $log) -and ((Get-Item $log).Length -gt 5MB)) {
    Move-Item $log "$log.old" -Force
}

Write-Log "supervisor starting (root: $root)"
Set-Location $root

if (-not (Test-DockerEngine)) {
    $desktop = Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\Docker Desktop.exe'
    if (Test-Path $desktop) {
        if (-not (Get-Process -Name 'Docker Desktop' -ErrorAction SilentlyContinue)) {
            Write-Log 'docker engine down; starting Docker Desktop'
            Start-Process $desktop
        }
        $deadline = (Get-Date).AddSeconds($DockerTimeoutSec)
        while (-not (Test-DockerEngine) -and (Get-Date) -lt $deadline) { Start-Sleep -Seconds 5 }
    } else {
        Write-Log "Docker Desktop not found at $desktop"
    }
}

if (Test-DockerEngine) {
    Write-Log 'docker engine ready; bringing up compose services'
    docker compose up -d *>&1 | ForEach-Object { Write-Log "docker: $_" }
    $deadline = (Get-Date).AddSeconds(120)
    while (-not (Test-Port 5432) -and (Get-Date) -lt $deadline) { Start-Sleep -Seconds 2 }
}

if (Test-Port 5432) {
    Write-Log 'postgres reachable on :5432'
} else {
    Write-Log 'WARNING postgres not reachable; starting anyway (db-backed pages will error)'
}

while ($true) {
    Stop-StackOrphan

    Write-Log 'starting composer dev'
    $proc = Start-Process -FilePath 'cmd.exe' `
                          -ArgumentList '/c', 'composer dev' `
                          -WorkingDirectory $root `
                          -RedirectStandardOutput $outLog `
                          -RedirectStandardError  $errLog `
                          -WindowStyle Hidden `
                          -PassThru

    $started = Get-Date
    $reason  = $null

    while (-not $proc.HasExited) {
        Start-Sleep -Seconds $HealthEverySec

        if (Test-Path $stop) { $reason = 'stop requested'; break }

        if (((Get-Date) - $started).TotalSeconds -lt $StartupGraceSec) { continue }

        $down = @()
        foreach ($name in $ports.Keys) {
            if (-not (Test-Port $ports[$name])) { $down += "$name(:$($ports[$name]))" }
        }
        if ($down.Count -gt 0) {
            $reason = "unhealthy: $($down -join ', ') not listening"
            break
        }
    }

    if ($proc.HasExited) {
        Write-Log "composer dev exited (code $($proc.ExitCode))"
    } else {
        Write-Log $reason
        try { Stop-Process -Id $proc.Id -Force -Confirm:$false -ErrorAction SilentlyContinue } catch {}
    }

    Stop-StackOrphan

    if ($Once) { break }

    if (Test-Path $stop) {
        Remove-Item $stop -Force
        Write-Log 'stop requested; supervisor exiting'
        break
    }

    Write-Log 'restarting in 5s'
    Start-Sleep -Seconds 5
}
