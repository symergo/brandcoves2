---
name: giftcoves-tag-products
description: Tag GiftCoves products with gift tags (interest, recipient, occasion, preference) and a not-a-gift verdict, judged in this session and posted over the production editorial API. Use when given a prompt from the admin's Product tagging page, or asked to tag products saved to wish lists, new catalogue products, or untagged products for GiftCoves or brandcoves.
---

# Tagging GiftCoves products

The admin page **Catalogue > Product tagging** counts the products nobody has judged yet and
hands out a prompt naming a queue, the markets and a period. You judge each product yourself
and post the verdicts. No model runs on the server; this session is the judgment.

## Setup

- Base: `https://giftcoves.com/api/editorial`. Key: `.claude/giftcoves_api.api` (`KEY=…`,
  gitignored). It needs the publish ability, which the tags write sits under.
- Reads 120/min, writes 20/min: wait about 3 s between writes.
- Every `curl` carries `--max-time`.
- Write request bodies to a file in the scratchpad with the Write tool and send them with
  `--data @file`. Bash heredocs mangle backslashes and quotes in product titles.

## Flow, per market in the prompt

1. **Read the brief**: [reference/brief.md](reference/brief.md). All of it, once per session.
2. **Fetch a page**:
   `GET /products/to-tag?market=<m>&source=<lists|new>&days=<n>&limit=200[&after=<last id>]`.
   It returns `waiting` (the whole queue), the `vocabulary`, and rows with `id`, `title`, `brand`,
   `category`, `minPriceCents` and `giftableByRules`.
3. **Judge every row** by the brief. Read the titles and decide; no keyword rules, no scripts.
4. **Post the page** as one batch, `POST /products/tags`:

   ```json
   {"market": "be-nl", "tags": [
     {"id": 123, "tags": ["interest:coffee", "preference:powered"]},
     {"id": 124, "tags": [], "giftable": false},
     {"id": 125, "tags": []}
   ]}
   ```

   | Your verdict | Entry |
   |---|---|
   | not a gift (`x`) | `{"id", "tags": [], "giftable": false}` |
   | a gift, no tag fits (`-`) | `{"id", "tags": []}` |
   | a gift with tags | `{"id", "tags": ["interest:…", "recipient:…", "occasion:…", "preference:…"]}` |

   Never send `"giftable": true`: the rules already let these through, and `true` would lift the
   price ceiling too. Every write stamps `gift_tags_at`, which takes the product out of the queue,
   `-` included.
5. **Next page** with `after` = the last id you fetched, until a page comes back empty.

A 422 names the tag or the id it refused, and nothing in that batch was written. Fix it and send
the batch again. A value you wish the vocabulary had is not something to invent here: note it for
the report. Values are added in code (docs/features/gift-tags.md).

## `lists` rows need care

A product in the `lists` queue was saved to somebody's wish list. The rules' verdict is shown
(`giftableByRules`) but not applied. Somebody chose it, so `x` needs a clear reason from the
brief's list (a refill, a spare part), not a doubt. Only the product is in the listing, never the
list or who saved it, and nothing about the list is yours to look up.

## Report

Per market: how many tagged, how many `x`, how many `-`, and any vocabulary value you wished for,
with how often.
