---
name: Design system
area: Frontend / Brand
status: Active — tokens, Button, Badge, the navigation beam; migration of call sites ongoing
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

**Errors are `text-danger`.** A new token, `--color-danger` (#b42318, 6.4:1 on cream), clearly
not the brand. Nineteen error lines moved to it.

**Muted text is `text-ink-soft`, never `/70`.** Full strength is 6.9:1 and looks nearly the same.

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

- Migrating the remaining hand-rolled buttons and pills to the primitives. Done so far: the
  product page, the home search, the list and Santa create forms, the product card and daily
  deal badges.
- Three h1 tiers with no rule for which page gets which; two prose measures on editorial pages.
- Four icon stroke widths, and glyph characters (`☰ ✕ ▲ ▼ ×`, one emoji) standing in for icons.
- Self-hosting Inter: the TTFs are vendored for the social cards and the site still loads a
  render-blocking stylesheet from bunny.net.
- The footer carries no mark; the social card palette (teal and amber) and the site palette
  (cream and terracotta) are strangers — a decision to make, not a bug.
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
that reveals the explanation in the flow on a tap. The words are one tap away for whoever wants
them and cost nothing for whoever does not. This replaces the sentence under every label, the
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
