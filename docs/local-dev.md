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

### The health check has to be address-family agnostic

Fixed 2026-08-29, after a morning of `localhost` serving a blank page.

`Test-Port` connected to `127.0.0.1` with `New-Object Net.Sockets.TcpClient`, which on Windows
PowerShell 5.1 creates an **IPv4-only** socket. Vite is given no `server.host`, so it binds whatever
`localhost` resolves to — here `::1`, and it listens on IPv6 **only**:

```
Get-NetTCPConnection -State Listen -LocalPort 5173
LocalAddress LocalPort OwningProcess
::1               5173         29672
```

So the probe failed against a Vite that was running perfectly — `devserver.out.log` says *"ready in
412 ms"* on the same line the supervisor calls it dead. The stack was then killed and restarted every
two minutes, indefinitely.

**What it looks like is not an outage.** `public/hot` points the browser at a port whose process keeps
being killed underneath it, so the site is blank or stale much of the time and it reads as "my change
did not take". `devserver.log` is where it is obvious: an unbroken `unhealthy: vite(:5173) not
listening` → `restarting in 5s` loop.

Retrying the connect against `::1` is **not** the fix — the same IPv4 socket throws on an IPv6
address, so the second attempt fails silently in the `catch`. `Test-Port` now asks
`Get-NetTCPConnection`, which is address-family agnostic, answers the question the supervisor
actually has (*is anything serving this port?*), and is already what `Stop-StackOrphan` depends on.

> Pinning Vite to `127.0.0.1` in `vite.config.ts` would also have worked, and was rejected: a health
> check that can only see IPv4 will tell the same lie about the next service that prefers IPv6.

**A warning worth having.** `Stop-Process node -Force` — killing every node process to clear a stray
SSR server — takes Vite down with it, and takes the supervisor's stack with it. Use
[`scripts/dev-stop.ps1`](../scripts/dev-stop.ps1), which stops the task and frees the three ports by
owner, and then `Start-ScheduledTask "GiftCoves Dev Server"`. Note that `dev-stop.ps1` leaves a
`storage/framework/dev-server.stop` file that the next supervisor start consumes and exits on, so
starting the task immediately after stopping it appears to do nothing.

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
