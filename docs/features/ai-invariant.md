---
name: The AI invariant
area: Core
status: Active
date_added: 2026-08-07
---

# The AI invariant

> **AI is only ever called from a queued job. Never from a request handler.**

A visitor request must not be able to cause AI spend. Not "should not" — *cannot*, by construction.

## Why this is a hard rule and not a preference

Three failure modes it prevents, all of which are cheap to create and expensive to discover:

1. **Cost that scales with traffic instead of with content.** A gift wizard that expands angles via
   an LLM per request costs nothing in testing and a fortune the day something gets shared.
2. **Latency you cannot fix.** An LLM call in the request path puts seconds on a page that should
   render in under 200 ms, and no amount of caching helps the first visitor.
3. **A denial-of-wallet vector.** Any public endpoint that triggers a model call is a way for a
   stranger to spend your money in a loop.

## How it holds

- **Everything AI-touched is precomputed.** Daily-pick themes and blurbs, guide editorial copy and
  gift-angle expansion all run in scheduled jobs. The request path only ever *reads* the output.
- **`gift_angles` is the pattern.** The gift engine needs interest → query expansion, which is a
  natural LLM job. So the worker widens the angle map nightly and stores it; `GiftEngine` reads rows.
  Recommendation stays pure, fast and free.
- **Per-feature daily caps.** Every AI caller registers a `feature_key` in
  `config('brandcoves.ai.caps')` and goes through `AiUsage::withinCap()`. A feature with no key is
  invisible in the admin usage table, which is itself the incentive to register one.
- **Failed calls still count.** `AiUsage::record()` increments on error too — otherwise a
  persistently failing feature retries forever at full cost.
- **`AI_ENABLED=false` is a supported mode.** The site works without an API key: daily picks fall
  back to a curated theme rotation, guides to template copy. This is what makes the invariant
  testable rather than aspirational.

## Visitor writes stay AI-free too

The 🤯/meh reaction on a daily pick is an event insert and nothing more — rate-limited, honeypotted,
no model call, no live API call. Same treatment for any future user-submission route.

## Enforcement

Currently by convention and review. **Phase 5 should add an architecture test** asserting that no
class under `App\Http\Controllers` transitively references the AI client — the invariant is worth
more when a test protects it than when a document asserts it.

## Files

- `config/brandcoves.php` (`ai.*`)
- `app/Models/AiUsage.php`
- `app/Services/Ai/` (Phase 5)

## Settings in admin

`/admin/ai-settings` holds the enable switch, the API key, the model and the
per-feature daily caps. Everything there was an environment variable, which meant
every change was a redeploy — turning generation off during an incident should
not require a build.

**The invariant is untouched.** Enabling AI here makes the *nightly jobs* able to
call a model. It does not make a request able to: `AiClient` checks the queue
context before anything else, and an architecture test asserts no controller can
reach the client. `AiSettingsTest` restates that as a test on this feature.

### How it reaches the rest of the code

`AiSettingsStore::apply()` runs in `AppServiceProvider::boot()` and writes the
stored values **over** the config. `AiClient`, `AiUsage` and the usage table all
read `config('brandcoves.ai.*')` already, so nothing downstream changed — and
there is still only one way to ask whether AI is on. A second way is a way to get
a stale answer.

Precedence is **database over environment**. A setting nobody has touched has no
row and the env value stands; clearing a field deletes the row and falls back,
which is the only undo available on a machine where you cannot edit the env.

An **allowlist** maps setting names to config paths. Without it a row in that
table could overwrite any config value in the application — a privilege
escalation dressed as a settings screen.

### The credential

Stored encrypted with `APP_KEY` in `connector_settings`, whose `source` CHECK was
widened to accept subsystems as well as connectors.

The field is **always empty on load** and an empty submit means "leave it alone",
so a save that only changes a cap cannot silently blank the key — and the key
never enters an HTML response, a browser's form cache, or a screenshot. What is
shown instead is a fingerprint: last four characters and a length, which answers
the only question anyone has. The "Test the key" action makes one real, tiny call
that counts against the cap like any other, because a test that bypassed the cap
would not be testing what runs in production.

Rotating `APP_KEY` orphans the stored key. The fix is to paste it again here.
