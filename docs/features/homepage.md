---
name: The homepage
area: Core / Frontend
status: Active
date_added: 2026-08-10
---

# The homepage

Four bands, in this order: the pitch, today's Cove, the gifting band, the Coves archive.

## What it does not end on: catalogue counters

Removed 2026-08-10. The page used to close with three stat tiles — products indexed, groups with
more than one offer, guides published — and, when the catalogue was empty, a panel reading
"The catalogue is empty. Run a feed ingestion to populate it:" above `php artisan bc:ingest`.

Both were scaffolding, and honest scaffolding: real counts rather than placeholders, put there while
ingestion was being built so the page could not lie about how empty the catalogue was. They did that
job and then kept running long after there was anyone left to lie to.

To a visitor they are a boast about our warehouse. **"57,911 products" says nothing about whether we
have the one they want**, and a page that ends on inventory size ends on us rather than on them. The
empty state was worse: an artisan command, on the front page, telling a shopper to run something on
a server they do not have.

The numbers that survive on this page are the ones that belong to the **visitor** — their lists,
their people, their Secret Santa groups — or to a **Cove**, in its monthly search volume. Those are
reasons to click. A total is not.

It also cost three `COUNT(*)` queries per homepage request, two of them across `product_groups`,
which is the largest table we have. The controller no longer computes them.

## The bands that stayed, and why they are in this order

- **Today's Cove first.** The thing that makes someone return tomorrow should not be one click deep.
  A visitor who lands here and sees a dated edition with real finds learns that this site changes;
  one who sees a search box learns it is a search engine.
- **The gifting band, with counts where the visitor already has something.** "3 lists" is a reason
  to click; "Make a list" is not, once the lists exist. Anonymous-first, exactly like the lists
  themselves — someone who saved a product before signing up sees it here.
- **The Coves archive last.** The evergreen half. It earns traffic over years, and the front page is
  where a first-time visitor learns the archive exists at all.

## Files

- `resources/js/Pages/Home.tsx`
- `app/Http/Controllers/HomeController.php`
- `lang/*/site.php` — the `home.*` block

## See also

- [navigation.md](navigation.md) — the header, and why editorial leads it
- [daily-cove.md](daily-cove.md) — the edition this page opens with
