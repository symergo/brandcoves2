---
name: Offline ideas (what people typed by hand, as ideas for others)
area: Gifting / Wishlist / Admin
status: Active — counted nightly (04:00); nothing shows until five people wrote it and a person approved it
date_added: 2026-09-26
---

# Offline ideas: what people typed by hand, as gift ideas for others

The owner's request (2026-09-26): "use the gifts created by others as suggestions for others." This
is the part about **offline items**: things people typed onto their own lists by hand (a cooking
workshop, a spa day, a concert ticket, a voucher). Those are `wishlist_items` with
`source = manual` ([wishlists.md](wishlists.md), "Offline items, with a photo"). Most have no link
and are not in the catalogue, so the people who thought of them are the only source of ideas like
these.

## The privacy problem decides the design

A typed title can name a person or say something private ("tickets voor Anna's concert", "the red
scarf like mum's"). So an idea has to pass two gates before a visitor sees it:

1. **Five different people** wrote something that folds to the same idea
   (`giftcoves.offline_ideas.min_owners`, the same bar as [list-signals.md](list-signals.md)).
   Different list owners, not lists or items: one person typing it on ten lists is one. Five
   strangers writing the same words cannot be writing about one person.
2. **A person reads it** in the admin (`/admin`, Content > Offline ideas), writes the wording a
   visitor will see, tags who and what it suits, and approves or rejects it. Only approved ideas
   are ever shown.

And these always hold:

- A visitor sees the approved **wording and an id**, nothing else: not who wrote it, not how many,
  not anything anybody typed. `OfflineIdea` hides the key, the count and the spelling even from
  accidental serialisation.
- **Photos are never read.** The count reads the title only.
- **What is kept is the fold, not the text.** The table holds the normalised key, the count, and
  one spelling for the reviewer (the one most people used). That spelling is emptied the moment the
  idea is approved or rejected.
- **Only link-less, product-less, accepted items count.** An item with a link is named after the
  shop until its page is read, and would teach us shop names ("bol.com" five times is not an idea);
  an item joined to a product is a product; a visitor's suggestion the owner never accepted is not
  the owner's.
- **Private lists count too**, as they do for the list signals. The privacy page says so, in "What
  lists teach together" (English and Dutch; fr/es legal pages are not translated yet).

## How titles fold (`App\Services\Ideas\IdeaKey`)

Pure and unit-tested. Lowercase, accents off; every word with a digit goes (years, dates, prices,
sizes, counts: they are what makes an item one person's, never what makes it an idea); size and
money words go; small words go per language plus English (articles, "voor", "my", "with"); a plural
"s" goes from words over three letters; then the words are **joined without spaces**.

The join is the Dutch compound rule: "kook workshop", "kook-workshop" and "kookworkshop" are three
spellings of one word, and joining makes them one key. Word order still counts ("workshop koken"
stays its own key); telling that apart would take a dictionary, and the reviewer sees both anyway.

A title with **more than five words left is refused**: a long title is a description of one
particular thing, and the longer it is the likelier it is to be personal. Keys under four letters
are refused as too little to be an idea.

## The nightly count (`CountOfflineIdeas`, 04:00)

After the personal-data prune (03:20, so deleted items no longer count) and the list signals
(03:50). `OfflineIdeaCounter` folds every offline item into a temporary table in chunks, then counts
distinct owners per market and key in SQL, and:

- proposes a new idea (`pending`) for a key that reached the bar;
- refreshes the count and the spelling of one still waiting;
- **drops a waiting idea that fell below the bar** (items deleted, lists gone): it no longer passes
  the bar that made it safe to show a reviewer;
- keeps approved and rejected ideas whatever the count: an approved wording is the reviewer's own,
  and a rejection is what stops an idea from coming back;
- skips a key that is an approved idea's own reworded wording coming back. Every "Add to my list"
  puts that wording on somebody's list, and without this a reviewer who wrote "Een workshop koken"
  for `kookworkshop` would see `workshopkoken` proposed again once five people had added it.

No AI anywhere: string rules and SQL (invariant 1).

## The review screen (`App\Filament\Pages\OfflineIdeaReview`)

Modelled on [match-review.md](match-review.md): one idea at a time, the most-written first, with
keys (**A** approve, **R** reject, **S** skip, ignored while typing). The form starts from the
spelling most people used; the reviewer rewrites it as a general idea (no names, dates or places
that point at one person). Tags are the same strings products carry (`interest:cooking`,
`recipient:father`, `occasion:birthday`), so a brief meets both the same way. A rough price band is
optional: under 25, 25 to 75, over 75 euros. Rough on purpose, because "a spa day" costs anything
from 40 to 400.

**Approving needs at least one tag.** An idea is matched to a brief by its tags alone, so an untagged
one would be approved into nowhere. Approved ideas are listed below the queue with **Edit** (change
wording or tags, or **Withdraw**, which rejects it).

## Where approved ideas show (`App\Services\Ideas\OfflineIdeaPicker`)

- **Find a gift results**, as a small **Ideas without a shop** block (NL "Ideeën zonder winkel", FR
  "Idées hors boutique", ES "Ideas sin tienda") under the product cards, with an explanation behind
  the info icon.
- **This or that results**, on the giver's page only. The person choosing through their own link is
  describing themselves, not shopping, so their page gets none.
- **Persona pages** (2026-09-26), under the budget tabs, from the persona's brief
  ([persona-budgets.md](persona-budgets.md)). For someone who has everything, an approved idea whose
  wording is done or used up ("a cooking workshop") fits whatever it is tagged with
  ([has-everything.md](has-everything.md)).
- **Gift landing pages** (2026-09-26), under the cards, from the page's brief, since they draw the
  one Find-a-gift results page ([find-a-gift.md](find-a-gift.md)).

At most three (`giftcoves.offline_ideas.shown`), in the brief's market only. An idea shows when it
shares something with the brief: an interest (weighs 2), who it is for or the occasion (1 each).
It is left out when it carries an interest the brief avoids, or when its price band cannot meet
the budget (overlap, not containment: a Mid idea fits a 20 to 40 euro brief).

## Add to my list

"Add to my list" posts `source=manual, idea_id` to `POST /list-items`, the same path and the same
`ItemSaver::saveManual()` as typing an offline item, and lands on Find a gift's chosen person's
list when there is one. **The wording is read from the idea on the server**, never taken from the
request, and only an approved idea can be added.

Signed out, the press goes through the existing save-intent and sign-in flow: `/save-intent` now
accepts `idea_id`, and `PendingSave` adds the idea's wording to the default list at sign-in. That
flow has refused `manual` since it was built, because a signed-out free-text channel has no owner.
An idea id is not free text: the wording comes from a reviewed row at replay, and an idea withdrawn
in the meantime is simply not added.

## Decisions taken without the owner

- Items with a link are not counted (see above). Offline items with a link are mostly shops.
- A waiting idea that drops below five people is deleted; approved and rejected ones are kept.
- The reviewer sees one example spelling and the count. Without a spelling there is nothing to
  write the wording from; it is emptied once decided.
- Approving requires a tag.
- The price band is three rough bands, set by the reviewer, not a price.

## Not yet

- Word order and synonyms: "workshop koken" and "kookworkshop" are two keys. A reviewer who sees
  both can approve one and reject the other; merging keys would need its own screen.
- Other surfaces (gift landing pages, shared lists) do not show ideas yet.

## Files

- `app/Services/Ideas/IdeaKey.php`, `OfflineIdeaCounter.php`, `OfflineIdeaPicker.php`
- `app/Jobs/CountOfflineIdeas.php`, `routes/console.php`
- `app/Models/OfflineIdea.php`, `app/Enums/OfflineIdeaStatus.php`, `app/Enums/IdeaPriceBand.php`
- `database/migrations/2026_09_27_000200_offline_items_become_gift_ideas.php` (a new table only)
- `app/Filament/Pages/OfflineIdeaReview.php`, `resources/views/filament/pages/offline-idea-review.blade.php`
- `app/Http/Controllers/GiftController.php`, `TasteController.php` (the `offlineIdeas` prop),
  `WishlistItemController.php` and `SaveIntentController.php` (`idea_id`),
  `app/Services/Wishlist/PendingSave.php`
- `resources/js/Components/OfflineIdeas.tsx`, placed in `Pages/Gift/Wizard.tsx` and `Pages/Gift/Taste.tsx`
- `lang/*/site.php` (`gift.offline_ideas.*`, `help.find_offline_ideas`), `resources/js/Pages/Help.tsx`
- `resources/legal/{en,nl}/privacy.md`

## Tests

`tests/Unit/IdeaKeyTest.php` (spellings that meet, what never becomes a key) and
`tests/Feature/OfflineIdeasTest.php`: the five-owner threshold in people, which items count, market
scoping, waiting ideas dropped and decided ones kept, an approved wording not proposed again,
nothing shown before approval, nothing identifying in the page props, budget, This or that, add to
list signed in and through sign-in, an unapproved idea cannot be added, and the review screen
(approve needs a tag, the spelling is forgotten, reject).
