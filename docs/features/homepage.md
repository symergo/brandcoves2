---
name: The homepage
area: Core / Frontend
status: Active
date_added: 2026-08-10
---

# The homepage

Four bands, in this order: the pitch, today's Cove, the gifting band, the Coves archive.

## The pitch says who it is for, and that includes you

Rewritten 2026-08-15 with the rename to GiftCoves. It read *"You don't know what you want. / You
know who it's for."* — a good line for a site about finding things, and one that quietly says this
place is for buying for **other** people. Half the product argues otherwise: `wishlists.kind` is
`mine` or `for_someone`, `SuggestionProfile` carries a whole `for_myself` shape whose budget curve
exists precisely because *"nobody thinks their own €12 wish is thoughtless"*
([gifting-lenses.md](gifting-lenses.md)), and the receiver lens is the other half of the gifting
model.

So the headline names both — *"Something worth giving. / Yourself included."* — and the two CTAs
split the same way: **Find a gift** next to **Something for yourself**. The second is the one that
was missing; a visitor shopping for themselves previously had to read past a page telling them it
was for somebody else.

The intro names the tools rather than only the search, because the tools are what a visitor cannot
guess is here: lists, sharing, group giving, Secret Santa, the quiz. It stops at naming them. The
[Gift Cove](gifting-lenses.md) hub exists to explain them, and repeating that explanation on the
front page is how both pages get longer and neither gets read.

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
