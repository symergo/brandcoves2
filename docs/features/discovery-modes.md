---
name: Discovery modes
area: Core / Discovery
status: Removed 2026-09-07
date_added: 2026-08-08
---

# Discovery modes (removed)

The dial page at `/discover/{mode}`: one ranking pipeline reconfigured by a mode profile, with
nine retrievers (popular, fresh, value, curated, keyword, outlier, slots, spectrum, two-tower)
blended by a scorer, a continuous control from "I know what I want" to "surprise me", a
"not for me" reaction that tuned the weights, and an admin resource for the per-mode weights.

Removed on 2026-09-07 at the owner's request. What the 2026-09-06 review found about it is the
background: it was indexable and in the sitemap per mode, but reachable from nothing on the
site; it re-ranked on arrival with the values the server had just used; its "not for me"
dropped a card with no recovery; and the line under the dial printed the scoring weights to
visitors. A door was added the day before, and the decision the day after was that the surface
did not earn its place beside the Daily Cove, Surprise, the guides and the personas, which
answer the same "show me something" with editorial rather than a slider.

## What went

- `App\Http\Controllers\DiscoverController`, the three routes, the sitemap's per-mode listing,
  the `discover` editorial link target, the nav entry and `Pages/Discover.tsx`.
- `App\Services\Discover\*` — engine, registry, ranker, profile, request, result, candidate and
  the retrievers — and their bindings in `AppServiceProvider`.
- `App\Models\DiscoveryReaction` and `ModeProfileRecord`, the `ModeProfiles` admin resource, and
  the two tables, dropped by `2026_09_07_000100_drop_the_discovery_modes`.
- The `discover.*` copy group (45 keys) in four languages, and three test files.

## What stayed

`App\Services\Discovery\*` is a different namespace — catalogue-level signals (trends,
serendipity, freshness, catalogue age) that the Surprise page, the Daily Cove builder and the
charts use. `CatalogueAge` lost its one mode-specific test and kept the rest. `/discover-cove`,
the hub page, is unrelated and stays.

## Find a gift opens with the search card (2026-09-13)

At the owner's request the `/discover-cove` page carries the same `SearchCard` as the home page,
right under its title and intro, before the four cards. Somebody who chose "Find a gift" in the
header most often knows what they are looking for, and the field is the shortest way there; the
Daily Cove, Surprise, the Coves and Ask remain below for the ones who do not.

## After the search: today, the days before, then the map (2026-09-13)

At the owner's request the Find a gift page now reads, top to bottom: the search card, Today's
Cove, a list of the editions before it (a week, newest first, each row a date and a title linking
to that edition's page, with "All editions" to the Daily Cove's archive), and only then the cards
for every kind of Cove. The cards had sat directly under the search, so the map came before any
of the territory; a visitor who liked today's edition now sees at once that there was a yesterday.
The list is `DiscoverCoveController::dailies()`, which is "the newest editions by `drop_date`,
skipping the first", the first being exactly what `today()` shows. The bands below the cards
(Surprise, questions, personas, guides) are unchanged.

## The Whisperer sits under the search card on this hub (2026-09-14)

`GiftWizardCard` is a dozen interest chips and a budget, directly under the search card on
`/discover-cove` — the shape the front page gives the list wizard, and for the same reason: the
search card answers the visitor who knows what they want, and the card under it answers the one who
does not. On a page called "Find a gift" that is most of them. The Whisperer is the first entry in
the menu again the same day; see [navigation.md](navigation.md) for what changed in between.

It is a teaser, not a second wizard. The submit posts the brief to `/{market}/gift`, the same
endpoint the full wizard's own form posts to, so the visitor lands on the Whisperer page at its own
address with the board already on it and there is no lesser result screen to keep in step with this
one. The five questions the chips skip — age, vibe, taste, what to avoid, who it is for — are one
link away, and the controller sends every interest so what the card shows is a layout decision
rather than a payload one.
