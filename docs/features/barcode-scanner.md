---
name: Barcode scanner (mobile)
area: Search / Mobile
status: Active
date_added: 2026-08-07
---

# Barcode scanner (mobile)

Point a phone camera at a barcode in a shop and immediately see whether it is
cheaper somewhere else.

## Why this is the strongest possible entry point

Every other route into the site starts with someone *wondering*. This one starts
with someone **holding the product, in the shop, about to pay** — the highest
purchase intent that exists. It is also the one moment where a price-comparison
site is unambiguously more useful than the retailer standing in front of you.

It turns the catalogue from something you browse into something you *check
against*.

## It is nearly free, because of work already done

A scan produces a GTIN. `product_groups` is already unique on
`(market, identity_key)`, and for EAN-grouped products **the identity key is the
GTIN**. So the lookup is:

```sql
SELECT * FROM product_groups WHERE market = ? AND identity_key = ?
```

A single index hit on a unique index. No new table, no new column, no fuzzy
matching. `Gtin::normalise()` already validates the check digit, folds UPC-A and
ITF-14 to GTIN-13, and rejects placeholders — the same rules the ingestion path
uses, so a scan and a feed row agree on what a product *is* by construction.

The offer-comparison card the result renders is the one Phase 2 builds anyway.

## The flow

1. **Scan** — camera opens, barcode decoded on-device.
2. **Validate** — `Gtin::normalise()`. A failed check digit means a misread;
   keep scanning rather than querying, because a wrong digit is a *different
   real product*, not a near miss.
3. **Look up the stored index** — direct hit on `(market, identity_key)`.
4. **Miss? Ask bol live** — `BolConnector::fetchById()`; bol's catalogue is
   EAN-addressable and far broader than our Awin feeds.
5. **Still nothing?** Offer to add it to a wishlist from the scanned code, and
   log the miss — see *What a miss is worth* below.

## Three honest limitations

These decide whether the feature feels magic or broken, so they belong in the
design rather than in a bug report later.

### Only EAN-grouped products can ever be found

Products grouped by the brand+title fallback have **no barcode to match**. In
v1's catalogue only about a third of Awin rows carried a usable EAN. A scan that
finds nothing is therefore the *expected* case early on, not a failure — and the
UI has to say so honestly rather than implying the product does not exist.

This is also a concrete argument for bol in the lookup path: its catalogue is
EAN-addressable and much broader than any single Awin advertiser.

### Browser support is uneven

`BarcodeDetector` is native on Chrome for Android, ChromeOS and macOS. It is
**not** in Chrome on Windows or Linux desktop, and **Safari and Firefox do not
ship it at all** — including Chrome on iOS, which is WebKit underneath and so
behaves like Safari here. The fallback is a WASM decoder (ZXing), lazy-loaded
**only when the scanner opens** — never on the homepage, where it would be pure
weight for the majority who never scan. The wasm is served from our own origin,
not zxing's default CDN: a core feature should not depend on a third party at
runtime, and a blocked or mismatched CDN build fails silently.

Requires HTTPS for camera access. Staging and production both have it; note it
for local dev, where `localhost` is treated as a secure context.

### Physical conditions are hostile

Shop lighting, curved packaging, damaged and shrink-wrapped barcodes. Practical
mitigations: torch toggle where `MediaStreamTrack` exposes it, a wide scan
region rather than a narrow line, continuous scanning with a stability check
(same code decoded twice before accepting), and a **manual entry fallback** —
typing 13 digits is tedious but it always works.

## The stream is attached after the preview mounts, never before

Fixed 2026-08-08. The `<video>` is rendered only while `scanning` is true, so at
the moment `start()` had the `MediaStream` in hand the element was not mounted
and `videoRef.current` was still `null`. The guarded assignment
(`if (videoRef.current)`) therefore did nothing at all: the camera came on, the
preview stayed black, and the decoder was handed a video with no source. That is
**every browser**, not a device quirk — it was reported on iPhone only because
that is where it was tested.

Two things conspired to hide it:

- The decoder throws `InvalidStateError` for a video below `HAVE_CURRENT_DATA`,
  and the frame loop swallows exceptions because *an undecodable frame is the
  normal case*. A permanently broken pipeline and an unreadable barcode produced
  identical behaviour: a running scanner that never finds anything.
- Nothing in the UI distinguishes "waiting for a barcode" from "will never see
  one".

So the fix is in three parts, and the last two matter as much as the first:

1. Attach the stream in an effect keyed on `scanning`. An effect runs after the
   commit that mounts the element, which is the only point where the ref exists.
2. Tick only when `readyState >= HAVE_CURRENT_DATA`, so the silent catch is
   reached only by genuine decode failures and cannot mask a dead preview again.
3. Surface a rejected `play()` instead of spinning on a video that will never
   produce a frame.

The error state holds a **translation key**, not translated text. `t` is a new
closure on every render, so storing a translated string would pull `t` into the
camera effect's dependencies and tear down and restart the decoder on every
keystroke in the manual-entry field.

## Privacy

**The camera stream never leaves the device.** Decoding happens in the browser
and only the resulting digits are sent to the server. This is worth stating in
the UI at the permission prompt: "we read the barcode on your phone; the camera
image is never uploaded."

No frames are stored, logged or transmitted. That is not a nice-to-have — asking
for a camera is the most invasive permission this site will ever request, and it
has to be obviously worth it.

## What a miss is worth

Every scan that finds nothing is a **precise, high-intent gap in the catalogue**:
a real product, a real shopper, a real moment of demand, identified by an exact
GTIN.

Logged to `events` as `scan_miss`, these become the best possible input to
merchant onboarding — not "we should add homeware", but "47 people scanned
things we do not carry from this category last month". Nothing else in the
product produces a signal that specific.

## Where it fits

| | |
|---|---|
| Depends on | Phase 2 — the product page and offer-comparison card |
| Route | `/{market}/scan` |
| Enhances | Wishlists (scan to add), Daily Picks (scan a pick you saw in a shop) |
| Effort | Small, because identity and lookup already exist. Most of the work is camera UX and the decoder fallback |

Suggested slot: **late Phase 2 or early Phase 3**, once there is a product page
worth landing on. Building it before that means scanning into an empty result.

## Not in scope

- **A native app.** A web scanner reaches everyone from a link; an app store
  listing is a distribution project, not a feature.
- **QR codes.** Different intent entirely — those are marketing links, not
  products.
- **Scanning a shelf label or a price tag.** OCR is a much harder problem with a
  much worse failure mode; barcodes are a solved, checksummed format.

## Files

- `resources/js/Pages/Scan.tsx` — camera UI, and `makeDetector()`: native
  `BarcodeDetector` where it exists, lazy-loaded ZXing wasm everywhere else.
  Small enough to stay in the page; there is no separate `scanner/` module.
- `app/Http/Controllers/ScanController.php` — validate, look up, fall back to bol
- Reuses `App\Services\Identity\Gtin` and `BolConnector::fetchById()` unchanged
