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

Changed 2026-08-10. The nav is five entries:

| Label (nl) | Route | |
|---|---|---|
| Cove van de dag | `/{market}/daily` | the editorial that changes every day |
| Alle Coves | `/{market}/guides` | the editorial index |
| Zoeken | `/{market}/search` | |
| Geven | `/{market}/gift-cove` | |
| Verras me | `/{market}/surprise` | |

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

**Gifting is labelled with the verb, not the surface.** `nav.give` — Geven, Gifting, Offrir,
Regalar — rather than `nav.cove` ("Geschenk Cove"). Between "Zoeken" and "Verras me" it reads as a
thing you do, which is the state someone buying for another person is actually in. The surface keeps
its name everywhere it is named, including the account menu.

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
