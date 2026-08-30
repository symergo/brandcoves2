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
guess is here: lists, sharing, group giving, Secret Friend, the quiz. It stops at naming them. The
[Gift Cove](gifting-lenses.md) hub exists to explain them, and repeating that explanation on the
front page is how both pages get longer and neither gets read.

### The intro leads with the list, not with the search — 2026-08-16

It used to open *"GiftCoves searches bol, Amazon and hundreds of shops at once"*: a description of
the machinery, and one that positions the site as a price comparison engine. That is a category
where the visitor already has a habit, and we are not the shop they compare against.

It now opens with what the visitor makes — *"GiftCoves is where you make your wish lists and your
gift lists"* — and then what they do with it: share, club together on a group gift, run a Secret
Friend. The search is still directly under the paragraph as a box you can type into, which argues
for itself better than a sentence about it does.

The paragraph opens on the promise — *"Find and give the best gifts, to other people and to
yourself"* — and only then names the tools, one per clause: lists, sharing, a group gift, a
[Secret Friend](secret-santa.md), the [quiz](list-quiz.md). That sentence closed the paragraph in the
first draft and was moved to the front, because a paragraph that opens on *how* is asking to be
skipped by anyone who has not yet been told *what for*.

**Only the product name for the santa feature.** A draft of this rewrite carried *"a Secret Friend
or secret santa"* — the generic term alongside ours, on the theory that *secret santa* is what people
search for. It is not worth it on the front page: it puts two names for one feature in a sentence
whose job is to be scanned, and the recognition it buys belongs to the surfaces built for it
(`home.seo_description`, the santa pages, the copy bank), not to the pitch. `nav.santa` — `Geheime
Vriend`, `Ami Secret`, `Amigo invisible` — is the one name the whole product uses, so it is the one
here.

The Dutch says *"organiseer een Geheime Vriend-**sessie**"* where the other three markets say only
the product name. *Organiseer een Geheime Vriend* reads in Dutch as organising a **person**; the
extra noun makes it the event you organise. Written as one hyphenated compound, not three loose
words — a proper name plus a noun is `Geheime Vriend-sessie` in Dutch, and the loose form is an
anglicism. Only `home.intro` carries it: `nav.santa` and every other santa surface stay
`Geheime Vriend`, because the feature is not called that, only this one sentence needs the verb to
land.

`home.seo_description` is unchanged and still leads with the search: it is read on a results page by
someone who has never heard of us, where "searches bol, Amazon and hundreds of shops" is the part
that distinguishes us from every other list site. The two keys are separate for exactly this reason —
see the note above them in `lang/en/site.php`.

The Dutch second headline moved with it, from *"Jezelf inbegrepen."* to *"Ook aan jezelf."*
*Inbegrepen* is the language of what a price covers; the line is the end of a sentence about
**giving**, and *aan jezelf* carries the same verb as the paragraph under it. The other three markets
keep *"Yourself included." / "À vous aussi." / "También para ti."*, which never had that problem.

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

## What people have been searching for: three, not six

Changed 2026-08-15. The band under the pitch shows **three** terms, in one row at `lg` and stacked
below it.

Six wrapped to a second row and turned a glance at what other people are looking for into a block of
the front page competing with the editorial under it. Three is a sample; six starts to read as a
ranking, which is a claim this band does not make and cannot support — the terms are recent, not
popular.

No two-column step in the grid: with three cards it would always leave one orphan on its own row, and
each card carries four 40px thumbnails with nowhere to shrink to in a narrow column.

`RecentSearches::TERMS` is the single source and the read side slices to it as well as the write
side. Without that, lowering the number would keep showing the old count for up to 75 minutes after a
deploy, because the band is served from a cache the hourly job writes.

## The drawing beside the pitch

Added 2026-08-15. The hero is a two-column band above `md`: the headline, the paragraph and the
search box on the left, and one large drawing on the right.

It is the **only** illustration on the site that contains the logo. `CoveIllustration` and
`ListIllustration` are scenes *about* a surface, drawn in one stroke weight and `currentColor` with
the accent used only as a translucent wash. `HomeIllustration` speaks that same language — it has to,
sitting two bands above both of them, because two illustration styles on one page reads as two
websites — and then puts the real mark inside it, in its real colours, moored in the mouth of a
headland that is the logo's own arc scaled from r=15 to r=60. The tile does not take `currentColor`:
a recoloured logo is not the logo.

Two decisions worth keeping:

- **Hidden below `md`, not stacked.** On a phone the drawing costs roughly a screen of height and
  pushes the search field — the one thing this page wants pressed — under the fold, to say nothing
  the headline directly above it has not already said in words.
- **One object in the bay, not a group.** At this size a crowd of small shapes turns into texture and
  the mark stops being the first thing the eye finds.

A rejected version replaced all seven card illustrations with a `CoveMark` tile — the logo with one
element swapped per section. It is a coherent system and it was the wrong trade here: the section
cards want to show *what a surface does*, which a drawing can and a recoloured logo cannot, and seven
near-identical tiles down one page is a pattern rather than a set of signposts. The mark earns its
place once, at the top, at size.

## On a phone the two card bands lie on their side

Measured 2026-08-30 at iPhone 13 width, against the running app. The page was **4,792px — 5.7
screens** — and `Organiseer` (1,318px) plus `Ontdek` (1,228px) were **54% of it**: nine cards at
226px each, 96px of that artwork, stacked one per row. Roughly three screens of navigation before a
phone reader reached a single product.

The desktop card is right and is unchanged: illustration on top, at size, five and four across. It
is only the one-column stack that fails, because the thing that makes the card work wide — a large
drawing leading each one — is exactly what makes nine of them unreadable tall.

So below `sm` the card is a **row**: the drawing at `h-12 w-16` on the left, title and hint on the
right. 226px becomes 102px, and the page 3,692px — 4.4 screens. The SVGs keep their `160x116`
viewBox and default `preserveAspectRatio`, so the smaller box letterboxes them rather than squashing
them; nothing about the drawings themselves changed.

**`sm:gap-0` is not decoration.** The row needs `gap-4`, and a `gap` set without a breakpoint reset
survives into the `sm:flex-col` card and stacks on top of its `sm:mt-4`. That silently added 16px to
every desktop card — caught by measuring the desktop layout before and after, not by looking at it.

Two smaller phone fixes in the same pass:

- **The search field wrapped.** Three controls on one 390px line left the input about 200px wide, so
  it clipped its own placeholder mid-word — "Koptelefoon, koffiem…" — and the example that tells a
  visitor what the box accepts was the half cut off. The field now takes its own line below `sm`,
  the scanner and submit share the next, and the submit grows to fill.
- **The intro is `text-lg` only from `sm`.** At phone width it ran to seven lines of near-heading
  type and took the first screen by itself, which is the same failure the drawing was hidden to
  avoid — see above.

## Files

- `resources/js/Pages/Home.tsx`
- `resources/js/Components/HomeIllustration.tsx` — the hero drawing, and the only one carrying the mark
- `app/Http/Controllers/HomeController.php`
- `lang/*/site.php` — the `home.*` block

## See also

- [navigation.md](navigation.md) — the header, and why editorial leads it
- [daily-cove.md](daily-cove.md) — the edition this page opens with
- [brand-mark.md](brand-mark.md) — the mark, its palette, and everywhere else it appears
- [search-help.md](search-help.md) — linked from under the hero search box

## The Discover band gained a fourth card

2026-08-16. Ask others sits beside Daily, Surprise and the Coves, because it is a way of *finding*
something rather than a tool for keeping track of what you already chose.

Its sentence comes from `ask.nav_hint` — the same key the Discover hub uses — so the two pages
describing it cannot drift. That is also why `what` is now spelled out per entry in the band rather
than derived from the card's key: three of the four live under `discover_cove.*` and this one does
not, and inventing a fourth `discover_cove` key would be a second copy of a sentence that already
exists.

## The Organise band gained a registry card

The registry is a wish list with an occasion and a date on it, so the card links to that list rather
than to a surface of its own — there is not one, and inventing a URL for what is really a panel on
your own list is how two names for one page start.

It shows the **soonest upcoming** registry, not the newest: a registry is a date people are buying
towards, and last summer's wedding is not the list anybody is still adding to. Past dates are
excluded outright; an occasion with no date still counts, and loses to one that is actually
happening on a day.

**No claim state, ever.** This is the owner's own front page, so the card says what the list is
*for* and never how much of it has been bought — the exact surface where a helpful "2 of 8 bought"
would be added by somebody who had not read invariant #4. `HomeRegistryTest::the_card_carries_no_claim_state`
is the guard.
