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
