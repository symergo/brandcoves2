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
| | `/{market}/search` | Search | Zoek | Rechercher | Buscar |
| | `/{market}/discover-cove` | Discover | Ontdek | Découvrir | Descubrir |

Behind **Organise**: the Gift Finder, Lists, Secret Friend. Behind **Discover**, keeping the Cove
names that were briefly the top-level labels:

| Route | en | nl | fr | es |
|---|---|---|---|---|
| `/{market}/daily` | Daily Cove | Cove van de dag | Cove Quotidienne | Cove Diaria |
| `/{market}/surprise` | Surprise Cove | Verrassingscove | Cove Surprise | Cove Sorpresa |
| `/{market}/guides` | Idea Cove | Idee Cove | Cove d'Idées | Cove de Ideas |

**Every verb is also a destination.** Each points at a hub that explains its section —
`/gift-cove` already did, `/discover-cove` was built for this. A menu whose handle goes nowhere
hides the one page written to explain the tools behind it, which is the page that exists *because*
they are not self-evident.

**The label is a link and the chevron is a separate button** (`NavMenu.tsx`). One control cannot do
both without guessing, and the usual guess — navigate on click, open on hover — has no keyboard or
touch equivalent at all. Escape closes and returns focus to the chevron rather than dropping it to
the top of the document.

**The phone flattens rather than nesting.** A dropdown inside an already-open panel is a second
thing to open to reach a link that would have fitted on screen anyway.

**Icons only in the Discover menu.** Its three entries are destinations with distinct characters and
`CoveIcon` gives each one a mark; the Organise entries are tools whose names already say what they
are, and nine icons in a dropdown is a contact sheet.

**Editorial leads, tools follow.** The two Cove surfaces are the only things in the header that are
*ours*; search, gifting and Surprise are ways of querying a catalogue that every competitor also
has. Putting Search first made the site look like a search box with some extras attached, which is
the version of it that has no reason to be visited twice.

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
- `lang/*/site.php` — `nav.daily`, `nav.coves`, `nav.give`, `nav.sign_out`, `nav.admin`

## See also

- [wishlists.md](wishlists.md) — why lists exist before accounts do
- [localisation.md](localisation.md) — the market switcher and why it reloads the page
