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

## Run what the change touches

The full suite is for the push, not for the edit loop. While work is in progress — including work
Claude is doing — run the narrowest thing that proves the change: `--filter` on the test class, or
the test file itself, or `--dirty` to pick up everything touching uncommitted work. Report which one
ran, so "tests pass" is never mistaken for "the suite passes".

`composer test` earns its 39 seconds at one moment: **before production goes out** — a push to
`main`, or the fast-forward that advances it — and whenever it is explicitly asked for.

The hook already runs the suite on *every* push, staging included, so this deliberate run is not
about coverage the hook would miss. It is about *when you learn*. Run by hand, a failure arrives
while shipping is still a decision; left to the hook, it arrives after you have committed to the
deploy and are watching it abort. Same 39 seconds, bought at the point where the answer can still
change what you do.

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

## Two gates: the hook and CI

`.github/workflows/tests.yml` runs the suite on GitHub for every push and pull request, against a
real `postgres:16-alpine` service. **This is the gate.** It cannot be skipped with `--no-verify`,
does not depend on anything a clone remembered to configure, and applies to pushes from machines
nobody has set up at all — a GitHub web edit, a second laptop, a merge performed in the browser.

`.githooks/pre-push` runs the same suite locally *before* the push leaves the machine, which is the
same 40 seconds spent earlier, where a failure costs nothing. It is the early warning, not the gate,
because it only exists once someone runs the per-clone config below.

Both matter, and they fail differently: the hook is fast but optional, CI is authoritative but only
learns about the code after it has been pushed — and a push to a tracked branch has already started
a deploy by then. That asymmetry is the argument for the hook, not against it.

### The differences in CI

- **`--processes=4`, not 8.** A standard GitHub runner has 4 vCPU; the 8 in `composer test` is tuned
  for a 20-core laptop, and oversubscribing spends wall clock rather than saving it.
- **The frontend is built.** Feature tests render the root Blade shell, which calls `@vite`, and
  `public/build` is gitignored — without `npm run build:client` every feature test dies on "Vite
  manifest not found" before asserting anything. Client bundle only: the suite pins
  `INERTIA_SSR_ENABLED=false`, so the SSR bundle is never loaded.
- **`.env` exists only for `APP_KEY`.** `phpunit.xml` supplies `APP_ENV`, the database and every
  connector credential itself; `.env.example` ships `APP_KEY` empty, so CI copies it and runs
  `key:generate`.
- **Pint runs as a separate job**, with `--test`, so CI reports formatting rather than producing a
  diff someone has to reconcile.

### Installing the hook

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
