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
(`home.seo_description`, the santa pages, the page templates), not to the pitch. `nav.santa` — `Geheime
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

### And then it was deleted — 2026-09-03

Everything above this line is history now. `home.intro` is gone, along with `home.search_label` and
`home.gifting_lists_hint`, in a pass over the whole site for text that repeats what is already on
screen.

The paragraph was three sentences and seventy words, and every tool it named — lists, sharing, a
group gift, a Secret Friend — is a card in the Organise band a screen below, each with its own line
saying what it is. So it was an index of the page written above the page, and it sat in the one
position that costs the most: between the headline and the search field, pushing the box a
paragraph further down the first screen. The reasoning that put it there was sound and is left
above deliberately — *the tools are what a visitor cannot guess is here* is still true. The cards
say it, and they say it where a visitor can act on it.

The headline and the two CTAs are untouched, and so is `home.seo_description`, which is read by
somebody who has never seen the site and is the one place the pitch still has to be a sentence.

**`home.search_label` went for a different reason: it had become a lie.** It was the search field's
`aria-label`, reading *"Search for a product or a brand"*, while the placeholder beside it had been
rewritten to *"Search for a gift or scan a barcode"* — so a screen reader announced one field and
everyone else saw another. Two strings for one control drift apart the moment one of them is
edited; the field now labels itself with its placeholder, as the search page's already did.

**`home.gifting_lists_hint`** was the zero-state line under the Lists card: *"Keep things for
yourself or share them with others"*, under a card called **Lists**. The card showed its count when
there was one and nothing when there was not — until the band intros went too, below.

### The four band intros went as well — 2026-09-04

`home.organise_intro`, `discover_cove.intro` on the homepage, `home.personas_intro` and
`home.coves_intro`. Each was one sentence between a band's heading and its cards, and each was doing
the same job the cards under it were already doing at greater length: *"Somewhere to keep what you
want, what you are getting other people, and what several of you are buying together"* is the
Organise band's five card titles rewritten as a list.

They cost more than their words. Five bands each opening heading-sentence-cards is a rhythm that
teaches a reader to skip, and on a phone the sentence is two or three lines above the first thing
they can tap. The heading names the band; the cards say what each one is; the sentence in between
had to invent a reason to exist, and what it invented was a summary.

`discover_cove.intro` and `home.coves_intro` still exist — `/discover-cove` opens with the first and
uses the second over its own articles band, and there the sentence sits under an `h1` rather than
between a heading and the cards it describes, with no five-band rhythm around it. Only the two keys
nothing else rendered, `home.organise_intro` and `home.personas_intro`, were deleted.

**The hero's search-help link went with them.** `search_help.link` — *"What can I search for?"* —
sat under the search form, offering to explain a disappointment nobody had had yet: on the front page
that question is answered by typing, and the hero exists to get somebody into the box. The link stays
on `/search`, where it arrives after a result set, and it is now in the footer of every page under a
shorter name. See [search-help.md](search-help.md).

**One card needed a line as a result.** *My lists* was the only card in the Organise band with no
hint of its own when the visitor has no lists yet — it had been leaning on the band intro. It now
carries `home.organise_mine_hint` in that state, the same count-or-description shape the Secret
Friend card uses, and it says the part a first-time visitor is actually unsure about: the list is
private until they send somebody the link.

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

## Making a list, from the Organise band — 2026-09-01

The band was five cards and every one of them was a **door into lists that already exist**: my
lists, shared with me, group lists, Secret Friend, the occasion. Nothing on the front page made one.
Every route to a new list ran through a product first — search, save, and the list appears
underneath — which works for somebody who already knows what they want and not at all for somebody
starting a birthday.

So the band now opens with a **Make a new list** button, between its heading and the cards.

**It asks which kind before it goes anywhere.** The three kinds differ in who may claim, who may
vote and who sees the money ([list-taxonomy.md](list-taxonomy.md)), and none of that is recoverable
from the words *new list*. Choosing is free at this moment and awkward afterwards: somebody who
picks wrong finds out weeks later, when the mechanism they wanted is not on the page. `Lists/Index`
reached the same conclusion for its own create form and shows the same three cards with the same
three sentences — `lists.new_mine_body`, `lists.new_for_someone_body`, `lists.new_group_body` — so
the two surfaces cannot describe the choice differently.

**Each choice is a link to `?new=<kind>`, not a second create form.** `Lists/Index` already reads
that parameter to open its form pre-set to a shape; it was added so the Gift Cove's cards could land
on the thing they had just described. There is one `POST /lists` and one `ListMaker`, and this is a
shortcut into them rather than a parallel path that can drift.

**A disclosure, not a floating menu.** On a phone the band sits near the top of the second screen,
and a popover anchored under the button has nowhere to go but over the cards it is standing above.
A menu that covers what you were about to read is worse than one that moves it down. Escape closes
it.

**Outlined, not filled.** The accent button on this page is the search. Organise is not where the
page asks to be pressed first, and two filled buttons one screen apart is a page with no primary
action.

`lists.make_new` is a separate key from `lists.new_list`, and longer on purpose: on `/lists` the
button sits under a heading that already says *lists*, and here it has to say what it makes.

## A second phone pass — 2026-09-01

Measured at iPhone 13 width against the running app, after the pass described above. No overflow
either time; this was about how much of the screen the page spends on nothing.

- **The headline is `text-3xl` below `sm`.** Two sentences on two lines is the whole shape of it —
  that is what the `<br />` is for. At 36px in a 358px column *both* wrapped, so the reader got
  three ragged lines instead of two whole thoughts. At 30px the English is two lines again. Dutch
  stays at three (*"Iets wat het geven waard is."* is 28 characters and needs about 24px to fit,
  which is not a headline) — but 12px shorter per line, and the step to `text-4xl` now waits for
  `sm` with `text-5xl` from `lg`, so the desktop hero is unchanged.
- **`mt-14` between bands became `mt-10 sm:mt-14`.** 56px is read at arm's length on a desktop and
  at 20cm on a phone, where it is a seventh of the screen; five of those gaps is most of a screenful
  spent on nothing, on the device with the least of it. 40px is still comfortably more than the
  16px between cards *inside* a band, which is what keeps a band a band.
- **The Today card is `p-5` and the Cove cards `p-4`** below `sm`, both back to their old padding
  from `sm` up. 20px on every side of a card that is already the full width of the phone is the
  content column paying twice.

Together with the headline, 4,324px → 4,200px on `be-nl` and 3,258px → 3,114px on `en`. Small, and
the kind of thing that only shows up measured — `node scripts/shots.mjs home` prints the page height
and the overflow report, which is the half that matters.

## The band that was pushing the page sideways — 2026-09-01

Found by running `scripts/shots.mjs`'s overflow report against **production** rather than the local
app, immediately after the phone pass above shipped. Recently searched was **1,103px wide in a
390px viewport** on `en`, 823 on `nl-nl`, 653 on `be-nl`, 463 on `be-fr`. Only `es` was clean, and
only because it has no search history yet.

Two defaults, and either one alone is enough to break it:

- The `<ul>` was a bare `grid`, so the implicit column is `auto`, which sizes to **max-content**.
- A grid item's `min-width` is `auto`, which resolves to **its own content's minimum**.

Between them the row grew to fit *"bluetooth tracker koptelefoon draadloze…"* at full length, and
the `truncate` on the term never had a width to truncate against — it was doing nothing at all. The
fix is `grid-cols-1` on the list (Tailwind emits `repeat(1, minmax(0,1fr))`, which caps the track)
and `min-w-0` on the `<li>` (which lets the item shrink inside it). Verified by injecting exactly
those two rules into the live page before writing them: 1,103px → 390px, and the term truncates to
one 20px line instead of wrapping.

**The reason it survived a mobile pass that was otherwise measured.** The band is served from a
cache `RefreshRecentSearches` writes, and it is conditional on that cache having something in it. A
development database with no search history renders no band, so `node scripts/shots.mjs home`
reported a clean 390/390 page every time it was run — correctly, for the page it was looking at. The
overflow report is only as good as the data behind the page. Where a band is conditional on
production-shaped data, check it against production.

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
- [list-taxonomy.md](list-taxonomy.md) — the three kinds the new-list button asks you to choose between

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

## The gift personas were on every shelf except the front page

Added 2026-09-01. `HomeController::coves()` selects `->articles()` — guide, seasonal and advice — so
a gift persona appeared at `/gift-ideas`, on `/coves`, in the sitemap and in the hreflang set, and
nowhere a first-time visitor would meet one. Not a regression: the band had never existed. On a
market whose only other Coves are advice articles, that made the front page read as a
consumer-rights blog.

**Its own band, above the articles one.** The articles band promises "long reads around a theme" and
prints a monthly search volume on each card; a persona is neither — it is a person to shop for, it
has no search volume, and it is drawn rather than described. Folding them in would have meant the
band could no longer say what its cards are with one name. Placing it *above* is the argument: the page has just
asked who the visitor is shopping for, and a persona answers that question where a buying guide
answers a different one.

Three, not six — the grid is three wide and this page already carries five sections, so one full row
says the shelf exists and "All gift ideas" carries the rest. Ordered by `published_at` like the shelf
itself, which is stamped once at first build and never refreshed by a rebuild; anything else would
reshuffle the front page whenever a persona's products were refreshed, which is movement no reader
could account for. The band does not render on a market with no personas.

The copy is the shelf's copy, in all four locales. Two headings for one thing that read differently
is how a visitor ends up unsure whether they are the same page.

`GiftPersonaTest::a_persona_is_never_served_as_todays_edition` had to be tightened for this. It
asserted `assertDontSee('De kruidenliefhebber')` on `/be-nl`, which was a fine proxy for the NULLS
FIRST trap while the front page showed no personas at all, and became wrong the day it grew a band
for them — a whole-page string search cannot tell the band from the trap. It now asserts the props:
not `today.theme`, and present in `personas`.
