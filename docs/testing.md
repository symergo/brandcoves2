# Testing

894 tests, ~62,000 assertions, against a real PostgreSQL. The suite is the only thing standing
between a commit and a deploy, because both branches auto-deploy and neither has a human gate.

## Running it

```bash
composer test          # parallel, 8 processes — the normal way
composer test:serial   # one process, for when parallelism is the suspect
php artisan test --dirty      # only tests touching uncommitted changes
php artisan test --filter=BrandPage
```

## Parallel by default

Measured on 2026-08-15, 20 logical cores, Postgres in Docker:

| Processes | Wall clock |
|---|---|
| 1 (serial) | 150 s |
| 8 | 39 s |
| 16 | 36 s |

**Pinned at 8, not at the core count.** Past eight the curve is flat — 16 processes bought 8% for
double the memory and double the Postgres connections, and `--parallel` with no `--processes`
defaults to all 20, which is 10 GB of PHP heap at the `memory_limit` in `phpunit.xml` plus twenty
databases for Docker Desktop to keep open. The bottleneck above eight is Postgres and the Windows
filesystem, not CPU.

Laravel clones the test database per worker (`brandcoves_test_test_1` … `_8`). The `brandcoves`
role needs `CREATEDB`; in the local compose stack it is superuser, so this is already true.

> **Wall-clock assertions and parallelism do not mix.**
> `SuggestionEngineTest::it_answers_fast_enough_to_sit_in_a_request` asserts a 500 ms budget and
> failed at 576 ms during a serial run that merely had other work happening on the machine. Under
> eight workers it is measuring contention, not the engine. It passes today; it is the first test
> that will flake, and the fix is to assert on query count or work done rather than elapsed time.

## `APP_DEBUG=false` in the test run

`phpunit.xml` sets `APP_ENV=testing`, so Laravel looks for `.env.testing` — which is gitignored, so
on most machines it silently falls back to `.env`, where `APP_DEBUG=true`. Every one of the ~39
tests asserting a 404 then rendered Symfony's full debug error page. That is the expensive,
memory-hungry path the `memory_limit` note in `phpunit.xml` was raised to survive.

Pinned to `false` in `phpunit.xml` so it no longer depends on a file that may not exist. Failure
diagnostics are unaffected: Laravel captures the exception on the `TestResponse` and prints it in
the assertion message either way. The run now exercises the error page production actually serves.

## Tests run on push, not on save

`.githooks/pre-push` runs the suite and aborts the push if it fails. 40 seconds is too slow for an
edit loop and too valuable to skip entirely, and a push is the last moment a failure is still cheap
— `git push origin staging` *is* a deploy.

Install once per clone:

```bash
git config core.hooksPath .githooks
```

The hooks directory is versioned; `.git/hooks` is not, which is why the indirection exists. The
hook checks Postgres is reachable first and says so, rather than letting the failure arrive as 894
connection errors. Skip it with `git push --no-verify` when you know why that is fine.

### A failed parallel run is re-run serially — since 2026-08-29

Under a real `git push` on this Windows machine, ParaTest prints its banner and exits **127**,
command not found, having run **zero tests**. The identical command passes from a shell, under
Git's `sh`, under the hook invoked by hand, and under `git push --dry-run`. The hook's environment
on a real push is byte-identical to the dry-run's — `PATH`, `PATHEXT`, `COMSPEC`, cwd all match —
`PHP_BINARY` resolves to Herd's `php.exe`, and two workers on a single test file are fine. It fails
only when spawning eight workers for the full suite, and it fails before the first test runs.

The hook reported that as *"tests failed — push aborted"*, which is precisely what it must never say
when the suite has not run — the same class of bug as the earlier `php: command not found` under a
"tests failed" banner. It blocked five consecutive pushes of a commit whose suite was green,
verified five separate ways.

The hook now re-runs the suite **serially** when the parallel run fails, and the serial result
decides. This cannot weaken the gate — a genuinely broken test fails both ways and the push still
aborts — it can only turn a false abort into a pass. The extra ~150s is spent only on a run that was
going to abort anyway.

The root cause is unresolved; the fallback is a guard, not a fix. If it fires routinely rather than
rarely, suspect process-creation pressure from the supervised dev stack (`GiftCoves Dev Server`
restarting `artisan serve`, the queue, Vite and SSR) racing eight fresh workers, and try
`--processes=4` before anything cleverer.

## Why real Postgres, and other traps

Recorded in the comments in [phpunit.xml](../phpunit.xml), which are worth reading before changing
anything there: the schema cannot run on SQLite at all; live bol credentials leaking in from `.env`
once made search tests assert against a third party's inventory; and Inertia SSR quietly rendered
the suite through whatever stale Node bundle happened to be listening.
