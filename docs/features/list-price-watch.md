---
name: Watching the prices on a list
area: Wishlist / Alerts
status: Active — new 2026-09-12; one digest a morning
date_added: 2026-09-12
---

# Watching the prices on a list

"Mail me when anything on my list gets cheaper." One switch on the list with a percentage, every
item on it watched from then on, and one mail a morning listing what dropped and what is back in
stock. This is the wishlist mail of bstore.be, brought over on the owner's request: there a
subscriber picks a percentage and gets a table of drops per mail, and that is what people who came
from there expect a wish list to do.

It sits beside, not instead of, the per-product alerts ([wishlists.md](wishlists.md),
`price_alerts`): those answer "is *this one* cheaper than when I pressed the button, or under the
price I named", one mail per product. This answers "did *anything* on the list move by at least
this much since you last told me", one mail per person.

## The shape

- **`wishlists.price_watch_percent`**, nullable. Null is off. The allowed values are
  `ListPriceWatch::PERCENTAGES` (5, 10, 15, 20, 30): a short list rather than a free number, because
  1% is noise on a €30 item and the server is the only place that can refuse it. Set from the list's
  options panel (`ListTools.tsx`), through the same `PATCH /lists/{id}` every other switch uses.
- **`wishlist_items.watch_reference_price`** (cents) and **`watch_seeded_at`**. The reference is
  what a drop is measured against. It is taken when the switch goes on, when an item is saved onto a
  list that is already watching (`ItemSaver::saveGroup()`), and, defensively, by the job for
  anything unseeded. Seeding is always silent: an item seeded today reports nothing until its price
  moves from here, so switching the watch on does not mail a drop that happened last month.
- **The price** is `AlertEligibility::trackablePrice()`: the cheapest in-stock offer from a source
  whose programme allows price tracking. It used to be a private method on
  `RefreshWishlistedProducts`; both callers now share it.
- **`SendListPriceDigests`** runs once a day at 07:40, after the 05:20 live refresh, whose group set
  now includes the items on watched lists so a bol-only price is today's. Per owner it seeds,
  classifies (`ListPriceWatch::changes()`), moves the references (`apply()`), writes one
  `list_price_digest` notification per list with something to say, and sends one
  `ListPriceDigestMail` for all of them. Unique, like the other scheduled jobs.

## The reference moves

The rule everything else follows, and the reason a percentage works at all:

| was | now | means | reported |
|---|---|---|---|
| 100 | ≤ 90 at 10% | `drop` | yes, and the reference becomes `now` |
| 100 | 105 | `up` | no, but the reference becomes 105 |
| 100 | none | `gone` | no, reference becomes null |
| none | 80 | `back` | yes, "available again", reference becomes 80 |

Moving the reference after a drop is what makes one drop one mail: a frozen baseline would mail
the same 12% every morning until the price recovered. Moving it *up* after a rise is the part that
needed deciding. A reference frozen at the lowest price ever seen would never again say "20%
cheaper than last week", which is the sentence somebody watching a list wants to hear; the
per-product alert makes the same call when it re-arms at recovery. The cost is that a fall back to
where it started is reported as a drop, and that is what bstore does too, because its reference is
simply the previous check.

`gone` and `back` are bstore's `NA` and `NEW`. A product that goes out of stock and returns at the
same price is reported as available again, which is news to a person who wanted it.

The comparison is integer arithmetic on cents, `now * 100 <= was * (100 - percent)`, so a float
never decides a mail (invariant 7).

## Why once a day, and one mail per person

The live refresh runs twice a day, and the digest could follow both. It does not: a person watching
three lists would get up to six mails a day about products that moved a few cents each, and a
digest that arrives that often is a digest that gets muted. One pass, in the morning, after the
refresh that makes the prices current. The person, not the list, is the unit of the mail for the
same reason, and the button in a multi-list mail goes to the lists overview rather than favouring
whichever list sorted first.

The language is the owner's `preferred_market`, falling back to the first list's market, because a
list holds products from several markets at once and has no language of its own.

## Why switching off forgets

Turning the watch off clears every reference. Otherwise switching it on again next year would seed
nothing (everything is already seeded) and report the whole year in between as one enormous drop.
Off means off; on means from today.

## COMPLIANCE

Amazon offers can never seed a reference or produce a line in the mail: the price is chosen by
`trackablePrice()`, which reads trackable sources only, before anything reaches the template. The
mail links to our own product and list pages, never to a shop. An Amazon-only item is seeded with a
null reference, so a later trackable offer from another shop is reported as available again rather
than as a drop from a number we may not hold. See [amazon-compliance.md](amazon-compliance.md).

## Not done

- **A stop link in the mail.** The footer says where the switch is; there is no signed one-click
  route yet, which the Daily Cove digest has. Worth adding if Gmail's bulk-sender rules start to
  apply to this volume.
- **Manual items.** An item with no `group_id` has no price to watch and is skipped.
- **Suggestions awaiting a decision** are not watched; `Wishlist::items()` is accepted items only.

## Files

- `app/Services/Alerts/ListPriceWatch.php`, `app/Services/Alerts/AlertEligibility.php`
- `app/Jobs/SendListPriceDigests.php`, `routes/console.php`
- `app/Mail/ListPriceDigestMail.php`, `resources/views/mail/list-price-digest.blade.php`
- `app/Http/Controllers/WishlistController.php` (`update()`, `summarise()`),
  `app/Services/Wishlist/ItemSaver.php`
- `resources/js/Components/ListTools.tsx`, `resources/js/Pages/Notifications.tsx`
- `database/migrations/2026_09_12_000100_watching_the_prices_on_a_list.php`
- `tests/Feature/ListPriceWatchTest.php`
