---
name: Design system
area: Frontend / Brand
status: Active — tokens, Button, Badge, the navigation beam; most call sites not yet migrated
date_added: 2026-09-06
---

# Design system

The tokens live in [resources/css/app.css](../../resources/css/app.css) and the two primitives in
`resources/js/Components/Button.tsx` and `Badge.tsx`. This page records the rules that were
implicit before the 2026-09-06 review measured how often they were broken, and what was changed.

## What the review found

The token set was small and well used — 36 arbitrary-value utilities in the whole tree, three hex
literals outside icon files — but it was being *diluted* rather than ignored:

- 56 accent buttons in 41 distinct class strings; 32 without a hover state, 50 without a
  transition. Pressing around the site, some primary buttons responded and some were inert.
- The card radius token lost 42% of the time: 68 cards used `rounded-card`, 33 `rounded-lg`, 9
  bare `rounded`. The same visual object had three radii a page apart.
- Thirteen `text-[11px]` and one `text-[10px]`, below the smallest size the scale admitted.
- Three disabled opacities (40, 50, 60), none changing the cursor.
- Errors painted `text-accent` — the same terracotta as the submit button beneath them.
- `text-ink-soft/70` on cream at 3.4:1 and `text-accent` as link text at 4.1:1, both under the
  4.5:1 AA floor, on the price footnote of every product page and the "see all" link of every
  home band.
- `EntityRails` in `stone-*`/`emerald-*` with `dark:` variants that fired on the OS preference,
  which `app.css` says at length is not how dark mode is decided here.
- No loading affordance anywhere but the search field's beam.
- Two search fields with `focus:outline-none`, switching off the site's only focus ring.

## The rules, now written down

**One button recipe.** `Button` takes `variant` (`primary`, `secondary`, `ghost`, `danger`) and
`size` (`sm`, `md`, `lg`), and `busy` renders a spinner over a dimmed label. `buttonClasses()`
is for the anchors that look like buttons — an outbound shop link, a sign-in link — which cannot
be a `<button>`. A new button is one of these; a new class string is a regression.

**One badge recipe.** `Badge` takes `tone` (`accent`, `sage`, `neutral`, `amber`) and `size`.
The accent tone is a *wash* (`bg-accent/10 text-accent-dark`), never a fill: solid accent belongs
to the one primary action on a view, and a badge that shouts as loudly as the button beside it
dilutes both. The discount was drawn four ways; it is one badge everywhere now — the `discount`
tone since 2026-09-07: solid sage with white text. Green because a discount is good news, and the
accent wash it wore was the buy button's colour, so a price cut read as a call to action. Solid
because it sits over product photographs, where a translucent pill takes on whatever is behind it.

**Solid accent is the primary action, once per view.** The Amazon fallback on the product page
was a solid accent block above the shop buttons for the offers we actually carry, and read as the
page's main action. It is outlined now.

**Accent is for fills; accent-dark is for text.** `--color-accent` (#c9503a) is 4.1:1 on cream,
under AA for text. `--color-accent-dark` (#a83f2c) is 6:1. The link recipe
`font-medium text-accent hover:text-accent-dark` became `text-accent-dark hover:text-ink` in ten
places; `hover:text-accent` on a transient state is fine.

*(Values before 2026-09-13; see The action colour is amber.)*

**Errors are `text-danger`.** A new token, `--color-danger` (#b42318, 6.4:1 on cream), clearly
not the brand. Nineteen error lines moved to it.

**Muted text is `text-ink-soft`, never `/70`.** Full strength is 6.4:1 on cream and looks nearly
the same.

**`text-2xs` is the floor.** A new token at 11px, matched to the legacy value so nothing moved,
so the size is on the scale rather than beside it.

**Cards are `rounded-card`.** Forty surfaces swept.

**Disabled is `opacity-50` plus `cursor-not-allowed`**, from `Button`; the sweep unified the
opacity elsewhere.

**`color-scheme: light`** is declared on `html`, so a dark-OS device does not paint native
controls — the sort select, every checkbox in the filter rail — in dark chrome on a cream page.
The dark tokens switch on `data-theme`, not on the OS; this states the same decision to the
browser.

**Every Inertia visit shows a beam.** `NavigationBeam` in `SiteLayout` reuses the search field's
scanner sweep along the top edge, after 150 ms so a fast visit never flashes it. `Button`'s
`busy` covers form submits.

**The product page's price is the biggest thing on it.** Title and price shared a size and a
weight exactly; the price is one step up with tabular figures, the title one step down, the hero
image carries `width`, `height` and `fetchpriority="high"` so it neither shifts the layout nor
waits its turn, and the description has the same ~70-character measure as every editorial page.

## Still open

- Migrating the remaining hand-rolled buttons and pills to the primitives. `Button` is used in 3
  files and `buttonClasses()`/`Badge` in 9; 48 hand-rolled `bg-accent … px-…` class strings remain
  across 36 files (counted 2026-09-15), including the list wizard that replaced the list create
  form.
- Three h1 tiers with no rule for which page gets which; two prose measures on editorial pages.
- Four icon stroke widths. The glyph characters that stood in for icons (`▲ ▼ ✕ × ✓ ♥ ♡`, the
  header's 🔔, the notification emoji, the Daily's 👍 👎) became `ToolIcon`s on 2026-09-27; see
  below.
- Self-hosting Inter: the TTFs are vendored for the social cards and the site still loads a
  render-blocking stylesheet from bunny.net.
- The footer carries no mark; the social card palette (teal and amber) and the site palette
  (cream, and amber since 2026-09-13) are strangers — a decision to make, not a bug.
- A sticky header, and tap targets under 40px on the picker chevron, pagination and chips.

## The phone pass (2026-09-07)

Every public page rendered at 390×844 with Playwright against the dev server, measuring body
width, tap targets under 40px, text under 12px and images without a size. Two pages scrolled
sideways — the discover search row (a `flex-1` form beside the surprise slider that could not
shrink) and the list-help screenshots (`ml-10` plus `w-full`) — both fixed. The brands index was a
single column 18,600px tall; two columns and a sticky letter bar with 40px targets took it to
13,300px with a way back from Z to B. Below `sm`, every `Button` is 44px, chips and reaction
pills 40px, footer rows and band "see all" links 44px, the save picker's bookmark 40px and its
chevron 32px wide (it was 20). A card whose feed image is missing or broken shows a gift outline
in the line colour rather than a blank square (`ImagePlaceholder`). What remains under 40px is
inline text inside a larger card — a brand name, a title — where the card is the target.

The audit script is not in the repo; it is fifty lines of Playwright over the sitemap's first
URL of each kind, and worth re-running after any layout change. The numbers it printed are in
the commit that landed this section.

## Explanations go behind an (i) (2026-09-07, the standard from here on)

A control shows its label and, where there is something to explain, `InfoTip` beside it: an (i)
that reveals the explanation in the flow on a tap, **under the title it stands next to**. The words
are one tap away for whoever wants them and cost nothing for whoever does not.

The "under the title" part is a layout rule with one requirement on the caller (2026-09-13). The
component's wrapper is `display: contents` and the note is a full-width block, so in running text
the note breaks below the line the icon is on, and in a flex row it drops to the next line — but
only if the row has `flex-wrap`. Without it the row does not break and the note opens beside the
heading, hanging off the icon, which is how the section headings on My Lists looked. A flex row
that holds an `InfoTip` gets `flex-wrap`; the two that do (My Lists' group headings, the Whisperer's
"remember" tick) say so in a comment.

**Since 2026-09-27 that requirement is gone** (owner: "zet de info tekst altijd onder de titel,
niet ernaast"). Most of the ~100 call sites had missed it, so the rule now lives once in
`resources/css/app.css`: a `.flex`/`.inline-flex` row whose `InfoTip` note is open wraps
(`:has(> .contents > [data-infotip-note])`), and the note takes the whole width and goes last
(`flex-basis: 100%; order: 99`), so it lands under the title and under anything beside it. In a
grid it spans every column. The note carries `data-infotip-note` for that. A new call site needs
nothing. This replaces the sentence under every label, the
paragraph in every choice card and the note at the foot of a form — each true, each a line, and
on a phone a step of the list wizard was a screen of explanation with the controls between the
paragraphs.

Rules: the explanation is never the only place a *requirement* is stated (a required field says
so on the field); an empty-state sentence ("you have no friends yet") is a statement, not an
explanation, and stays visible; choice cards carry their labels and one (i) on the legend lists
what each choice means. The list wizard is the reference implementation. New forms follow it;
existing ones move over as they are touched.

## A search button is a magnifier (2026-09-07)

Every field that searches ends in the same button: a square the height of the field, the accent
colour, the `search` glyph from `ToolIcon`, and the word ("Zoeken") kept for screen readers only.
The home page, the search page, the 404 page, the add-a-product panel on a list, the suggestion
search on a shared list and the picks search on a question all use it. The word was set four
different ways across those six and was the widest thing on the row on a phone; the glyph is
recognised faster than the word and reads the same in four languages.

## The action colour is amber (2026-09-13)

The owner picked it from five schemes rendered on the same two screens (the home page and the
Gift Cove wizard): terracotta as it was, sea green, bordeaux, amber and indigo. Amber is the colour
of the dot in the mark, and terracotta had become the default of every generated shop page.

Contrast was checked before the tokens changed, because the first orange proposed (`#d9782a`) was
3.2:1 for white text and failed AA. The tokens now: `accent` `#b2601f`, 4.6:1 for white text on it,
which is every filled button; `accent-dark` `#93501a`, 6.2:1 as link text on cream; `ink-soft`
`#63594c`, 6.4:1 on cream. Cream, ink and line warmed a step (`#fbf6ee`, `#1d1710`, `#ebe0cf`) to
sit under the new accent; `amber`, the badge tint for a list about somebody, went a shade yellower
(`#b99055`) so it stays apart from the accent beside it; sage and danger are unchanged. The same
values went into the mail theme, the Inertia progress bar and the browser theme colour, the three
places the palette is written out rather than read from the tokens. The dark theme keeps its own
surfaces and takes the accent as is: 4.0:1 on its ground, the same as before.

## How to name a list in text (2026-09-26, the standard from here on)

The owner: "When you name a list in text, make clear it is a list name by styling it
consistently as a list name." Nothing limits what somebody calls a list, so a name dropped into a
sentence as plain text ("Bewaard in voor mama") leaves the reader to find where it starts. Every
place changed is listed in [list-names-in-text.md](list-names-in-text.md).

- **On the site, a list's name inside a sentence is `<ListName name kind />`**
  (`resources/js/Components/ListName.tsx`): the kind's small line icon, then the name in medium
  weight and `ink`. One icon colour (`accent`) for every kind, the icon sized to the text (`1em`),
  and the icon never separated from the first word by a line break. No kind known (a bookmarked
  Cove): the plain `list` icon, so the style never goes missing.
- **A translated sentence with a list in it** goes through `tRich(key, { list: <ListName … /> })`
  from `useTranslations`, not `t()`. `t()` stays for strings: `title=`, `aria-label=`, share texts
  and anything else that cannot hold an element.
- **A sentence the server writes** (a flash, a save answer, a notification) is sent in pieces as
  well as finished: `App\Support\ListName::mention()` gives `message`, `template` (the sentence
  with `:list` left in), `name` and `kind`, and the page draws it with `rich(template, …)`. For a
  flash use `->with(ListName::flash(ListName::mentionList($key, $list)))`, which sets both
  `success` (the plain sentence, unchanged) and `success_list` (shared as `flash.list`).
- **Quotes around `:list` in a translation are dropped where the name is drawn**, as a matched
  pair only: the style does the job the quotes did. The plain sentence keeps them.
- **In an e-mail the name is bold**, through `ListName::mailSentence()` or `ListName::inMail()`,
  which also escape it so a name with `*` or `<` in it reads as typed.
- **Not for a heading or a card that *is* the list's title**, nor a row in a picker: those
  already say "this is a list" by where they are.

The three kind icons are the ones `ListKindBadge` uses (`kindIcons`). The heart was redrawn the
same day at nine tenths of its size, so it matches the clipboard and the two figures when they sit
side by side at text size. The My Coves section headings now carry the same icons.


## Nothing beside it: full width (2026-09-26, re-checked page by page 2026-09-27)

The owner's rule: when a right column would be empty, the content takes the full width. A block
capped at `max-w-2xl` on the left of a wide screen reads as a page with a missing column.

Checked across every page on 2026-09-27, after the owner found the `/for/{token}` page narrow with
nothing beside it. Lifted: the `/for` page's header and "Over jou" form, This or that's who-step,
empty state, result, save and profile-card blocks, Find a gift's questions, the answer form on a
question, the Find-a-gift card on a gift landing page, the quiz result, and the two Secret Friend
cards.

**What keeps a width, on purpose:**

- **A paragraph of running text** (an intro sentence, a Cove's editorial, a guide, a product
  description, the legal pages): about 65 characters a line is what can be read. The cap sits on
  the paragraph, never on the block around it.
- **A small centred single-purpose screen** (sign-in, accepting an invitation, the "no more
  invitations" page, joining a Secret Friend, the 404): centred, so no column is missing.
- **This or that's duel**: two product photos side by side. Full width would make each photo about
  580px tall and push the buttons off the screen, so it is centred (`mx-auto max-w-2xl`).
- **The help pages** (`/help`, `/lists-help`, `/search-help`): centred reading pages.

## The consistency review, round 1 (2026-09-27)

A read-only review found the same thing said several ways across the list, people and Santa
pages. The owner approved the fixes in three rounds; this is the first, the quick ones.

**Words: "Cove + lijst".** *Mijn Coves* is the overview's name; the thing in it is a *lijst*,
everywhere: never "lijstje", "verlanglijstje" or "cadeaulijstje" (the diminutive made one object
sound like three). Making one is **Maak een Cove** on every button that makes one: the header,
the picker's "+ …" row, the wizard's submit and a person's page ("Nieuwe lijst" and "Lijst maken"
are gone). Keeping a product from anywhere outside a list (product page, search, gift ideas,
offline ideas, a reminder's idea) is **Bewaren**; inside a list you are on, putting something on
it is **Toevoegen** (a suggestion accepted, a wish taken from their wish list, something typed by
hand). "Zet erop" and "Op mijn lijst" are gone. English says *Make a Cove*, *Save*, *Add*; French
and Spanish keep their words for list, save and add, with *Créer une Cove* and *Crear una Cove*.
The legal and about pages (`resources/legal/nl`) still say "lijstje": changing a contract's
wording is the owner's call, not a sweep's.

**Row actions are outlined.** `rowActionClasses()` in `Button.tsx` is the one recipe for an action
on a row of a list of rows (Mijn Coves, Mijn mensen): outlined, icon and words on a wide screen,
the icon alone on a phone. Never filled: twenty filled buttons down a page are twenty primary
actions, and the header's one ("Maak een Cove", "Iemand toevoegen") stops being the one. A
person's own page keeps "Cadeau vinden" filled: there it is the page's one primary action.

**One field recipe.** `fieldClasses()` in `Button.tsx`: the text field, select and textarea class
the people, person and Santa forms repeated by hand.

**Dates, countdowns and budgets have one formatter each**, beside `formatPrice` in `types.ts`:

- `formatDay(iso, market, { year, month })`: a `YYYY-MM-DD` date or an `MM-DD` birthday, parsed as
  a local midnight (a bare date read by `new Date()` is UTC, and west of Greenwich shows the day
  before). `year` is `false` (default), `true`, or `'auto'` (only when not this year). The four
  Santa pages showed the server's raw `2026-12-20`; the Santa mail said "Dec 20, 2026" in every
  language and now uses the group's market.
- `formatCountdown(days, t)`: "vandaag", "morgen", "over 12 dagen", which three pages each wrote.
- `formatBudget(cents, market)`: "€ 50", not "€ 50,00", for anything somebody chose as a limit (a
  person's budget, a Santa budget, "Cadeaus onder € 100", a search's price chip, a watched search's
  threshold, a question's budget). Odd cents are kept, since rounding would state a different
  budget. Real prices keep their cents.

**Icons, not glyphs.** New `ToolIcon`s: `check`, `bell` (the alerts drawing), `package`, `gift`
and `heart` (the wish list's drawing; filled with `className="fill-current"`, because CSS outranks
the `<svg>`'s `fill` attribute). They replace ✓ (save picker, "on this list", suggested, shared
with), × and ✕ (every remove and dismiss), ▲ ▼ (the chevron, turned), ♥ ♡ (the vote), the
header's 🔔, the notification kinds' emoji and arrow, and the Daily's 👍 👎 (which gained words for
a screen reader, `daily.react_up` / `react_down`, since the emoji had been the only label).

**Status pills are `Badge`s**: Privé / Gedeeld / Standaard / "N wachten" on Mijn Coves, "op
GiftCoves" on a person, "nieuw" on the shops page. A flash error is `danger`, not accent.

**Copying a link is `useCopy()`** (`resources/js/useCopy.ts`), shared by `ShareRow` and
`ShareMenu`, which had each written the clipboard call, the failure message and the three-second
status.

**Unused keys** (`ask.nav_hint`, `friends.add`, `lists.added`, `saved_coves.save`,
`saved_coves.saved`) were deleted after a one-off scan found them used neither literally nor under
a dynamic prefix in any language. The scan is not committed: it flags about thirty keys that *are*
used, built from parts (`lists.about_${kind}_${visibility}`), and a test that has to be taught
every such pattern would be noise.
