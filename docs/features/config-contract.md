---
name: The config contract
area: Core / Operations
status: Active
date_added: 2026-08-10
---

# The config contract

**A setting added on a laptop must reach production, or fail loudly on the laptop.**

## The problem it solves

A setting crosses four places before it does anything in production:

| Place | What it is |
|---|---|
| `config/*.php` | The `env()` call — the only part that lives in code |
| `.env.example` | The documentation, and the only place anyone learns a value is wanted |
| `docker-compose.coolify.yml` | Whether a container can *see* the variable at all |
| Coolify | The value itself |

Only the first is in the repository, so the other three drift. And **every way this fails is
silent**: `env('X')` with no default returns null, and null nearly always reads as "feature off"
rather than "somebody forgot".

It has cost twice already. A login 500'd on a missing variable. Then the second Awin publisher
account — `AWIN_VDB_API_TOKEN`, declared in config, documented in `.env.example`, and never passed
through the compose file. `config/brandcoves.php` filters accounts on a filled token, so the account
disappeared without a word: a laptop ingested from two publishers and every deployed environment
ingested from one, reporting complete success either way. That is the whole bug class in one
example — **correct locally, quietly diminished in production, no error anywhere**.

## Three parts

### 1. `tests/Unit/ConfigContractTest.php` — the guard

A static scan, deliberately not a runtime check: it has to fail on the machine of whoever adds the
key, before a deploy, and long before someone wonders why production has fewer merchants. Every
`env('KEY')` in `config/brandcoves.php` and `config/services.php` **without a default** must appear
both in `.env.example` and in the compose file's app environment. It also checks the reverse — a
`${VAR}` the compose file interpolates but nothing documents arrives as an empty string.

It has an allowlist, `NOT_OURS`, for stock Laravel `services.php` entries this app never uses
(Postmark, SES, Slack). The allowlist is the point: skipping a key is a line somebody writes and a
reviewer sees, not a pattern that silently swallows the next real credential ending in `_KEY`.

It found a live gap on its first run — `POSTGRES_DB`, `POSTGRES_USER` and `POSTGRES_PASSWORD` were
required by the deploy and documented nowhere.

### 2. `bc:check-config` — did it actually arrive

The runtime counterpart, run inside a container. The test proves a setting *can* reach production;
this proves it *did*. Both are needed, because correct plumbing says nothing about whether anyone
filled in the value at the far end.

Two rules:

- **Presence and lengths, never values**, exactly as `bc:check-bol` does. A diagnostic that prints a
  secret is one nobody can paste into a ticket.
- **Read `config()`, never `env()`.** Under `config:cache`, `env()` outside a config file returns
  null, so a checker built on `env()` would report everything missing on precisely the environment it
  exists to reassure you about.

It fails (non-zero) **only in production**. A laptop legitimately has no Resend key and no bol
credentials; exiting non-zero there would make it fail for every developer every time, which is how a
diagnostic becomes something people pipe to `/dev/null`. It still lists what is missing.

### 3. `/health` — the deploy check

A `config` block of booleans, so "did the config carry over?" is answered by `curl` alongside `built`
and `migration`, rather than by an SSH session.

Booleans and counts only — this endpoint is unauthenticated, so anything richer (a length, a prefix)
narrows a brute force and belongs behind the shell `bc:check-config` already needs. It is deliberately
**not** part of the `status` calculation: a missing Amazon key must not take the site down, and
Coolify restarts anything reporting unhealthy.

`awinAccounts` is a **count**, not a flag, because the failure it exists to catch was *fewer accounts
than expected*, not zero. The catalogue still built — from one publisher instead of two.

### 4. The admin screen

**Operations → Migration** renders the same report in the browser, so the answer does not require a
shell at all. The rules live in `App\Services\Ops\ConfigReport`, shared with the command — two
implementations of "is the config right" would drift, and the one that drifts is always the one
somebody is reading at the time.

Same rule, harder: presence and lengths only. The screen renders straight into HTML, so a value that
reached it would also reach a screenshot, a browser cache, and anyone standing behind the person
reading it. A test asserts the page never contains `APP_KEY`, the claim-hash secret, or the database
password while still showing that each is set.

## Declared vs configured

`config/brandcoves.php` now carries `connectors.awin.declared_accounts` beside the filtered
`accounts`. The filter still decides what runs; the declared list survives so a diagnostic can say
*which* account is absent and *which* variable to set.

Skipping an unconfigured optional account is right — it is what let this ship before the second set
of credentials existed. Doing it **silently** was the mistake, and `bc:awin-feeds` now names any
account it cannot reach before it spends a minute discovering feeds.

## Files

- `tests/Unit/ConfigContractTest.php`
- `app/Console/Commands/CheckConfigCommand.php`
- `app/Http/Controllers/HealthController.php` — the `config` block
- `config/brandcoves.php` — `declared_accounts`
- `docker-compose.coolify.yml`, `.env.example` — the two halves that drift

## Verification

```bash
composer test                  # ConfigContractTest fails the moment a key is unreachable
php artisan bc:check-config    # locally: warns, exits 0. In production: fails.
curl -s https://brandcoves.com/health | jq .config
```

To prove the guard bites, add an `env('SOMETHING_NEW')` with no default to `config/brandcoves.php`
and run the test. It fails until the key is in both the compose file and `.env.example`.
