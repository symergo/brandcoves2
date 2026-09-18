---
name: Scheduled writing
area: Content / Operations
status: Active
date_added: 2026-08-30
---

# Scheduled writing

**A Claude scheduled agent asks what needs writing, writes it, and posts it back as
a draft. A person still approves.**

The Cove planner decides what a page is about and which products are on it. Somebody
still has to write the words. That can be the built-in writer — which costs AI budget
on this server and is capped per day — or it can be an agent running on a schedule
somewhere else, which costs nothing here.

It costs nothing because authored prose skips the model entirely; see
[editorial-api.md](editorial-api.md#authored-prose-wins-outright-and-skips-the-model).

## Setup

**Mint a key** with `editorial.read` and `editorial.write`, and deliberately **not**
`editorial.publish`:

```bash
php artisan bc:api-token "scheduled writer"
```

(read and write are the defaults)

The plaintext is shown once. `publish` is what approves a plan and puts a page in
front of readers; withholding it is the entire safety model, not a precaution. An
agent that cannot publish cannot publish something nobody read.

**Base URL** is `https://staging.giftcoves.com/api/editorial` or
`https://giftcoves.com/api/editorial`. Authenticate with `Authorization: Bearer …`.
Two throttles apply — a general one keyed on the token, and a tighter one on writes.

**Put the key in the environment, not in the prompt.** A scheduled task's prompt is
stored and its transcript is kept; a key pasted into either is a key in a log.

**Cadence:** one run per market per day, staggered. Size the run from
`GET /coves/queue?limit=` rather than from the calendar — a market with nothing to
write makes a cheap run and nothing is handed out twice.

## The loop

### 1. Ask what needs writing

```
GET /api/editorial/coves/queue?market=be-nl&limit=3
```

Returns only draft or approved plans that are **marked for an outside writer**
(`writer: authored`) and still have no prose, asked of the field the kind
actually writes: `body` for a guide, seasonal, advice, shop or brand Cove,
`editorial` for a Daily or a persona. That is what stops the same Cove being
offered on every run, without a "claimed" status that a crashed agent would
leave set forever, and what keeps this agent and the built-in writer off the
same plan.

**An empty queue is usually the marking, not the calendar.** A plan is marked by
*Operations → Automation*, setting `write` to `external` for that market and
kind, or one at a time with `PATCH /coves/{id}` `{"writer": "authored"}`. Left
on the default, `builder`, a plan belongs to the writer on this server and is
deliberately not offered here.

Each entry carries everything needed to write it, so there is no second call:

| Field | What it is for |
|---|---|
| `revision` | Send it back. See step 3. |
| `kind`, `language` | A Daily is a column; a guide is a comparison. Write in `language`. |
| `title`, `blurb`, `focusKeyphrase` | What the page is called and what it answers |
| `buildInstructions` | The editor's direction for this piece |
| `items[].note` | **Why the curator chose this product.** The most useful sentence in the payload, and the one thing a search result could never supply. |
| `items[].product` | Title, brand, category, shop count — facts, never prices |
| `allowlist` | Exactly what the prose may link to |

### 2. Write it, and post it back

```
POST /api/editorial/coves/{id}/editorial
{ "revision": "…", "editorial": "…", "items": [{ "id": 12, "verdict": "Best for the train" }] }
```

Narrower than `POST /coves` on purpose. That endpoint is a whole-plan upsert: it leaves the
shortlist alone when no items are sent, but resets every other field (editorial, blurb, pickMode,
writer) to what the body carries. This endpoint writes prose and cannot touch membership or rank. An
item id belonging to another plan is a 422, not a silent skip: it means the writer is working from a
stale brief, and the rest of what it wrote is suspect too.

### 3. Send the revision back

A scheduled agent retries, and two runs must not overwrite each other or a person's
edit. The queue returns a `revision` — a hash of the plan's timestamp and its item
ids — and the write requires it. A stale one gets **409 with the current state**, so
the agent can start that Cove again rather than guess what changed.

### 4. Read `linkCheck`, fix once, stop

The response reports every token that will not resolve. Fix and resubmit **once**,
then move on: a loop that keeps trying is a loop that spends the write throttle on
one Cove.

## The prompt

Paste this into the scheduled task. `{market}` is the only thing to fill in.

> Fetch `GET /api/editorial/coves/queue?market={market}&limit=3`. For each Cove it
> returns, write the editorial and post it back to
> `POST /api/editorial/coves/{id}/editorial`. Then stop.
>
> Fetch `GET /api/editorial/coves/{id}/brief` for each Cove and write to its `system` and `user`
> messages; they are the rules. Send `revision` back exactly as received; on a 409 re-fetch and
> start again. Read `linkCheck`, fix once, move on. Do not approve, publish or build.

## Why three of those rules exist

None of this is recoverable from a diff, so it is written down here.

**Never write a price.** Prices are re-read from the catalogue at render, and an
Amazon price is re-fetched live because we may not store it. A number written into
prose is a lie with a timestamp — and prose is the part of a page a reader trusts.

**Only what the allowlist contains.** A confident model asked to link to "the gift
finder" will invent `/gifts`, `/gift-finder` and `/tools/gifts` with equal
confidence, and each one is a 404 in the middle of an article. `CoveMarkup` renders a
token it does not recognise as plain text, so the failure is quiet: the sentence
reads fine and the link is simply gone.

**A key that cannot publish.** Everything else here is a convenience. This is the
part that means a scheduled job cannot put an unreviewed page in front of a reader,
however wrong the prompt turns out to be.

## Files

- `app/Http/Controllers/Api/CoveQueueController.php` — both endpoints
- `routes/api.php` — `/coves/queue` is registered *before* `/coves/{plan}`, or the
  word "queue" binds as a plan id and 404s
- `tests/Feature/CoveQueueApiTest.php`

## Open

- Nothing tells the agent a Cove was rejected after it wrote one. A `rejected` plan
  simply stops appearing in the queue.
- No webhook when a plan is approved, so an agent cannot confirm its work shipped.

## See also

- [editorial-api.md](editorial-api.md) — abilities, tokens, the writing contract
- [cove-planner.md](cove-planner.md) — where the plans and their briefs come from
- [ai-invariant.md](ai-invariant.md) — why authored prose costs nothing
