# Navigation and the account menu

The site shell: what the header offers, and the two things it did not.

## You could not sign out

`POST /{market}/logout` has existed since magic links went in. Nothing on the site ever linked to
it. Signing out meant clearing cookies by hand.

On a site that holds gift lists, delivery addresses and Secret Santa pairings, that is not a missing
convenience. A phone handed to someone across the table, a shared laptop, a machine in a hotel
lobby — the account stays open, and every list on it is one tap away. Invariant 4 says the owner of
a list must never learn what has been claimed from it; leaving them signed in on somebody else's
device hands them the whole thing from the other direction.

`AccountMenu` also answers "am I signed in?", which the header could not previously be asked. The
only difference between the two states was whether a bell icon was present, and a visitor does not
read the absence of an icon. It now shows a name, or the part of the address before the `@` when we
never asked for one — that is what people recognise as themselves, and it fits in a header.

Signing out is a `<button>` posting through Inertia, not a link. A `GET` that ends a session can be
fired by any `<img>` tag on any page on the internet.

The mobile panel carries the same items. A phone is the device most likely to be handed to someone
else, so it is the last place this should have been missing.

## Sign in is a button

It was a text link between the market switcher and "My lists", reading as footer furniture. Signing
in is the one thing we want from a visitor who has built lists in a cookie — the lists are
anonymous-first and live in that browser until they do.

## What the header offers, and in what order

Changed 2026-08-15. **Three verbs, two of which open.** The header says what you came to *do*; the
surfaces live underneath.

| Top level | Route | en | nl | fr | es |
|---|---|---|---|---|---|
| | `/{market}/gift-cove` | Organise | Organiseer | Organiser | Organizar |
| | `/{market}/discover-cove` | Discover | Ontdek | Découvrir | Descubrir |
| | `/{market}/search` | Search | Zoek | Rechercher | Buscar |

Behind **Organise**: the Gift Finder, Lists, Secret Friend. Behind **Discover**, keeping the Cove
names that were briefly the top-level labels:

| Route | en | nl | fr | es |
|---|---|---|---|---|
| `/{market}/daily` | Daily Cove | Cove van de dag | Cove Quotidienne | Cove Diaria |
| `/{market}/surprise` | Surprise Cove | Verrassingscove | Cove Surprise | Cove Sorpresa |
| `/{market}/guides` | Shop Smarter | Slim kopen | Acheter malin | Comprar mejor |
| `/{market}/gift-ideas` | Gift Coves | Cadeau Coves | Coves Cadeaux | Coves de Regalo |
| `/{market}/coves` | All Coves | Alle Coves | Toutes les Coves | Todas las Coves |

### Brand Coves and Shop Coves are built and withheld, 2026-08-29

Two more Cove types were named, built and then **deliberately kept out of this menu for now**.

| | Route | en | nl | fr | es |
|---|---|---|---|---|---|
| | `/{market}/brands` | Brand Coves | Merk Coves | Coves Marques | Coves de Marca |
| | `/{market}/shops` | Shop Coves | Winkel Coves | Coves Boutiques | Coves de Tienda |

**Brand Coves** is `/brands`, which already existed. **Shop Coves** is `/shops`, which did not —
see [shop-coves.md](shop-coves.md).

Withheld, not removed, and the distinction matters to whoever restores them. Both pages are live and
indexed, both are listed on All Coves, and their icons (`brand`, `shop` on `CoveIcon`), their
`nav.*_coves` labels and their `nav.hint_*` lines are all written in four languages. Restoring them
is putting two entries back into `discover.items`, between Gift Coves and All Coves — the block is
marked with a comment saying so.

The footer keeps its `/brands` link under `brand.index_title`, its own name, rather than under
`nav.brand_coves`. With no header entry to agree with, a footer link is not the place to introduce a
name the rest of the site is not yet using — and `/shops` has no footer link at all while it is
withheld.

### The menu names the Cove *types*, 2026-08-29

It used to name three surfaces — Daily, "Idea Cove" and Ask others — and that was wrong in three
separate ways at once.

**"Idea Cove" was the article archive wearing the name of the whole.** `/guides` is one of three
shapes a Cove takes, and it was the only one in the header carrying the bare word. A reader learning
the vocabulary from the header learned that a Cove is a long read, which is a third of the truth.

**The persona shelf was reachable from nothing.** `/gift-ideas` has existed since personas shipped
and appeared in no menu, no hub and no footer — only in the sitemap. It is the answer to "who am I
shopping for", which is the question most visitors actually arrive with.

**There was no page holding all of them.** Three kinds, three indexes, and nowhere showing that they
are three shapes of one thing. `/coves` is that page; see [all-coves.md](all-coves.md).

So the menu is now one entry per kind, named for the kind, with All Coves under them. Every entry
carries a `hint` — `NavMenuItem` has had the slot since it was written and nothing used it, and five
labels differing by one word (Daily, Surprise, Theme, Gift, All) cannot be told apart on first
opening. The hints are deliberately short: this is a dropdown, and the hub is one click away for the
argument.

**Ask others stays, below the Coves rather than among them.** It belongs under Discover — it is a
way of finding something when you cannot describe it well enough to search for — but its content
comes from other visitors rather than from us. Listing it as a fourth Cove type would say we
published it.

**`nav.coves` was retired, not repointed.** The footer, the front page and the Discover hub all
labelled `/guides` with it, and the footer's own comment said "same name as the header uses". Leaving
the header on "Inspiration Coves" while three other surfaces said "Idea Cove" would have been two
names for one page — the exact confusion the Cove naming pass in
[localisation.md](localisation.md) set out to remove — so all four moved to `nav.inspiration_coves`
together and the old key is gone.

**Why "Inspiration Coves" and not "Theme Coves".** The entry was briefly called Theme Coves, named
after what the page is organised *by*. That is a fact about our filing system, not about what the
reader gets: `/guides` holds shopping inspiration and buying guides, and "theme" describes neither.
The qualifier now names the value, which is also what makes it survive translation — Inspiratie
Coves and Coves Inspiration mean the same thing to a reader, where a literal "Thema" only means
something to somebody who already knows how we sort our content.

The key moved with it. `nav.theme_coves` holding "Inspiration Coves" would be the same two-names
drift the paragraph above is about, one level down — so `theme_coves`, `hint_theme_coves` and the
page's `coves.theme_*` block all moved with the name, and `CovesController`'s section key changed to
match, because the page builds its copy keys from it (`coves.{key}_heading`).

### It is "Shop Smarter" now, and it left the Cove family, 2026-09-01

"Inspiration Coves" named a mood. The shelf holds buying guides, seasonal guides and advice
articles — what to look at, what makes the difference, what it should cost — and somebody looking
for buying advice does not click on inspiration. The paragraph above chose the qualifier for naming
the *value* rather than the filing system; this goes one step further and says what the reader gets.

**It has no "Cove" in its name, and that is the deliberate part.** Gift Coves, Brand Coves and Shop
Coves are each a *shape* — a gift, a maker, a shop — with "Cove" as our word for what we make of
one. This shelf is not a shape but a promise, so the naming pattern that fits its siblings does not
fit it. It is now the only entry in the Discover menu without the word, and on `/coves` the only
band whose heading does not carry it.

**So it translates in full**, where a Cove name only translates its qualifier: Shop Smarter / Slim
kopen / Acheter malin / Comprar mejor. `nav.inspiration_coves` and `nav.hint_inspiration_coves`
became `nav.smart` and `nav.hint_smart` — `smart` because it is the one word all four keep — and the
`coves.inspiration_*` block became `coves.smart_*`, with `CovesController`'s section key following
for the same reason it followed last time.

The `CoveIcon` stayed `idea` (the lightbulb). It reads as a good idea rather than as an inspiring
one, and swapping the mark a reader has already learned would cost more than the name change buys.

**Two icons were added** to `CoveIcon`: `persona` (one person, head and shoulders — a Gift Cove is
built around somebody rather than a date, and that is the single fact separating it from the book
beside it) and `all` (a two-by-two grid, the only mark here depicting an arrangement rather than a
thing, because All Coves is not a kind of Cove but the set of them). `CoveIllustration` did **not**
grow to match: it draws the four homepage cards and now takes a narrower `CoveSceneKey`, so a scene
for a menu row nothing renders at 160px is a compile error rather than a blank card.

> **The hub was extended on 2026-08-30; the footer still has not been.** `/discover-cove` now carries
> a fifth card for the persona shelf and a band listing the personas themselves — see
> [gift-personas.md](gift-personas.md).
>
> Its intro no longer counts. It said "three ways" while the cards were four, the persona card made
> it five, and a number in the copy is a promise the card row has to keep — broken twice now, so it
> is gone rather than corrected to five. **`discover_cove.seo_description` still says "three"** and
> still enumerates the original three; it was left alone deliberately, because rewriting a meta
> description changes what shows in a search result and that is a decision rather than a typo fix.

**Every verb is also a destination.** Each points at a hub that explains its section —
`/gift-cove` already did, `/discover-cove` was built for this. A menu whose handle goes nowhere
hides the one page written to explain the tools behind it, which is the page that exists *because*
they are not self-evident.

### The Discover hub lists the Coves themselves

Added 2026-08-15. Under the three cards, `/discover-cove` now lists up to twelve published Coves for
the market, newest first, with a link to the full archive.

The hub still shows **no numbers** — a hub that totals things is the catalogue-counter mistake from
[homepage.md](homepage.md) in a new place. Titles are a different matter. Two of the three cards
describe something the visitor cannot see from here — today's edition and a Surprise both have to be
opened — but the archive is the one whose value *is* its contents. "Long reads around a theme" sends
a reader one click away to find out whether any of them is about anything they care about; a dozen
titles answers that on the page.

Twelve rather than sixty: enough that the range is obvious, then a link. A hub that lists everything
is a second copy of `/guides`. The band reuses the front page's `home.coves_*` copy keys, so the two
pages describing the same shelf cannot drift into describing it differently. `DiscoverCoveHubTest`
covers the two failures that are invisible on a page that otherwise looks right — a draft appearing,
and another market's Coves appearing.

**The label is a link and the chevron is a separate button** (`NavMenu.tsx`). One control cannot do
both without guessing, and the usual guess — navigate on click, open on hover — has no keyboard or
touch equivalent at all. Escape closes and returns focus to the chevron rather than dropping it to
the top of the document.

**The phone groups rather than nesting.** A dropdown inside an already-open panel is a second thing
to open to reach a link that would have fitted on screen anyway, so nothing in the phone menu
collapses. That was read as licence to *flatten*, though, and the panel rendered
`[organise, ...organise.items, discover, ...discover.items, ...nav]`: fourteen links in one column at
one weight, where "Organise", "Secret Friend" and "Feedback" are the same size and the same distance
apart. Nothing in it said which two were hubs, which four belonged under the first, or that the list
had an end — the structure the wide header spends two menus expressing was thrown away at the exact
width where a reader most needs it.

So, since 2026-08-31: the hub is a heading you can press, its surfaces are indented under a rule,
and the loose links, the account block and the market switcher are three groups below them. Same
links, same order, still one tap to any of them, still nothing to expand.

- **The heading is a `Link`, not a label.** Same argument as `NavMenu`: the hub page exists because
  the section is not self-evident, and a heading that cannot be pressed puts it out of reach on the
  one device with no hover to reveal anything.
- **The hints survive on a phone.** Four Cove entries differing by one word cannot be told apart the
  first time, which is why `nav.hint_*` exists at all; a narrow screen does not make that easier.
- **"You are here" needed a second predicate.** `isCurrent` compares paths, so a product opened from
  Search still reads as Search — but the three list views differ only by `?view=`, so a path match
  lights all three at once and an unstripped comparison lights none. `isHere` is exact for an href
  carrying a query and prefix-matching for everything else.
- **My Lists is no longer repeated.** It was in the panel twice: once inside Organise and once in
  the account block, four rows apart, which reads as two destinations. The wide header still carries
  its own copy beside the account menu, for the reason under *What stays out of the menu* below.

**Both menus carry icons** — `CoveIcon` in Discover, `ToolIcon` in Organise, drawn on one grid at one
stroke weight so a row of them reads as a set.

This used to say *icons only in Discover*, on the grounds that the Organise entries are tools whose
names already say what they are and that a screenful of marks is a contact sheet. Two things were
wrong with it. On a phone the two menus are one panel, so an iconed block above a bare one reads as
one finished list and one unfinished one. And three of Organise's four entries are the same noun with
a different adjective — My Lists, Shared Lists, Group Lists — which is precisely where a mark in the
margin earns its place.

`ToolIcon` gained a tenth key, `shared`, for the middle one: the share glyph, one node joined to two.
Not two people, because `collab` is two people and means the co-givers on a group list, which is the
row directly below it. The other three reuse `wishlist`, `collab` and `santa` exactly as the Gift Cove
tool pages draw them, so the header cannot drift from the pages that teach them.

**Editorial leads, tools follow.** The two Cove surfaces are the only things in the header that are
*ours*; search, gifting and Surprise are ways of querying a catalogue that every competitor also
has. Putting Search first made the site look like a search box with some extras attached, which is
the version of it that has no reason to be visited twice.

Changed 2026-08-29: **Search moved from the middle to the end**, so the order is Organise, Discover,
Search. Search between the two verbs split them, and it was the one entry that reads as a control
rather than a section — sitting between two menus it also broke the run of chevrons. Last is where a
visitor looks when the two curated routes did not have what they came for, which is what Search is
for. The phone panel follows the same order, with Search and Feedback in a group of their own below
the two sections.

**The header now uses the Cove names.** It was the last surface still calling `/guides` "Guides"
(`nav.guides`, "Koopgidsen") and `/daily` "Daily Picks" ("Dagtips"), while the homepage, the
subscription mails, the OG cards and the page titles all said Cove. Two names for one page is the
exact confusion the naming pass in `localisation.md` set out to remove, so the footer link to
`/guides` moved to the same key. Per that rule the noun stays and the qualifier translates:
Daily Cove / Cove van de dag / Cove Quotidienne / Cove Diaria, and All Coves / Alle Coves /
Toutes les Coves / Todas las Coves.

**The labels went noun → Cove → verb in one day, and the round trip is the useful part.** They were
briefly all Coves — Gift Cove, Search Cove, Daily Cove, Surprise Cove, Idea Cove. One consistent
system, and it failed on two counts. Five labels sharing a suffix are harder to scan than five
different words, because the eye must read past a repeated token to reach the one that
distinguishes. And it named *surfaces* on a site where the sections overlap by design — Search, the
gifting tools and the Coves all end in products — so the labels described where you would be rather
than what you were trying to do.

Verbs fix both, and the Cove names survive one level down where they are doing genuine work:
distinguishing three things that really are three destinations.

**`nav.give` is deleted, not renamed.** It and `nav.cove` pointed at the same page,
`/{market}/gift-cove` — the header said "Gifting" and the account menu said "Gift Cove". That is the
same two-names-for-one-surface defect the naming pass in [localisation.md](localisation.md) was
written to remove, and it had already drifted: Spanish read "Cove de Regalos" against French's "Cove
Cadeau". `nav.cove` remains as the surface's *name* in the account menu, while the header label is
the verb — the page is called one thing, and the door to it is labelled with what you do inside.

**Secret Santa is Secret Friend** (nl *Geheime Vriend*, fr *Ami Secret*, es *amigo invisible*,
unchanged because it was already exactly that). Santa dates the feature to December; the draw works
for a birthday, a team leaving do or a family in July. Spanish had the better name all along. Only
the copy moved — the routes, tables and `SecretSanta*` classes keep their names, because
`/{market}/santa/{group}/join/{token}` is a URL organisers have already sent to people.

**Scan is not in the header.** It is a way of entering a query, not a section — see
[barcode-scanner.md](barcode-scanner.md).

## The header says where you are

Entries render identically, so the nav said nothing about which page you had arrived at. The
sections overlap by design — Search, the Gift Cove and Daily Picks all end in products — so without
a mark, moving between them feels like being moved rather than moving.

`aria-current="page"` plus an underline, matched on prefix so a product opened from Search still
reads as Search.

## The login page 500'd for anyone already signed in

Laravel's `guest` middleware sends an authenticated visitor to `route('home')`. Every route here is
prefixed with `{market}`, so that call cannot be generated without one — `UrlGenerationException`,
served as a 500.

The mirror case was fixed long ago: `redirectGuestsTo` resolves the market from the request, and the
comment above it explains exactly this hazard. `redirectUsersTo` was never given the same treatment,
and nothing ever opened the login page while signed in, so it sat there. Both now share one market
resolver.

Reaching it takes no ingenuity: a bookmarked login page, a stale "Sign in" link, or a magic-link
email opened after signing in on another tab.

Found by `tests/Feature/PageSmokeTest.php`, which opens every reachable page twice — once signed
out, once signed in — and asserts only that the response is not a 5xx. A redirect is an answer, and
so is a 404 from a surface with no content generated yet; a server error never is. It is a low bar,
and it is the bar the last several regressions here failed to clear: a component used but not
imported, a helper called with the wrong argument, a class an autofix had dropped from the imports.
Each shipped green, because every unit beneath the page was tested and nothing opened the page.

## What stays out of the menu

**"My lists" keeps its own place in the header.** Lists work before signup — that is the whole
design — so a visitor with no account must reach them without opening a menu that is about having an
account.

**The bell only appears when something is unread.** A permanent icon that is almost always empty
teaches people to ignore it, and then the one that matters is ignored too.

## Files

- `resources/js/Components/AccountMenu.tsx`
- `resources/js/Layouts/SiteLayout.tsx`
- `app/Http/Controllers/Auth/MagicLinkController.php` — `logout()`
- `bootstrap/app.php` — `redirectGuestsTo` / `redirectUsersTo`, both market-aware
- `tests/Feature/PageSmokeTest.php` — every page, opened signed out and signed in
- `lang/*/site.php` — `nav.daily`, `nav.coves`, `nav.give`, `nav.sign_out`, `nav.admin`,
  `nav.account` (the caption over the phone menu's account group)

## See also

- [wishlists.md](wishlists.md) — why lists exist before accounts do
- [localisation.md](localisation.md) — the market switcher and why it reloads the page
