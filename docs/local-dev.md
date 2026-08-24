# Running the site locally

`composer dev` is still the command. This document is about the two things that made
`localhost` keep disappearing on the Windows host, and the supervisor that now prevents it.

## PHP must be Herd's, not WinGet's

**Smart App Control is enforced on this machine** (`VerifiedAndReputablePolicyState = 1` under
`HKLM:\SYSTEM\CurrentControlSet\Control\CI\Policy`). It refuses to load unsigned DLLs.

The WinGet PHP 8.4 package is a **thread-safe** build, so `php.exe` loads `php8ts.dll` — which is
unsigned. Code Integrity blocks it, and the failure is uniquely unhelpful: `php.exe` starts, the
loader refuses the DLL, and the process exits with `0xC0E90002` printing **nothing at all**. Not an
error, not a usage message, no stderr. `composer dev` then dies instantly with exit code 2 and no
output, because `concurrently --kill-others` tears the stack down the moment PHP fails. It reads as
"the dev server never came up".

Herd's PHP 8.4 is a **non-thread-safe** build. There is no `php8ts.dll` to block, and its `php.exe`
clears Smart App Control on cloud reputation. That is the whole reason the toolchain moved:

```
C:\Users\<you>\.config\herd\bin\php84\php.exe
```

The WinGet package was uninstalled and removed from `PATH` on 2026-08-16 so nothing can fall back to
it. If PHP ever dies silently again, check first:

```powershell
Get-WinEvent -FilterHashtable @{LogName='Microsoft-Windows-CodeIntegrity/Operational'; Id=3077} -MaxEvents 5 |
  ForEach-Object { $_.Message }
```

Event **3077** is the signature of this failure. Note that Herd's PHP is *also* unsigned — it passes
on reputation, not on a certificate — so this is not permanently immune. The blocks escalated over
nine days (an extension, then another, then `php.exe`, then `php8ts.dll`) before PHP stopped working
entirely.

## The supervisor

`composer dev` in a console window is fragile in a way that costs an afternoon: a stray Ctrl+C, a
closed window, or a reboot takes `localhost` down and nothing brings it back. It happened three times
on 2026-08-16.

[`scripts/dev-server.ps1`](../scripts/dev-server.ps1) runs the stack under supervision, registered as
the **`GiftCoves Dev Server`** scheduled task with an **at-logon** trigger. It starts Docker Desktop
if the engine is down (Docker's own `AutoStart` is off, and postgres must be listening before
`artisan serve` boots), runs `docker compose up -d`, waits for `:5432`, then keeps `composer dev`
alive.

Two details in it are load-bearing and easy to undo by accident:

**Composer's output goes to a file, never through a PowerShell pipeline.** `artisan serve` spawns a
`php -S` grandchild that inherits the stdout handle. Piping means the pipeline stays open long after
composer itself has exited, so the supervisor blocks forever and never restarts. Waiting on the
process handle avoids it.

**Orphans are killed before every restart.** `concurrently --kill-others` does not reliably reap that
`php -S` grandchild or the ssr node process. A survivor holds its port and the replacement dies with
`EADDRINUSE`. The ssr process is the nasty one — it runs as `node bootstrap/ssr/ssr.js` with a
*relative* path, so it never matches the project root and used to survive a path-based sweep. Cleanup
therefore frees `:8000`, `:5173` and `:13714` **by port owner**, which is deterministic.

Health is judged on all three ports, not just `:8000`. An orphaned `php -S` will happily answer
requests while vite, ssr and the queue are all dead — the site looks fine and nothing works.

Cleanup only ever kills `php.exe`/`node.exe` that belong to this project. VS Code's extension host is
also `node.exe`, and matching on image name alone would take Intelephense out with it.

## Day to day

```powershell
# status
Get-ScheduledTask -TaskName 'GiftCoves Dev Server' | Select-Object State
Get-Content storage\logs\devserver.log -Tail 20        # supervisor events
Get-Content storage\logs\devserver.out.log -Tail 40    # the stack's own output

# stop (deliberately - the stop file is what stops it restarting itself)
.\scripts\dev-stop.ps1

# start again
Start-ScheduledTask -TaskName 'GiftCoves Dev Server'

# stop it starting at logon
Disable-ScheduledTask -TaskName 'GiftCoves Dev Server'
```

Running `composer dev` by hand in a terminal still works and is fine for a focused session — just
stop the task first, or the two will fight over the ports.

`storage/logs/*.log` is already gitignored via the root `*.log` rule.
