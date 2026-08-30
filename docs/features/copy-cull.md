# The copy cull — 2026-09-03

A pass over every page for text that repeats what is already on screen, or that renders nowhere at
all. **45 keys removed across four languages, one added.**

This is written down because the deletions are invisible in a diff of the *pages* — most of them are
lines vanishing from `lang/*/site.php` — and because "why is there no hint under this field" is a
question somebody will ask.

## How the dead strings were found

Every leaf key in `lang/en/site.php`, grepped against the codebase, discounting keys built by
concatenation. That last part is the whole difficulty: `registry.types.*`, `gift.interests.*`,
`gift.vibes.*`, `nav.countries.*`, `ask.status.*` and `reminders.lead_*` are all reached as
`__('site.x.'.$value)` from an enum or a job, and `lists.about_*`, `lists.claim_privacy_*`,
`lists.empty_*`, `search.view_*`, `coves.*`, `discover.modes.*`, `discover.why.*` and `gift.step_*`
from template literals. None of those appear literally anywhere and none of them are dead.

**Anyone repeating this: find the dynamic prefixes first.** A naive grep would have deleted about
forty live strings, and the failure would have shipped as the string key printed on the page —
`useTranslations` returns the key when the lookup misses, which is a visible bug on the frontend but
silent in `__()` on the server.

## What went, and why

**43 keys nothing rendered.** The largest group was `lists` (12): `save_to_mine`, `invite_hint`,
`invite_collaborator`, `collaborator_invited`, `share_heading`, `not_claimable`, `price_was`,
`relationship`, `occasion`, `no_recipient`, `enable_sharing`, `manual_hint` — mostly surfaces that
were rebuilt around a share link and left their old copy behind. Then `home` (6 gifting-band strings
from a band that no longer exists), `nav` (4, including `scan` — see below), `search` (3: `price`,
`price_min`, `price_max`, orphaned when the price facet left `FilterPanel`), `handover` (2),
`pledges` (2), and one each in `registry`, `brand`, `suggestions`, `votes`, `auth`, `recipients`,
`santa`, `quiz`, `gift`.

**`lists.friends`, `friends_empty`, `follow`, `unfollow`, `followed` were kept.** They are unused
too, but they read as a feature someone intends to build rather than as residue. Deleting them would
throw away a decision, not a leftover.

**Two strings that rendered, and should not have.**

- `search.empty_hint` — "try a shorter search term, or check the spelling" — was the empty state of
  the **brand index**, a page with no search field and no term. Replaced by `brand.index_empty`, the
  one key this pass added: the intro above it promises every brand in the catalogue, so that state
  needs a sentence saying there are none, not no sentence at all.
- `nav.scan`. [barcode-scanner.md](barcode-scanner.md) claimed it was kept "for that page's own
  use"; `Scan.tsx` titles itself from `scan.title`. The doc has been corrected.

**Four things visible on screen.** See [homepage.md](homepage.md) for `home.intro`,
`home.search_label` and `home.gifting_lists_hint`, and [wishlists.md](wishlists.md) for the Cancel
button in the add panel.

## The rule this pass applied

A line earns its place by saying something the thing next to it does not. A hint under a control
named *Lists* that says you can keep lists is the control's name at greater length; a hint under an
optional email field that says the address is only used to reply answers a question the visitor is
actually asking at that moment. The first goes, the second stays.

The corollary is that **an `aria-label` and a placeholder on the same field are one string, not
two.** `home.search_label` said "a product or a brand" for eight days after the placeholder had been
rewritten to offer a barcode scan. Two strings for one control drift the moment either is edited,
and only one of them is visible to the person editing it.

## What was deliberately left

`LocalisationTest::every_language_defines_every_key()` uses `lang/en/site.php` as its reference, so
removing a key from all four files is safe and removing it from one breaks the suite — which is the
guard that makes a pass like this survivable at all. It does **not** catch a key removed from all
four while still being rendered; `tsc` does not either, since `t()` takes a string. The full suite
plus a page-by-page read is what stands behind these deletions.
