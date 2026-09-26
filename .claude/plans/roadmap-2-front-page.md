# Roadmap step 2: the front page and the navigation

Strategy: [docs/strategy.md](../../docs/strategy.md), section 6. Written 2026-09-26.

**Status 2026-09-26: built** ([docs/features/homepage.md](../../docs/features/homepage.md)).
Changes from the design below: the search card left the page too (the header searches everywhere);
the Coves band is the existing random shelf as six cards rather than themed picks; Help moved to
the footer; the illustration is `SharedCoveIllustration` ("A2, round 3"), approved by the owner.

## Goal

The homepage answers three questions (what is this, why should I care, what can I do now) and gets
the visitor into the system; the header stops presenting a dozen features as equals.

## Where we start

- `resources/js/Pages/Home.tsx` + `HomeController`, in order: hero ("Give better. / Get what you
  actually want.", buttons to `/lists?new=mine` and `/discover-cove`, `HomeIllustration`),
  `SearchCard`, the list wizard ("Make a new list", `WizardOffer`), today's Cove (4 picks),
  `CoveSubscribe`, `RecentlyViewed`, "More Coves" (10 random published non-daily Coves, cached an
  hour). Copy under `home.*` in `lang/{en,nl,fr,es}/site.php`. History in
  `docs/features/homepage.md`.
- Header in `resources/js/Layouts/SiteLayout.tsx` (`organise`, `discover`, `nav` objects, dropdown
  `Components/NavMenu.tsx`): **Make a list** → `/gift-cove`; **Find a gift** (Gift Whisperer,
  search, Daily, Surprise, Shop Smarter, Gift Coves, All Coves, Ask others); **Help**; right side
  bell, My lists, `AccountMenu`. Mobile: full-screen panel and `AccountSheet`.
  `docs/features/navigation.md` is partly stale.
- Every destination the new page needs exists: create a list (`/lists?new=mine`), `/coves`,
  `/gift`, today's Cove, `/gift-cove/how-it-works`, `CoveSubscribe`.

## Design

### Homepage, top to bottom (the owner's structure)

1. **Hero.** Headline "Find things worth giving, getting and sharing." One sentence: an open place
   to discover products from shops, brands and independent sellers, and save them in Coves you can
   keep, share or give. Buttons **Create a Cove** (`/lists?new=mine`) and **Explore Coves**
   (`/coves`). Under them: *Search anything · Add anything · Share anything*. The search card stays,
   directly under the hero: it is the "search anything" of that line.
2. **Three ways in**, three cards: 🎁 Looking for a gift? → Gift Whisperer · ❤️ Building a wish list?
   → create a Cove · ✨ Just browsing? → `/coves`.
3. **From anywhere.** Search it / add it from any website / scan it in a shop, with a small inline
   SVG of sources flowing into one Cove. Generic labels, owner's decision: "Big online shops ·
   Independent makers · The shop round the corner". Button **Add something to GiftCoves** → the
   default list's add panel open (`AddProduct` already opens by default on an empty list).
4. **Coves.** "See what other people are collecting": themed cards. Until public lists exist
   (step 6) these are editorial Coves; the controller picks one per theme from published personas
   and guides by `gift_tags`/theme, falling back to the current random selection.
5. **Daily.** Today's Cove and `CoveSubscribe`, as now, moved down.
6. **Trust.** "Open. Useful. Transparent." Two sentences (sources; commission does not change what
   you pay) and **How GiftCoves works →**.
7. **Final call.** "Start your first Cove." **Create a Cove**.

The list wizard and recently viewed leave the homepage. The wizard stays where "Create a Cove"
leads.

### Words

- New `home.*` keys in all four languages; em dashes out (house style). "Cove" is the brand word
  here; the SEO `title` and `seo_description` keep leading with the searched words ("wish list",
  "gift ideas") per the naming rule, and stay inside the 48/155-character budgets
  (`LocalisationTest`).
- The commission sentence must match the disclosure elsewhere (`amazon-compliance.md`, required
  disclosures) and not promise more.

### Navigation

- Desktop: **Discover** (search, today's Cove, surprise, ask others) · **Coves** (`/coves`) ·
  **Gifts** (Gift Whisperer, gift ideas, guides) · **How it works** (`/gift-cove/how-it-works`);
  right side: bell, **My Coves** (`/lists`), account.
- Secret Santa, group lists and occasions live inside My Coves (the `AccountSheet` rows already
  exist). Help moves to the footer and the account menu.
- Mobile panel and `AccountSheet` follow the same four plus My Coves.
- `navigation.md` rewritten to what is true.

## Decide before building

Decided 2026-09-26:

1. "Create a Cove" works **without an account**, as `/lists?new=mine` does today.
2. **"My Coves"** is the label in all four languages.
3. A **new hero illustration**, shown to the owner for approval before it is built.

## Files

- Change: `Pages/Home.tsx`, `HomeController` (theme cards), `Layouts/SiteLayout.tsx`,
  `Components/NavMenu.tsx`, `Components/AccountSheet.tsx`, `Components/AccountMenu.tsx`,
  `lang/*/site.php` (`home.*`, `nav.*`).
- New: `Components/SourcesDiagram.tsx` (inline SVG, both themes).
- Docs: `homepage.md` (a new dated section with the reasoning), `navigation.md` rewritten,
  `INDEX.md`.

## Verification

- `HomeTest` (or the existing homepage test), `LocalisationTest` (budgets, every key in four
  languages), a nav test that every header link resolves (200 or redirect).
- By hand: the page at 375 px and desktop, light and dark; signed out, click Create a Cove and add
  a product end to end.
