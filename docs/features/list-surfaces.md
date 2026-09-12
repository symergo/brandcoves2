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

## Six tabs became four, then five

`ListTools` grew a tab per feature: Share, Quiz, Special occasion, People, Handover, Secret Friend.
Three of those were asking three halves of one question — *who else is looking at this list, and what
are they looking at it for* — and the roster, the one thing a group list cannot work without, was
filed furthest from the button that shares it.

Share, People and Occasion became one panel with sections. What varies is the sections rather than
the panel: every kind gets the link, and a list about somebody else also gets the people.

**The occasion came back out, and the merge was two-thirds right rather than wrong.** Share and
People really are one errand: the roster only exists once the link does, and both answer *who else is
looking at this list*. The occasion answers something else. You set it once, months before anybody is
invited, and you set it just as readily on a list you never share at all — a birthday on a private
list about your father is the ordinary case, not the edge. Folded into Share it was filed under a
word that means something else, three forms deep, and the person who opened the panel to copy a link
had to scroll past a wedding-date form to reach the roster.

So `occasion` is a chip again, between Quiz and Handover, shown to the owner of any kind of list. It
is labelled `registry.occasion` — *Gelegenheid*, *Occasion*, *Ocasión* — not `registry.badge`: a chip
in a horizontally scrolling row wants one word, and the panel it opens still carries the full
"Special occasion" as its heading. Both strings already existed; neither was added for this.

**Opening it no longer publishes the list, and that regression is the interesting part.** Pressing
Share used to turn sharing on as a side effect of opening the panel, which was right when the panel
*was* the link — sharing took two presses in two places and people left without the URL. It stopped
being right the moment the panel also held the occasion and the roster: those are things an owner
sets on a list they have **not** decided to share, and a tab that published as a side effect of being
opened is a privacy change nobody asked for, on the page where privacy is the point. The press is now
a button inside the panel, and the two-press objection is answered by it being the first thing in
there.

The manual's steps had to move both times — `registry_step1` said "press Special occasion", naming a
tab that had stopped existing; it then said "press Share, then fill in Special occasion", naming a
route that had stopped existing. That is the rule about quoting real labels failing exactly as
[gifting-lenses.md](gifting-lenses.md) warns it does.

`CopyMatchesCodeTest::the_occasion_panel_is_called_what_the_manual_calls_it` is the catch, and it
asserts against `registry.occasion` — the chip — rather than `registry.badge`. Pointed at the badge it
would have gone green while the manual named a word the row does not print, which is the whole failure
it exists to prevent.

## Three buttons: Share, Settings, Delete (2026-09-12)

The row had grown back to six chips (Share, Ask *name*, Quiz, Occasion, Hand over, Secret Friend)
plus a delete icon in the page header, and the owner read it the way a first-time visitor would:
three of those are one errand. Handing a list over and reading what the recipient asked for are both
things you do *with the people you shared it with*, so they live inside Share now, as sections under
the link and the roster, each with its own heading and a rule above it. Ask *name* keeps its heading
with the name in it; it lost its own chip and the lit state that came with it.

Ask came back out the same evening, as a chip of its own labelled *Ask for suggestions*
(`lists.ask_chip`), second in the row after Share. The owner asked for it, and the case is fair: the
link it hands out goes to the one person the list must stay hidden from, which is the opposite
direction to everything under Share, and the answers that come back are a list to read, not a
setting. Hand over stays under Share. The lit state returned with the chip: it lights once the
recipient has actually answered.

**Settings is new, and it is what the list *is* rather than who may see it.** The name and the note
under it were edited inline in the header, the price watch sat among the sharing switches (where it
was a fact about the owner's inbox filed under a word about other people), and the occasion was a
chip of its own. One panel holds the three: a small form for the name and the note, the price watch
with its percentage, and the occasion form under a rule. The chip lights when a price watch or an
occasion is set, since either is a fact about the list worth seeing from the row.

**Delete is in the row, last, with its name.** It was a trash icon alone in the header corner, the
one destructive control on the page and the one without a word beside it. The row is where somebody
looks for what a list can do; pushed to the far end with a label it reads as the last resort it is.
It is a button, not a panel: it asks once and acts.

**Every chip carries an icon** from `ToolIcon`, the same set the Gift Cove draws its tools with, so
the row and the manual show the same marks. Two were added for this: `settings` (three sliders) and
`trash`.

**The header lost two pills.** "Anyone can add" and "Shared" both restated something the row already
shows: Share lights up when the list has a live link, and the add-a-product control is present or it
is not. A badge captioning a control one line below it is the header explaining the row.

The item grid is one column of full-row cards on every width. It was two columns on a phone, three
from `sm` and two again from `lg`, and on a wide screen two narrow cards side by side read as a
broken layout; a card with the picture left and the actions right is a row, and a row wants the
width.

The ✕ on each card went the same afternoon. The bookmark beside it is a toggle whose menu already
takes an item off the list, with an undo rather than a confirm; the ✕ was that act a second time, and
on a group list it pushed the vote button into the corner.

The manual's steps moved with it, as they did the last two times this row changed: `registry_step1`
now says press *Settings*, `handover_step2` says open *Share* and press *Hand over*, and
`CopyMatchesCodeTest` pins the occasion step to `lists.settings`. The help screenshot script clicks
the Settings tab for picture 15 and keeps the file name.

## The sharing panel, in the order the decision is made

The merge above got the *contents* right and left the panel itself a stack. It read: the link → who
sees the claims → a loose sentence about what the link grants → what the link allows → the roster,
all under one heading reading **"Visibility"**. So the two settings that are about the *link* sat
below the two that are about *claims*, separated by a paragraph explaining a link they were nowhere
near — and the one heading on the panel named neither.

Four blocks now, each with its own heading, in the order somebody actually decides:

| Block | Shown when | Holds |
|---|---|---|
| the state and the link | always | one sentence saying whether this is shared, then the link or the button that makes one |
| **What the link allows** | owner, and there is a link | what the link grants, and whether people holding it can add |
| **Who sees what** | owner, claimable, and somebody else is on it | do I see the claims; do the others see each other's names |
| **Invited before sharing became a link** | owner, and the roster is not empty | the people let in one at a time, and the way to take it back |

### It says what is true before it offers a control

The panel used to open straight into either a button or a URL and leave the reader to work out the
state from which of the two they got. On the page where the mistake is thinking something is private
when it is not, that is worth a line of its own: `lists.sharing_on` / `lists.sharing_off`, at the top,
before anything you can press.

`sharing_on` used to be passed to `ShareRow` as its hint, which put "anyone with the link can see
this list" *under* the link — describing a consequence below the thing that causes it.

### The link's settings only exist once the link does

"People with the link can add to this list" was offered on lists with no link. It is a property of a
URL that exists, and showing it before there is one is a setting for something that has not happened
— which is how the sentence explaining what the link grants ended up four controls away from the
link it was about.

### The sentence contradicted the control under it

`lists.share_grants` read *"Anyone with the link can see this list **and add to it**. There is
nothing else to set up"* — directly above the setting that decides whether they can add to it, which
on a wish list defaults to *no*, and directly above three more things to set up. It now states the
half that is always true and leaves the rest to the controls that own it.

### Stop sharing is a button, not a footnote

It was grey underlined text under the URL. It is the one irreversible thing on the panel — the link
dies and everyone holding it is out — and while it should stay quiet and stay second, it should not
read as an annotation to the field above it. A bordered secondary button: still quiet, still second,
unmistakably a control.

### Both outcomes on screen, not one behind a toggle

`link_can_add` was a checkbox whose hint changed with its state, so the alternative was only readable
*after* you had switched to it: you had to make the change to find out what the change did. Off here
is not "nothing happens" — it is the approval queue — and a checkbox says otherwise by its shape.

It is two radios carrying the two sentences that already existed, `link_can_add_on` and
`link_can_add_off`, as their labels. Both outcomes are on screen; the one in force is the one
selected. No copy was added for this.

### A privacy switch that saves silently is a privacy switch you cannot trust

Four settings here write on change, with no Save button and no confirmation: the request goes out,
`back()` returns the page, and a re-render that looks identical to no re-render is not feedback. On a
form that is a fair assumption. On *can the people I sent this to see each other's names* it leaves
the reader with the control's own position as the only evidence anything was stored — which is
exactly the evidence they would have had if it had failed.

One `role="status"` line at the foot of the panel, `lists.saved`, cleared after two and a half
seconds. One rather than one per control: four of these save the same way, and the line holds its
height whether or not it has anything to say, so saving never nudges the page.

## The sharing panel, redrawn to match Settings (2026-09-12)

The same afternoon the row became three buttons, the owner asked for the share card to be made
consistent with the rest. Two things were wrong with it, and both were the same thing seen twice.

**It had no headings.** The Settings panel next to it opens with a heading, a short form, a rule and
the next heading; the share panel was one column of blocks with a fixed gap between them and no
words above any block but the first. A radio pair about money, three switches and a list of people
sat under each other with nothing saying which was which. The earlier note above argues headings
were scaffolding for a form this is not; that held while the panel was a link and three switches,
and stopped holding once hand-over and the recipient's suggestions moved in under it. Each block now
carries an `h3`, in the order the decision is made: the link, who gets it by name, what
it allows, how a group collects, who was invited before links existed, then hand over, then what the
recipient asked for.

**It rendered blocks that were empty.** The options section existed on every list and had nothing
in it on a private wish list of your own, which is most lists, so the panel opened onto a sentence,
a button and a hundred pixels of nothing; the "Saved" line held its height under a panel with no
switch to save. `linkOptions` is the one fact both now hang on, and a section exists only when it
has content.

Two smaller changes came with it. The button that publishes the list says *Turn sharing on*
(`lists.enable_sharing`) rather than *Share*, which was the word on the chip that had just been
pressed. And the friends picker is a section rather than a button that revealed one: it still does
nothing until Send, so the deliberateness the collapsed button was protecting is intact, and a
heading with a hint (`lists.share_with_friends_hint`) says what the chips are for better than the
button did.

The rules between the sections and the heading over the link switches went the same evening, at the
owner's request: with headings on the sections a gap separates well enough, the switches say what
they do without a title, and the card is a third shorter. The Settings panel keeps its one rule
between the form and the occasion.

The Send button under the friends went last. A chip was a checkbox and the row ended in Send, so
choosing and sending were two moments, while the other half of the same row already acted on tap: a
friend who has the list is a tick that becomes a cross. Now a tap shares with that person and the
email goes out; a second tap takes it back after a confirm. A mis-tap sends a real email that cannot
be recalled, and the chip turning green on the spot is what makes it visible; the harm is a friend
hearing about a wish list, which was judged small enough for one control instead of two.

Settings also names the person. Once the "Ask :name" chip lost its label the recipient's name
appeared nowhere on their own list, so the form carries a *For whom* field above the description on a
list about somebody. The name is a fact about the recipient, not the list: Save patches
`/recipients/{id}` first and the list once that has landed, so one press is one outcome.

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

## The Gift Cove: a wizard on top, the whole site under it (2026-09-06)

The page called "everything you can do here" opened on a title and a grid of the nine list tools, which
described a third of the site and started nothing. A grid of explanations is a reference, and nobody
arrives wanting a reference: they arrive with a person and an occasion, and the thing to do with those
is make a list.

**The hero is the wizard** (`ListWizard`): three questions since 2026-09-07 (four before), each explained before it is asked. *Who for*
(the three kinds, with what each can do and why it cannot be changed later), *name and occasion* (the
person, picked from friends or typed; the occasion and date, with what a date does: registry, reminders,
delivery address), *sharing* (private or link, explained per kind; "anyone can add" for building a list
together; voting on a group list; share with named friends; the privacy rule). The third step ends
in the Create button. Signed out, that button is the sign-in, and the answers survive it: the draft is
kept in `localStorage` for a day, because the magic link opens in a new tab where session storage is
empty, and once signed in it is restored and submitted without asking again — the button they pressed
was "sign in and make the list".

*Until 2026-09-07 there was a fourth step*, a summary of the answers with the Create button under it.
It was removed: every answer is one step back and the summary restated a screen the reader had just
filled in. The list page is the summary. It opens on the empty state with the add-a-product panel
already open (`AddProduct defaultOpen`), because an empty list has one thing to do and a button saying
so was a step between the person and it. The private choice is labelled "Private (or share later)",
so choosing it does not read as closing a door.

**The replayed draft lost its title (fixed 2026-09-07).** The title auto-fill effect ("For Anna" until
somebody types) runs on mount with its empty initial values, in the same pass as the draft restore, and
its unconditional write of an empty title landed after the restore. Every list made by signing in at
the end of the wizard arrived with no title and was refused. The effect now writes only when the value
actually changes, so the mount run is a no-op. Reproduced and verified signed out, where the restore
path is the same.

It posts to the same `store()` as the form on My Lists. That endpoint learned `event_type`,
`event_date`, `visibility` (private or link), `link_can_add`, `voting_enabled` and `share_with`,
because a wizard that explains an option and then sends you to the list page to turn it on has
explained it to nobody. Each is optional and follows `update()`'s rule for the same column; voting is
dropped on any kind but group; friends are shared with through `ListSharer`, which keeps only friends
and refuses a private list. A freshly `create()`d model does not carry the database's default
visibility, so the list is refreshed before sharing.

**Under it, five bands with a button each**: your own list (share it, a registry, suggestions), a
list for somebody (the private research list, buying separately with the other givers, handover), with
other people (build a list together, buy together, the board, Secret Santa, the quiz, friends), find a
present (Whisperer, search and barcode, Ask, notifications), get inspired (daily Cove, guides, ideas,
surprise). A card is one sentence and a button that starts the thing, and the grid takes its column
count from the band (three or four) so every band is full rows. Coves keep their `CoveIcon` drawings;
`ToolIcon` gained `search`, `alerts`, `friends`, `guides`, `split`, `build` and `board`.

The privacy rule is said once, on the sharing step, where the decision it governs is made. The SEO
description no longer counts "nine tools".

### The picker that emptied itself, and the date nobody should be asked for

Three things the wizard got wrong, found by using it.

**A friend stopped being offered the moment you used them.** The person picker drew its friends group
from friends who were *not* already one of your people, on the reasoning that a friend with a profile
is listed under their own name and offering them twice makes two profiles. True for the list, wrong
for the group: making one list for a friend removed them from "from your friends" for good, and
somebody whose only friend already had a profile opened a heading with nothing under it. Now the
group holds **every** friend, and a friend who already has a profile is offered *as* that profile, so
one person is one entry and picking them still cannot mint a second. `GiftCoveController` sends one
`friends` list for both the picker and the sharing step; the two lists it sent before are how they
came to disagree.

**A date field next to "Birthday" asks for something the screen above already knows.**
`App\Services\Wishlist\OccasionDate` answers when an occasion falls, from two sources: the person (a
birthday is theirs and nothing else can answer it) and the calendar, for the days that are a number
rather than a custom — Christmas and Valentine's. A wedding, a baby, a graduation are null on
purpose. Always the next occurrence, today included, and 29 February lands on the 28th in a common
year.

**Mother's Day and Father's Day are deliberately not answered.** They were, from the editorial
`ObservanceCalendar`, and that was wrong: those days move by *region*, not only by country. Father's
Day is the second Sunday of June in Flanders and the second Sunday of March in Wallonia; Mother's Day
is 15 August in Antwerp. A market is not a region, so a single date would be confidently wrong for a
chunk of the people reading it, on a day they care about. The editorial calendar keeps its
market-level date, because stocking a themed Cove a week early costs nothing like as much.

A filled-in date is always arguable: the wizard says what it will put on the list and offers **a
different date** beside it, prefilled with the one it was going to use. Christmas Day is the 25th and
plenty of families here hand out presents on the evening of the 24th.

The server derives it on the way in, so the date on the list is one answer rather than two, and only
when the wizard sent none: a date typed by hand always wins. Two things had to arrive for that to
work. A friend's birthday now travels into the profile made from them (theirs if published, else my
note, the same order the Friends page reads them), which the reminders wanted anyway. And a birthday
typed for somebody who already has a profile is now kept rather than dropped, filled in only when
that profile has none.

The field itself only appears when the date is genuinely a question, with a label of its own beside
the labelled occasion select. It used to sit there always, greyed out until an occasion was chosen,
then demanding a date for Christmas, and stretching its unlabelled neighbour to the taller cell.

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

## The prose came off, and the controls say it instead (2026-08-31)

Everything above is the argument for *explaining* a list in sentences. This pass takes most of them
away, and the reason is not that the argument was wrong — it is that the explanations were being
read by the owner, on every visit, forever, about a list they made.

Four changes, all on `Lists/Show`:

**The tool chips say what is switched on.** `ListTools` rendered five identical pills, so the only
way to learn whether this list had an occasion, a quiz or a live link was to open each panel in turn
and read it. Each tab now carries a `set` flag next to `show` — the *stored* fact its own panel
writes, never a proxy for it — and a set tab renders in sage with a filled dot and an `sr-only`
`lists.tool_on`. Open still wins visually: accent is "you are looking at this one", and if a set tab
matched it the row would read as two panels open at once.

| Chip | Lit when |
|---|---|
| Share | `visibility != private` **and** there is a `shareUrl` — the link is what the panel hands out |
| Occasion | `eventType` **or** `eventDate` — a date with no type is still an answer |
| Quiz | a `ListQuiz` exists. `quizPlays` is how it *went*, which is a fact for inside the panel |
| Hand over | **never.** See below |
| Secret Santa | some membership is `attached` — being in a group is why the chip exists at all |

Handing over is an act, not a setting. `canHandOver` is already false once it has happened, so the
chip disappears rather than lighting up — and `handoverEmail` is only the recipient's address
prefilled for convenience, so lighting the chip off *that* would announce a handover nobody has
offered.

**The chips moved above the pot.** They were rendered after `Pledge`, so on the one kind of list
that has a pot the controls started a card and a half down the page. Under the header on every kind
is what makes their position learnable.

**Delete is an icon in the corner.** It was the widest button on the page, level with the title —
the only destructive control also the first thing the eye met. The words survive as `aria-label`
and `title`.

**Two paragraphs are gone.** The shared/private *sentence* became a chip beside the kind badge,
using `lists.shared_short` / `private_short`, the same shape the index cards use; and the kind
sentence — `useListKindWords().sentence`, the `lists.about_*` keys — is no longer rendered anywhere.
So is the owner's "claims are hidden from you, that is the point" banner (`lists.owner_view_note`),
which still appears on `Lists/Shared`, where a reader arriving from a link has not just come from
the settings that caused it.

That last one is a real loss against the argument above, and it is worth naming rather than
smoothing over: the private sentence was **the only place the mechanisms were taught**. What
survives is the case that section itself calls the clearest — `lists.quiz_unlocks`, still rendered
on a private `mine` list, because a gated tab cannot teach that it exists. The rest is now taught by
the chips being lit or not, which is a weaker teacher for a feature you have never heard of and a
much better one for the state of a list you already own.

`useListKindWords().sentence` and the `lists.about_*`, `lists.shared_badge` and `lists.private_badge`
strings are left in place, unused. They are the whole of that argument, four languages deep, and the
next person to want a teaching sentence should find it rather than rewrite it.

### Two more, from reading the Dutch out loud

**"Vraag het ze zelf" is "Vraag het hen zelf".** *Ze* as an indirect object is spoken Northern
Dutch; a Belgian reader hears it as sloppy, and `be-nl` and `nl-nl` share one catalogue. Changed
with the two strings that quote or echo it — `handover.handover_step1` names the button in its
instructions, so a rename that missed it would have pointed people at a button that no longer
existed.

**And it only belongs on a list about somebody else.** The ask-them card is gated on the kind now
(`for_someone` or `group`) as well as on there being a recipient. `ListMaker` derives one from the
other, so on today's data those are the same condition — but "ask them what they want" on a wish
list of your own is the page asking you to interview yourself, and a kind derived somewhere else is
exactly the sort of thing that stops being derived.

## Files

- `resources/js/Components/ListKindBadge.tsx` — the badge, and the one place the sentence is chosen
- `resources/js/Pages/Lists/Show.tsx`, `Index.tsx`
- `resources/js/Components/ListTools.tsx` — the chip row, the `set` flag per tool, and the sharing
  panel's four blocks
- `app/Services/Wishlist/ListMaker.php` — the recipient decides the kind, which the ask card mirrors
- `lang/*/site.php` — `lists.kind_*`, `lists.new_*_body`, `lists.quiz_unlocks`, `lists.shop_for`,
  `lists.shared_empty`, `lists.tool_on`, `lists.shared_short` / `private_short`. `lists.about_*`,
  `lists.shared_badge` and `lists.private_badge` are kept but no longer rendered

## One door to a new list: the wizard (2026-09-07)

"New list" on My Lists opens `ListWizard` — the same four questions the Gift Cove opens with —
and the one-screen create form that used to sit there is gone. Two reasons.

The form asked the same things with none of the explanation. Its three kind cards carried a
sentence each; the wizard explains a kind before it asks, the sharing before it is chosen, and
lets a signed-out visitor walk the whole thing as the explanation, with the sign-in as the last
button and the answers replayed on return. A page that had both was two ways of doing one thing,
and the poorer one was behind the more prominent button.

The form also had its own copy of the people picker, and it had already drifted: it dropped friends
who had a profile, the bug the wizard fixed the day before. `App\Services\Wishlist\WizardOffer`
now builds friends, recipients and occasions for both pages, and `ListWizardTest` holds the two to
the same answer.

What the wizard learned for this: `initialKind`, so `?new=<kind>` — the Gift Cove's cards, the home
page's disclosure — opens on the second question with the first answered (back still leads to it);
`onCancel`, so a wizard opened by a button can be put away by one; and `hasListDraft()`, so My
Lists opens the wizard on arrival when a draft is waiting, because a magic link does not promise to
land on the Gift Cove.

Two smaller things on the list page the same day. The "Gift list for Anna" subtitle under the
title is gone: the recipient is on the kind pill beside the title and in the title itself now that
the wizard names a list "For Anna", so the line said it a third time. And the delete icon keeps the
top-right corner on every width — the header used to wrap on a phone and drop the icon under the
pills — at 44px, the site's minimum target.

## The item card is a tile on a phone (2026-09-08)

On a phone the row that works on a desktop did not: thumbnail, three lines of title and the
save control side by side in 390px left the title 141px and the picture a smudge. Three fixes in
one afternoon moved the controls around inside that row, and each cost something the owner saw
within the hour: a strip of empty space, or a bigger thumbnail that made the words narrower
again. The brief in the end was "compact, large picture", and the answer is not a better row but
a different shape.

Below `lg` `ListItemCard` is a tile: the picture is the card's width and square, the controls sit
on it in the top-right corner the product card already uses, and the words go underneath, two
lines of title and the price. The list shows two tiles to a row on a phone and three between `sm`
and `lg`. A row of two tiles is shorter than two of the old rows and every picture is twice the
size (171px on a 390px phone, against 80). From `lg` the card is the row it was: the two-column
grid gives it 490px there, and beside a picture that wide the words would be the afterthought.
The picture is a link to wherever the title goes, hidden from the tab order and the screen reader
because the title is the same link with a name; a tap on the biggest thing on the tile that did
nothing read as a broken page. It sits at the foot of its box with no padding under it, so a
product drawn on white meets its title instead of floating above the feed's margin plus ours.

The save control lost its chevron below `lg` in the same change. The compact `SaveToList` is a
bookmark and a narrow chevron, the chevron there so a card can be filed straight into a named
list. On a phone the chevron was a 32px second target beside the first, and the pair covered a
third of a tile's picture; there the bookmark is the whole control, a tap saves and a second tap
opens the sheet, which is where a move lives anyway. The desktop keeps the pair. `CopyToList`'s
icon button and the owner's edit and remove buttons became the same round chip with a background
and a blur, because they now sit on a picture rather than beside a title.

## See also

- [list-taxonomy.md](list-taxonomy.md) — the three kinds, and why they are three
- [wishlists.md](wishlists.md) — claiming, sharing, and the occasion
- [list-quiz.md](list-quiz.md) — why the quiz cannot appear on a private list
- [sharing.md](sharing.md) — the link, the copy button and the channels inside the first block
