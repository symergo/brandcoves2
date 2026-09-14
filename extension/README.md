# GiftCoves importer

A Chrome extension. Open a shelf on bol.com or Amazon, click once, and the
products on the screen are in GiftCoves.

The reasoning behind all of it — why bol and Amazon are handled differently, what
the scrapers learned from real pages, which page types are verified — is in
[docs/features/page-import.md](../docs/features/page-import.md). This file is
how to install and use it.

---

## Install

1. Mint a key with the `editorial.write` ability:

   ```bash
   php artisan bc:api-token "chrome extension" --abilities=editorial.read,editorial.write
   ```

   It is printed once. `editorial.publish` is not needed and should not be
   granted: importing a product is not publishing anything.

2. Open `chrome://extensions`, turn on **Developer mode**, click **Load
   unpacked**, and choose this `extension/` folder.

3. Open the extension's **Settings** (the link in the popup, or the options
   entry on the extensions page) and paste in the key. Leave the address as
   `https://giftcoves.com` unless you are testing against staging or a local
   server.

---

## Use

Open a bol.com or Amazon page and click the toolbar icon.

The popup lists what it found, everything ticked, with one button. Untick
anything you do not want. On a **product page** only that product is ticked and
the recommendations around it are left for you to choose — "the products on this
page" means something different on a shelf and on a single product.

**Alt+Shift+G** does the same thing without opening the popup: same selection
rule, with a badge on the icon and a notification for the result. Rebind it at
`chrome://extensions/shortcuts`.

Every product comes back with its own outcome, and an imported one links
straight to its GiftCoves page.

### Pages it reads

Search results, category pages, brand pages, campaign and deals pages, product
pages, and Amazon's bestseller and new-release charts. Anything that links
products the way the site normally links them.

Your own bol wish lists (`/lijstjes/`) work in your logged-in browser; they could
not be tested from a script, since they need a session.

Amazon's `/deals` page is the one known miss — its cards are client-rendered and
contain no product links at all.

If a page shows nothing, scroll it first: both sites render lazily, and the
extension reads what is in the page at the moment you click.

---

## What is actually sent

This is the part worth knowing, because the two sites are treated differently on
purpose.

**bol — ids and titles only.** Nothing the page says about price, stock, picture
or link is sent or believed. The server re-fetches every one of those from bol's
own API. That is what makes an imported product trustworthy, and what makes its
affiliate link earn: a scraped link works perfectly for the visitor and pays
nobody.

**Amazon — the page's own words, never the price.** There is no Amazon API
configured, so the title, description, image, category and barcode are stored as
the page stated them. The price is not read, not sent, and there is nowhere to
put it. Amazon products land in the ASIN store rather than in the catalogue, so
they never appear in search or offer comparison. See
[docs/features/amazon-compliance.md](../docs/features/amazon-compliance.md).

---

## Files

| File | What it does |
|---|---|
| `manifest.json` | permissions, matches, the keyboard shortcut |
| `scan-bol.js` | reads a bol page |
| `scan-amazon.js` | reads an Amazon page |
| `content.js` | picks the scraper for the host and answers the popup |
| `page.js` | asks a tab to scan; decides what "all of them" means |
| `api.js` | settings, the two endpoints, batching, error messages |
| `background.js` | the keyboard shortcut, badge and notification |
| `popup.*`, `options.*` | the two screens |

Each scraper is wrapped in a function of its own. Two content scripts in one tab
share a global scope, so a duplicate top-level name would break both — and the
failure arrives as a page that scans empty rather than as anything naming a
collision.

### After changing a file

Press the reload arrow on the extension's card at `chrome://extensions`. Content
scripts also need the bol or Amazon tab reloaded; the popup and service worker
pick up changes on their own.
