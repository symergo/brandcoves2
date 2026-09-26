# One-step list creation

**Status:** built 2026-09-26 (UX audit, item 7). Replaces the three-step list wizard.

## What it is

"Create a Cove" (the home page hero, the button on My Lists, `?new=…` links) opens one screen with
one question: **who is it for?**

| choice | kind it makes | default name (nl / en) |
|---|---|---|
| Voor mezelf / For me | wish list (`mine`) | Verlanglijst / Wish list |
| Voor iemand anders / For someone else | gift list (`for_someone`) | Cadeaus voor Sara / Gifts for Sara |
| Samen, voor iemand / Together, for someone | group gift (`group`) | Samen voor Sara / Together for Sara |

For the two lists about somebody, a name field (with the people and friends you already have as
one-tap chips above it). The list name is filled in and follows the person's name until it is
typed over; it may be cleared, and the server writes the same default then. One button makes the
list and lands on its page, with the add field open, focused and scrolled into view: the next thing
to do is paste a link or search.

A Secret Friend group is still reachable from the same screen, as a quiet link under the button
("Or draw names in a group"). It switches the screen to the group's own fields, all on one screen,
because a group cannot be edited after it is made.

## Steps: "make a list for my sister and add something"

Counted from the home page, signed in, by pressing through it (Playwright, desktop and phone).

| | before (three-step wizard) | after |
|---|---|---|
| 1 | Maak een Cove | Maak een Cove |
| 2 | Voor iemand anders | Voor iemand anders (cursor lands in the name field) |
| 3 | Volgende | type "Sara" |
| 4 | type "Sara" | Lijst maken |
| 5 | Volgende (name, occasion) | paste or type in the focused add field |
| 6 | Lijst maken (sharing step) | pick a result |
| 7 | search in the add field | confirm |
| 8 | pick a result | |
| 9 | confirm | |

**9 before, 7 after**; the list itself takes 4 instead of 6. The audit counted ~10 because it
started from the old dropdown-looking button on My Lists and counted opening the add panel, which
an empty list already did on its own. The last three steps (search, pick, confirm) belong to
`AddProduct` and are unchanged here.

## What moved where (nothing removed)

| was asked in the wizard | now |
|---|---|
| occasion and its date | list page, Settings (`Instellingen`). The first view of a new list offers it in a one-line prompt |
| sharing (private or link, anyone may add, voting, share with friends) | list page, Share (`Delen`), as it always was |
| ask the person for ideas | list page, the Ask chip (`Vraag`); offered in the prompt as "Ask Sara for ideas" |
| ask others for suggestions | list page, Share; offered in the prompt as "Share or ask others for ideas" |
| a new person's birthday | the list's occasion: choosing Birthday with a date on a list about somebody fills their birthday when it is blank (`WishlistController::update`). Also still on their `/for/{token}` page, and copied from a friend |

**The prompt** (`NewListPrompt`) shows once, for the owner, on the page `store()` redirects to
(flash `new_list`, shared as `flash.newList`). Its chips open the existing panels of `ListTools`;
"Later" hides it; the next request forgets it. It is one line and not a form because the add field
under it is what the page is for at that moment.

`store()` still accepts every setting the old wizard sent (occasion, visibility, ask, friends), so
the Gift Cove, older clients and the tests that post them keep working. Only the screen stopped
asking.

## Decisions, and why

- **Who-for is the only question** because it is the only answer that cannot change later: it
  decides the kind (see [list-taxonomy.md](list-taxonomy.md)). Everything else can be set on the
  list, where it was always settable.
- **The name is not required.** Most people answer "whatever"; asking was a step between them and
  the list. `ListMaker::defaultTitle()` writes the kind's default from the same language keys the
  screen shows (`site.wizard.default_*`), in the market's language, as ordinary text the owner can
  rename. It is not a `DefaultTitle` (the default list's name, translated on read): this one is a
  suggestion the person accepted.
- **The kind's name sits under each choice** (Verlanglijst, Cadeaulijst, …), so the reader learns
  the word while answering the question. Those labels are `lists.kind_*`, which another change
  renames; this screen follows whatever they say.
- **One name for the entry point.** The hero said "Maak een Cove" and My Lists said "Maak een nieuwe
  lijst", for the same screen. `lists.make_new` now says the same as `home.cta_create` in all four
  languages (a test holds that), and `NewListButton` lost its chevron: it looked like a dropdown of
  kinds, and the kind is now chosen on the screen.
- **Guests** still get the screen; its button is "Log in en maak de lijst", which remembers the
  answer in local storage for a day and makes the list on the way back, as the wizard did. The
  create endpoint stays behind an account ([wishlists.md](wishlists.md), "Keeping a list requires an
  account").
- **`?new`, `?new=mine`, `?new=for_someone`, `?new=group`, `?new=santa`** all still work: the kind is
  preselected, and for a person-shaped kind the cursor starts in the name field.
- **Scrolling to the add field.** `AddProduct` with `defaultOpen` focused its field, but Inertia
  resets the scroll to the top when a visit finishes, so on a phone the cursor sat a screen and a
  half down, out of sight. It now scrolls the field into view (`block: 'nearest'`) a moment after
  mounting; on a desktop screen where it is already visible, nothing moves.

## Files

`resources/js/Components/ListWizard.tsx` (the screen; name kept so its two callers did not change),
`NewListButton.tsx`, `NewListPrompt.tsx`, `AddProduct.tsx` (scroll), `Pages/Lists/Show.tsx` (one
element), `app/Services/Wishlist/ListMaker.php` (default name),
`app/Http/Controllers/WishlistController.php` (`store`: title optional, `new_list` flash; `update`:
birthday), `HandleInertiaRequests` (`flash.newList`). Help: `lang/*/help_lists.php` (saving, kinds,
group gift, Secret Friend) and `help.coves_make` on /help. Tests: `OneStepListTest`.
