# Brands from the start of the title

Since 2026-09-30. `App\Services\Catalogue\TitleBrand`, used by `OfferUpserter` for every offer it
writes, and `php artisan bc:fill-brands` (dry run unless `--write`) for the offers already stored.

## Why

Measured on production that day: 42% of product groups had no brand (be-fr 58,552, be-nl 82,226,
nl-nl 52,692, en 13,533). bol's catalogue API sends no brand at all, and eBay listings rarely do. A
product without a brand is missing from its brand page, from brand search and from the brand facet;
Le Creuset and Rituals, for example, were in the catalogue but could only be found by title. The owner
asked for a fill while reviewing the gift catalogue.

## The rule

An offer whose source sent no brand gets the brand its title **starts with**, if some source has
already named that brand on at least five offers (`TitleBrand::MIN_OFFERS`).

- **Start, not contain.** "Hoesje voor Sony WH-1000XM5" is somebody else's case. The same anchor
  `Search\BrandAttribution` uses on brand pages.
- **Only brands a source named.** A title's first word is never promoted to a brand on its own
  ("Dames", "Set", "Mini"). That caps what the fill reaches, and is why it is right when it fills.
- **The longest brand wins** ("Philips Hue" over "Philips"), at a word boundary ("Apple" does not
  claim "Applesauce"), on `Str::ascii()`-folded text ("KARCHER" is "Kärcher").
- **Three letters at least.** "LG" and "HP" are left out: two letters start too many titles that are
  not theirs.
- **Placeholders are never brands**: "Unbranded", "Generic", "Merkloos", "Does not apply" and the like.
- **A brand the source sent is never replaced.**

## What it must not touch: identity

For an offer without a barcode, the brand is part of its grouping key (`IdentityResolver`, title
fallback). Filling the brand before identity is resolved would move eBay offers into other groups.
`OfferUpserter` resolves identity from the brand the source sent, and only then fills the stored
brand. `OfferUpserterTest` holds both halves.

## How far it reaches

On a copy of production (2026-09-27 data): 35,984 of 337,804 unbranded offers, 743 brands. Philips,
LEGO, Apple, Disney and Funko lead. A sample of 40 groups was right 40 times; licences (Disney, Marvel,
Star Wars) come through as the brand, which is how the feeds that do send a brand write them too.

The rest stays unbranded: bol never names the brand, so a brand that no other source carries is not
known to us. Closing that gap needs a source that sends brands for bol's range, not a looser rule.

## The groups

Only `products.brand` is written. A group takes its brand from its display offer on the next
regrouping run (`ProductGrouper`, twice a day), which rewrites any group whose display offer changed.
