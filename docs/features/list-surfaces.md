---
name: List surfaces — saying what a list is
area: Wishlist / Gifting
status: In progress
date_added: 2026-08-29
---

# The screens a list has, and what they were not saying

[list-taxonomy.md](list-taxonomy.md) settles *what the three kinds are*. This one is about the
screens: what a person sees when they open a list of their own, and what a stranger sees when they
follow a link into one. Those two pages decided almost nothing and showed almost nothing, and every
one of them was written when only `mine` was live.

## Two axes, and only one was ever on screen

The kind says **what a list is about**. Sharing says **whether anybody else is involved**. Every
mechanism in the product needs both, and the interface read neither:

- `Lists/Show` displayed a title, a recipient and a shared/private badge. **It never said what kind
  of list you were looking at** — the fact that decides who may claim, who may vote and who sees the
  money.
- `Lists/Index` carried the kind in the **section heading** only. A card read out of context said
  nothing, and in the Shared and Group views there are no sections at all, so it was never said.

### Most lists are private, of every kind

This is the correction the whole pass is built on, and it is easy to get backwards. A `for_someone`
list is usually **solo research** — one person, one present, nobody to coordinate with. A `mine`
list is just as often personal: the default list every account gets is where a bookmark lands, and
it stays private until somebody deliberately shares it.

So a sentence like *"people can claim things off this"* is not merely premature on a private list —
it **describes an audience that does not exist**, on the majority of lists, to the person who would
know best.

`ListKindBadge` therefore splits the two:

| | Reads | Changes when shared? |
|---|---|---|
| the badge | kind | **never** |
| the sentence | kind **and** shared | yes |

The badge holding still is deliberate. A list must not appear to change what it *is* because
somebody was invited to it — that is the whole reason `ListKind` is chosen at creation rather than
derived, and a label that shifted underneath people would undo it from the other end.

### A private list says what sharing would do, and that is the only place it is taught

Each private sentence names the mechanism the list does not yet have. *"Only you can see this —
share it and people can claim them, and you will never see which."*

That second clause is doing real work. A settings panel is a worse teacher than a sentence, because
you have to already suspect a feature exists before you go and open the panel that explains it.

**The clearest case is the quiz.** `ListTools` gates it on `shared && claimable`, and
[list-quiz.md](list-quiz.md) is right that the gate must stay: a quiz publishes what is on the list,
so one over a private list would be a leak that never went through the sharing switch. The
consequence, though, is that the feature invented to solve *"nobody fills in a wishlist"* was
invisible on **exactly the wishlist nobody had filled in**. The gate does not move. A private wish
list now carries one line naming the quiz as what sharing unlocks, and that line is the only place
on the site where somebody with a private list of saved things learns the feature is there.

### The sentences carry no name, on purpose

Every surface rendering one already names the person a line or two above — `Lists/Show` under the
title, `Lists/Shared` in the heading — so interpolating a recipient would say it twice. It also
keeps four languages honest: a name dropped into a sentence needs different grammar in each of them,
and the two markets where it would read worst are the two where the name is already on screen.

## Creating a list: three cards that name the mechanism

The audience chooser was three pills — *For me*, *For someone else*, *Together* — which name **who
the list is about**. That is not the choice being made. The three kinds differ in who may claim, who
may vote and who sees the money, and none of that is recoverable from the audience.

A hint appeared under the group option alone, on the stated grounds that three permanent hints is a
paragraph nobody reads. True of a paragraph; **not true of three cards**, where the sentence is the
thing being compared and the eye reads across rather than down. (`lists.for_group_hint` was deleted
rather than left behind — its content lives in the card body now, and a copy key nothing renders is
the drift this codebase keeps finding.)

This is also the only cheap moment to explain any of it: the choice is free here and awkward
afterwards, and somebody who picks wrong finds out weeks later, when the mechanism they wanted is
not on the page.

**Neither of the first two cards promises an audience.** Most lists of both kinds stay private. Only
the group card does — because a group gift with nobody else on it is not a thing at all, which is
precisely why that kind is chosen up front rather than derived.

## A list shared with me is how I shop for that person

A wish list Anna shared with me is, from where I stand, **the way I buy Anna a present**. That is
the commonest gifting act on the site, and nothing anywhere said so: the card read *"11 items"*, in
a view subtitled *"Lists other people have shared with you"*, which describes the filing rather than
the errand.

Fixed in **copy alone**. The taxonomy does not move — the row still belongs to its owner and still
lives under Shared Lists — because the alternative (recording that I opened a link, attaching it to
a recipient, folding it into my own gift list for them) is a real feature with a real design, and
the wording should not wait for it. See the *opened-link record*, still open, in
[list-taxonomy.md](list-taxonomy.md).

Three changes:

- **The view's subtitle** names the errand: *"This is how you shop for them."*
- **The card** carries *"Claim something for Anna"* — but only on a `mine` list of theirs, which is
  the kind with something to claim. A `for_someone` or `group` list I was invited to is co-giver
  coordination, and its own kind sentence already covers it.
- **The empty state stopped lying.** `lists.empty` — *"You have no lists yet"* — is simply **wrong**
  in the Shared view, where I may own a dozen, and the button under it sent somebody off to build a
  fourteenth when what they came for was a list somebody had sent them. The page already drew this
  distinction for its heading and subtitle; the empty state was the one place it did not.

## Six tabs became four

`ListTools` grew a tab per feature: Share, Quiz, Special occasion, People, Handover, Secret Friend.
Three of those were asking three halves of one question — *who else is looking at this list, and what
are they looking at it for* — and the roster, the one thing a group list cannot work without, was
filed furthest from the button that shares it.

Share, People and Occasion are now one panel with sections. What varies is the sections rather than
the panel: every kind gets the link and the occasion, and a list about somebody else also gets the
people.

**Opening it no longer publishes the list, and that regression is the interesting part.** Pressing
Share used to turn sharing on as a side effect of opening the panel, which was right when the panel
*was* the link — sharing took two presses in two places and people left without the URL. It stopped
being right the moment the panel also held the occasion and the roster: those are things an owner
sets on a list they have **not** decided to share, and a tab that published as a side effect of being
opened is a privacy change nobody asked for, on the page where privacy is the point. The press is now
a button inside the panel, and the two-press objection is answered by it being the first thing in
there.

The manual's steps had to move with it — `registry_step1` said "press Special occasion", naming a tab
that no longer exists. That is the rule about quoting real labels failing exactly as
[gifting-lenses.md](gifting-lenses.md) warns it does, and only a human reading the page catches it.

## "How each one works" is its own page

The manual was the bottom half of `/gift-cove`, a page with two readers who want opposite things: one
is here to *use* a tool and wants the grid and their own lists, the other to *understand* one and
wants the steps. Nine entries of three steps sat underneath what most visits came for.

Splitting it also gives the explanation an address. A section behind a `#manual` anchor cannot be
linked from an email, a support reply or a search result; `/gift-cove/how-it-works` can, and it is
what somebody is looking for when they type "how does the secret friend draw work".

The new page is deliberately **data-free** — no queries, no identity, nothing that differs between
two visitors. The hub personalises and needs an owner; this explains the tools to somebody who has
none of them yet, which is exactly who reads it.

## The front page stopped calling a gift list a registry

`HomeController::registry()` has always looked for `event_type` rather than for a kind, on the sound
reasoning that a registry is not a fourth kind of list. Once an occasion could sit on a list *about
somebody else*, that same query started returning gift lists — and the card would have told somebody
their research about their father was a wedding list of their own.

The card is about **the next occasion** now, which is the more useful nudge anyway: a birthday you are
shopping for beats a registry most people never create. It names the person when the occasion is not
the visitor's own.

The Gift Cove went the other way for the same reason: its `registries` count is now `event_type` **and
`kind = mine`**, because that card is specifically about a registry — your own list, with a date and
an address, that people post things to.

## The phone, 2026-08-29

Reported as three complaints — "the mobile view does not look nice", "something overflows sideways",
"the screen zooms in when I tap a field". The last two are **one bug**, and finding that is what made
the first tractable.

### Safari zooms a field under 16px, and the zoom is the overflow

iOS Safari magnifies the whole page when a focused input has a font smaller than 16px, and does not
zoom back out. The page is then wider than the viewport, so the reader is left scrolling sideways
through a layout that fitted a second earlier. Most text fields here are `text-sm` — 14px, chosen
against a desktop viewport where it is a sensible compact control, and one class below the threshold
that matters on the device these pages are mostly read on.

Fixed once in `resources/css/app.css` rather than in several dozen class lists, which would also have
changed how the same fields look on a desktop, where the zoom does not happen and 14px is right. The
rule is **unlayered**, which is what lets it beat Tailwind's layered utilities without every call
site opting in — the same trick, and the same reason, as the reduced-motion rule above it.

**Not `maximum-scale=1` on the viewport meta**, which is the other well-known fix. It works by taking
pinch-zoom away from everybody, permanently, on every page: a real accessibility loss traded for a
styling problem. The font size is the actual cause, so it is the thing to change.

The same rule sets a 44px floor on those controls. 16px text in `py-2` is a 34px box, which is under
what a finger expects — and the padding is not raised in the utilities because it is right on a
desktop.

### Headings were written once, at desktop size

27 of them, `text-2xl` and `text-3xl` with no step down. 30px on a 360px screen is a title that wraps
to three lines and pushes what it introduces off the fold. They step now, and `main` drops from
`py-10` to `py-6` below `sm`: 40px of nothing top and bottom reads as a page that starts late rather
than one that is well spaced.

### The shared list had six cards before its first product

Self-inflicted, in this same pass: the badge, the owner note, the per-kind intro, a claim-consent
card with a name input, the pot, the progress line and the occasion — each a bordered block, stacked,
above the thing the page is for.

Three of them were not cards at all. The occasion is a **caption** for the page, so it rides up next
to the title as one line — a bordered box for "Birthday · 14 June" was a whole block of the first
screen. Progress belongs under the intro, because "what this page is" and "how much is already
handled" are one thought. And the claim disclosure is a line, not a form: it has to be read before
somebody claims, but the *name field* was asking for something before anybody had decided to give it,
so it appears with the first claim instead.

What is left is one card and a few lines. The owner note and the visitor intro are mutually
exclusive — `hideClaims` implies ownership and the intro is `!isOwner` — so no reader ever sees both.

### Seen, finally

The pass above was reasoned from markup because there was no way to look at the pages. Playwright is
a devDependency now and `npm run shots` renders every surface at iPhone width into
`storage/app/shots/`.

**The overflow report is the half that earns it.** A screenshot shows *that* something is wrong;
`document.scrollWidth > clientWidth` plus the offending nodes says *which* element, which is
otherwise guesswork — and it correctly reports zero on every page, because the overflow was never a
wide element. It was the zoom. Nodes inside a deliberately scrollable ancestor are skipped, so a tab
strip that is *meant* to be wider than its box does not drown the real ones.

Looking at the result immediately found three things reading could not:

- A **rule floating at the top of a card with nothing above it** — `Pledge` draws a top border to
  separate itself from the product above, and in the group header it had no product above it.
- **"Nobody has chipped in yet" under all six items** of a wish list, which is what led to removing
  per-item pledging altogether.
- **A feed title running to ten lines**, making one card four times the height of its neighbours.
  Clamped to three: enough to recognise a thing you have already seen, which is what a list is for.

The wish list went from 9108px to 5814px tall — a third of it gone, none of it content.

## Files

- `resources/js/Components/ListKindBadge.tsx` — the badge, and the one place the sentence is chosen
- `resources/js/Pages/Lists/Show.tsx`, `Index.tsx`
- `lang/*/site.php` — `lists.kind_*`, `lists.about_*`, `lists.new_*_body`, `lists.quiz_unlocks`,
  `lists.shop_for`, `lists.shared_empty`

## See also

- [list-taxonomy.md](list-taxonomy.md) — the three kinds, and why they are three
- [wishlists.md](wishlists.md) — claiming, sharing, and the occasion
- [list-quiz.md](list-quiz.md) — why the quiz cannot appear on a private list
