#!/usr/bin/env python3
"""Seed the GiftCoves cove planner from a JSON brief, with EANs instead of ids.

The editorial API has no EAN lookup: /products?q= is full-text over title, brand,
category and description, and a barcode matches none of those. So this resolves
every EAN through the public /{market}/scan/{ean} endpoint first — one hit on the
(market, identity_key) unique index — and only then writes the plan.

Usage
-----
    python seed_coves.py brief.json                        # production
    python seed_coves.py brief.json --base https://staging.giftcoves.com
    python seed_coves.py brief.json --resolve-only         # just EAN -> groupId

The key comes from $GIFTCOVES_API_KEY. It is never printed, and --dry-run needs no
key at all: resolution is a public endpoint.

One host, start to finish
-------------------------
A group id means nothing outside the environment that issued it. EAN 4548736132580
is group 3210 on production, 3921 on staging and 21214 on a local dev database —
same barcode, same market, three ids. So resolution and the write both go through
--base and there is no way to point them at different hosts.

The reverse is the trap: group 3921 is that Sony on staging and an ASUS Vivobook on
production. In stock, priced, right market, accepted by every check the server has —
nothing about the row is wrong, it is just not the product meant. Existence and market
cannot catch it. Only "expect" can. Prefer EANs.

Brief format (one object, or a list of them)
--------------------------------------------
    {
      "market": "be-nl",
      "kind": "guide",
      "slug": "beste-koffiemolens",          // or "date": "2026-09-14" for daily
      "title": "De beste koffiemolens",
      "blurb": "…",
      "editorial": "…",                      // daily/persona prose
      "body": "…",                           // guide/seasonal/advice prose
      "metaDescription": "…", "focusKeyphrase": "…",
      "faq": [{"question": "…", "answer": "…"}],
      "seasonFrom": "11-15", "seasonTo": "12-27",
      "pickMode": "locked",
      "queries": ["koffiemolen"],
      "buildInstructions": "…",
      "note": "…",
      "items": [
        {"ean": "8712345678901", "note": "the only one with a real grinder",
         "verdict": "best overall"},
        {"groupId": 5190, "expect": "Sage Smart Grinder",
         "note": "cheap, and the writing should say why"}
      ]
    }

An item may carry "ean" or "groupId". Mixing them across items is fine; sending both
on one item is an error, because they could disagree and there is no right answer to
which wins.

Prefer "ean": a barcode is the same number on every host, an id is not. On a literal
"groupId", add "expect" — a few words from the intended title. It is the only thing
that catches an id carried in from another environment, which otherwise names a real,
in-stock, validly-marketed *different* product.

Exit codes: 0 all written, 1 a write was refused, 2 an EAN did not resolve, 3 setup.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

TIMEOUT = 30

# Kinds addressed by a date rather than a slug. Mirrors CoveKind::isDated().
DATED_KINDS = {"daily"}

# Kinds that carry focusKeyphrase / metaDescription / body / faq. The server refuses
# these fields elsewhere rather than dropping them, so catching it here is only a
# faster, clearer version of the same answer. Mirrors CoveKind::isArticle().
ARTICLE_KINDS = {"guide", "seasonal", "advice"}

ALL_KINDS = {"daily", "persona", "guide", "seasonal", "advice", "shop"}
MARKETS = {"be-nl", "be-fr", "en", "es", "nl-nl"}

# /be-nl/p/8412/de-titel  ->  8412
PRODUCT_URL = re.compile(r"/p/(\d+)/")


class Fatal(Exception):
    """Something the caller has to fix before anything else can be tried."""


# --------------------------------------------------------------------------- http


def _request(url: str, *, token: str | None = None, body: dict | None = None) -> tuple[int, dict]:
    data = json.dumps(body).encode() if body is not None else None
    headers = {"Accept": "application/json", "User-Agent": "giftcoves-seed-coves/1"}
    if data is not None:
        headers["Content-Type"] = "application/json"
    if token:
        headers["Authorization"] = f"Bearer {token}"

    req = urllib.request.Request(url, data=data, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            return resp.status, json.loads(resp.read() or b"{}")
    except urllib.error.HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw or b"{}")
        except json.JSONDecodeError:
            return e.code, {"message": raw.decode("utf-8", "replace")[:500]}
    except urllib.error.URLError as e:
        # The sandbox blocking egress looks exactly like the host being down, and
        # the difference is the whole of what to do next.
        raise Fatal(
            f"Could not reach {urllib.parse.urlparse(url).netloc}: {e.reason}. "
            "If this environment has no outbound network, say so rather than retrying."
        ) from e


def _get(url: str, token: str | None = None) -> tuple[int, dict]:
    for attempt in range(3):
        status, payload = _request(url, token=token)
        if status != 429:
            return status, payload
        # Reads are 120/min per token; a backoff is cheaper than a failed run.
        time.sleep(2 ** attempt)
    return status, payload


# ------------------------------------------------------------------------ preflight


def preflight(base: str, token: str | None) -> str:
    """Say out loud which environment this run is about to write to.

    Both hosts answer every endpoint identically and differ only in who reads the
    result, so the single most useful thing to print before a write is which one it
    is. `environment` is "production" on staging too — it runs production config —
    so `branch` is the field that actually distinguishes them.
    """
    status, health = _get(f"{base}/health")
    if status != 200:
        raise Fatal(f"{base}/health answered {status}. Not writing to a host that is not healthy.")

    branch = health.get("branch", "?")
    host = urllib.parse.urlparse(base).netloc
    live = branch == "main"

    print(
        f"{'PRODUCTION' if live else 'staging'}: {host}  branch={branch}  "
        f"built={health.get('built', '?')}  migration={health.get('migration', '?')}",
        file=sys.stderr,
    )

    if token:
        status, index = _get(f"{base}/api/editorial", token=token)
        if status == 401:
            raise Fatal(f"The key was refused by {host}. A key is issued per environment — check it is this one's.")
        if status == 200:
            # Nested under `token`, not top-level. Read flat it silently prints
            # "none", which reads as a key with no abilities rather than a bug here.
            abilities = index.get("token", {}).get("abilities", [])
            print(f"  key: {index.get('token', {}).get('name', '?')} [{', '.join(abilities) or 'none'}]", file=sys.stderr)
            if "editorial.write" not in abilities:
                raise Fatal("this key cannot write drafts — it lacks editorial.write")

    return branch


def check_literal_id(base: str, market: str, group_id: int, expect: str | None, token: str) -> str | None:
    """None if this id is what the brief meant, else why not.

    Existence and market are the weak half. Group 3921 is a Sony WH-1000XM5 on
    staging and an ASUS Vivobook on production — same id, same market, in stock,
    priced, and accepted by every check the server has. Nothing about the row is
    wrong; it is simply a different product. So an id carried in from elsewhere is
    only caught by knowing what it was supposed to be, which is what `expect` is.

    Without an `expect` the title is printed and nothing is asserted, because a
    guess about which product was meant would be worse than an honest unknown.
    """
    status, payload = _get(f"{base}/api/editorial/products/{group_id}", token=token)

    if status == 404:
        return f"no product {group_id} on this host — an id from another environment?"
    if status != 200:
        return f"could not check (HTTP {status})"

    data = payload.get("data", {})
    title = data.get("title", "")

    if data.get("market") != market:
        return f"product {group_id} is {data.get('market')} here, not {market} — ids are per market"

    if expect:
        if expect.casefold() not in title.casefold():
            return (
                f'product {group_id} here is "{title}", which does not match the expected '
                f'"{expect}". An id from another environment names a real but different product.'
            )
        print(f"  groupId {group_id} = {title}  (matches {expect!r})", file=sys.stderr)
    else:
        print(f"  groupId {group_id} = {title}  (unverified — no `expect`, prefer an ean)", file=sys.stderr)

    return None


def verify_write(base: str, plan_id: int, sent: dict, token: str) -> list[str]:
    """Read the plan back and report what the server did not keep.

    A 201 is not proof. The deployed CovePlanController discards a non-persona's
    slug and its `validated()` omits body, faq, focusKeyphrase, metaDescription and
    the season window entirely — and Laravel's validate() returns only validated
    keys, so those arrive, are dropped, and the response still says 201. Measured on
    production 2026-08-29: a guide with a slug, a body and a FAQ came back with
    slug null and no article fields at all.

    Comparing what was sent against what reads back catches that, and keeps
    catching whatever the next version drops. It also stops reporting a problem the
    moment the fix is deployed, which a hardcoded list of "kinds that work" would
    not.
    """
    status, payload = _get(f"{base}/api/editorial/coves/{plan_id}", token=token)
    if status != 200:
        return [f"could not read plan {plan_id} back (HTTP {status}) — verify by hand"]

    got = payload.get("data", {})
    lost = []

    for field in ("slug", "date", "title", "blurb", "editorial", "buildInstructions",
                  "focusKeyphrase", "metaDescription", "body", "faq", "note"):
        if sent.get(field) and not got.get(field):
            lost.append(field)

    if sent.get("items") and got.get("curatedCount", 0) != len(sent["items"]):
        lost.append(f"items ({len(sent['items'])} sent, {got.get('curatedCount', 0)} stored)")

    return lost


# ------------------------------------------------------------------ ean resolution


def resolve_ean(base: str, market: str, ean: str, token: str | None) -> tuple[int | None, str]:
    """(groupId, explanation). groupId is None when it did not resolve."""
    ean = ean.strip().replace(" ", "").replace("-", "")
    if not ean.isdigit() or not 8 <= len(ean) <= 14:
        return None, "not a barcode (8-14 digits)"

    status, payload = _get(f"{base}/{market}/scan/{ean}", token=None)

    if status == 422:
        # Gtin::normalise rejected the check digit. A misread, not a miss — and
        # retrying the same digits will fail identically.
        return None, "failed its check digit — misread or mistyped"

    if status == 200 and payload.get("status") == "found":
        match = PRODUCT_URL.search(payload.get("url", ""))
        if match:
            return int(match.group(1)), payload.get("title", "")
        return None, "scan found it but returned no product URL to read an id from"

    # A miss can mean bol has it and we have never ingested it. Asking /products
    # with includeLive pulls and ingests through the ordinary path; its own reply
    # is empty (full-text, no EAN in the search vector), so ignore it and re-scan.
    if token:
        query = urllib.parse.urlencode({"market": market, "q": ean, "includeLive": 1})
        _get(f"{base}/api/editorial/products?{query}", token=token)
        status, payload = _get(f"{base}/{market}/scan/{ean}", token=None)
        if status == 200 and payload.get("status") == "found":
            match = PRODUCT_URL.search(payload.get("url", ""))
            if match:
                return int(match.group(1)), payload.get("title", "") + " (pulled live)"

    return None, f"no EAN-grouped product in {market}"


# ----------------------------------------------------------------------- the brief


def validate(brief: dict) -> None:
    market, kind = brief.get("market"), brief.get("kind", "daily")

    if market not in MARKETS:
        raise Fatal(f"market must be one of {sorted(MARKETS)}, got {market!r}")
    if kind not in ALL_KINDS:
        raise Fatal(f"kind must be one of {sorted(ALL_KINDS)}, got {kind!r}")
    if not brief.get("title"):
        raise Fatal("title is required")

    dated = kind in DATED_KINDS
    if dated and brief.get("slug"):
        raise Fatal("a daily Cove is addressed by its date; its slug comes from the title at build time")
    if not dated and brief.get("date"):
        raise Fatal(f"a {kind} is permanent and has no date — send a slug instead")
    if not dated and not brief.get("slug"):
        raise Fatal(f"a {kind} needs a slug: it is the permanent URL the page lives at")

    if kind not in ARTICLE_KINDS:
        stray = [f for f in ("focusKeyphrase", "metaDescription", "body", "faq") if brief.get(f)]
        if stray:
            raise Fatal(
                f"a {kind} carries no {', '.join(stray)} — it is a column, its words go in `editorial`"
            )

    if kind != "seasonal" and (brief.get("seasonFrom") or brief.get("seasonTo")):
        raise Fatal("only a seasonal guide carries a season window")

    for item in brief.get("items", []):
        if item.get("ean") and item.get("groupId"):
            raise Fatal(
                f"item {item} has both an ean and a groupId. They can disagree, and there is "
                "no right answer to which wins — send one."
            )
        if not item.get("ean") and not item.get("groupId") and not item.get("externalId"):
            raise Fatal(f"item {item} has nothing to identify it")


def build_payload(brief: dict, base: str, token: str | None) -> tuple[dict, list[str]]:
    """The POST body with every ean swapped for a groupId, plus the ones that failed."""
    payload = {k: v for k, v in brief.items() if k != "items"}
    payload.setdefault("kind", "daily")

    items, unresolved = [], []
    for item in brief.get("items", []):
        out = {k: v for k, v in item.items() if k in ("note", "verdict", "source", "externalId")}

        if item.get("groupId"):
            group_id = int(item["groupId"])
            if token:
                problem = check_literal_id(base, brief["market"], group_id, item.get("expect"), token)
                if problem:
                    unresolved.append(f"groupId {group_id}: {problem}")
                    continue
            out["groupId"] = group_id
        elif item.get("ean"):
            group_id, why = resolve_ean(base, brief["market"], str(item["ean"]), token)
            if group_id is None:
                unresolved.append(f"{item['ean']}: {why}")
                continue
            out["groupId"] = group_id
            print(f"  {item['ean']} -> {group_id}  {why}", file=sys.stderr)

        items.append(out)

    if items:
        payload["items"] = items
    return payload, unresolved


# ---------------------------------------------------------------------------- main


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("brief", help="JSON file: one brief object, or a list of them. '-' for stdin.")
    ap.add_argument("--base", default=os.environ.get("GIFTCOVES_BASE", "https://giftcoves.com"),
                    help="target host. Default production; https://staging.giftcoves.com for staging.")
    ap.add_argument("--dry-run", action="store_true", help="resolve and print the body; write nothing")
    ap.add_argument("--resolve-only", action="store_true", help="print ean -> groupId and stop")
    args = ap.parse_args()

    base = args.base.rstrip("/").removesuffix("/api/editorial")
    token = os.environ.get("GIFTCOVES_API_KEY")

    if not token and not args.dry_run and not args.resolve_only:
        print("GIFTCOVES_API_KEY is not set. Writes need it; --dry-run does not.", file=sys.stderr)
        return 3

    preflight(base, token)

    raw = sys.stdin.read() if args.brief == "-" else open(args.brief, encoding="utf-8").read()
    briefs = json.loads(raw)
    if isinstance(briefs, dict):
        briefs = [briefs]

    failed_writes, failed_resolution = 0, 0

    for brief in briefs:
        label = brief.get("slug") or brief.get("date") or brief.get("title", "?")
        print(f"\n=== {brief.get('market')} {brief.get('kind', 'daily')} {label}", file=sys.stderr)

        try:
            validate(brief)
            payload, unresolved = build_payload(brief, base, token)
        except Fatal as e:
            print(f"  refused: {e}", file=sys.stderr)
            failed_writes += 1
            continue

        if unresolved:
            failed_resolution += 1
            print("  unresolved EANs:", file=sys.stderr)
            for line in unresolved:
                print(f"    {line}", file=sys.stderr)
            # Writing a shortlist with a pick missing is the one thing not to do
            # silently: the prose still refers to it.
            print("  not written — decide what replaces them first.", file=sys.stderr)
            continue

        if args.resolve_only:
            continue

        if args.dry_run:
            print(json.dumps(payload, ensure_ascii=False, indent=2))
            continue

        status, response = _request(f"{base}/api/editorial/coves", token=token, body=payload)

        if status in (200, 201):
            plan = response.get("data", {})
            check = response.get("linkCheck", {})
            print(f"  plan {plan.get('id')} {plan.get('status', 'draft')}", file=sys.stderr)

            lost = verify_write(base, int(plan["id"]), payload, token) if plan.get("id") else []
            if lost:
                failed_writes += 1
                print(
                    f"  SILENTLY DROPPED: {', '.join(lost)}\n"
                    f"  The server answered {status} and did not store these. This host's API is "
                    f"older than the fields sent — check what it supports before writing more.",
                    file=sys.stderr,
                )

            if check.get("unresolved"):
                print(
                    f"  linkCheck: {check['unresolved']} will render as plain text — "
                    "advisory on a plan, the builder's own finds are not in the allowlist yet.",
                    file=sys.stderr,
                )
        else:
            failed_writes += 1
            print(f"  HTTP {status}: {json.dumps(response.get('errors') or response)}", file=sys.stderr)
            if status == 403:
                print("  the key lacks that ability. Do not route around it.", file=sys.stderr)

    if failed_writes:
        return 1
    if failed_resolution:
        return 2
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Fatal as e:
        print(f"{e}", file=sys.stderr)
        sys.exit(3)
