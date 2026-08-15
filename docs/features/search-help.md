---
name: Search and scanning instructions
area: Search / Content
status: Active
date_added: 2026-08-15
---

# Search and scanning instructions

`/{market}/search-help` — one page saying what the search box accepts and how the camera works.

## Why the page exists

The search field takes four different kinds of input and looks exactly like every other search box
on the internet:

- words, with typo tolerance and accent folding
- a barcode, 8–14 digits, which lands on the product rather than a result list
- an Amazon product URL, whose slug is read for the product name
- a query it will stem in the market's own language

None of that is discoverable. A placeholder can carry one of those four; a tooltip carries nothing on
a phone; and a field that explains itself in four lines has stopped being a field. So the box stays
plain and the explanation lives on a page that can be linked to, read at leisure and indexed.

The scanner half is the part that earns the page on its own. Asking for a camera is the most invasive
permission this site will ever request, and "where does the picture go" deserves an answer somewhere
better than a permission dialog. It is answered in one line — the image never leaves the device, only
the digits are sent — and that line is the truth, see [barcode-scanner.md](barcode-scanner.md).

## Every claim is checkable against the code

This is the constraint the copy is written under, and it is what keeps the page from becoming
marketing.

The sharpest case is **"finding nothing is normal"**. Only EAN-grouped products can ever be matched
by a scan, and in v1's catalogue about a third of Awin rows carried a usable EAN, so a miss is the
*expected* case rather than a fault. A help page that implied every product is scannable would turn a
working feature into one that looks broken the first time it is used. The same rule kills the
temptation to print how many products the market carries — the catalogue-counter mistake from
[homepage.md](homepage.md), which flatters us and answers nothing anyone standing in a shop is
asking.

Where the page describes something the visitor can be wrong about, it says what to do instead: a
failed check digit means keep scanning, a shrink-wrapped barcode means type the digits, a shortened
`amzn.to` link means paste the full one.

## Copy in the language files, not markdown on disk

About, privacy and terms are long documents reviewed as a whole, so they are markdown on disk in two
languages with an honest "English pending translation" banner — see
[legal-pages.md](legal-pages.md).

This page is the opposite shape: forty-odd short strings that had to exist in **all four** market
languages the day it shipped. A reader who is mid-task, standing in a shop, served English
instructions for a Dutch search box is a worse failure than the same substitution on a privacy
policy. `lang/{en,nl,fr,es}/site.php` under `search_help.*` is where every other in-product sentence
already lives, and `HandleInertiaRequests` shares the whole `site` file, so nothing had to be
registered.

## Where it is linked from

Both places with a search field on them, and nowhere else:

| Surface | Link |
|---|---|
| Homepage hero | Under the search form, `search_help.link` |
| Search page | Under the box, above the results |

**Not in the header.** Scanning was removed from the top nav on 2026-08-10 for the reason that
applies here too: this is not a *section* of the site, it is documentation of a control, and it
belongs beside the control. A nav entry would claim a sixth destination that is not one.

Listed in the sitemap at priority 0.4, monthly. "How do I scan a barcode to compare prices" is a real
query with real intent, and `/search` itself cannot answer it — a results page with nothing on it
until somebody types.

## Files

- `app/Http/Controllers/SearchHelpController.php`
- `resources/js/Pages/SearchHelp.tsx` — three definition lists, because a reader arrives with one
  specific question and scans for the line that answers it
- `lang/{en,nl,fr,es}/site.php` — `search_help.*`, 47 keys, identical in all four
- `routes/web.php` — beside `/search`, not with the legal pages
- `app/Http/Controllers/SitemapController.php`
