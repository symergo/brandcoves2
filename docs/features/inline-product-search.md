---
name: Inline product search
area: Wishlist / Gifting / UI
status: Active
date_added: 2026-09-27
---

# One inline product search, everywhere a product is picked in place

## Why

The owner, 2026-09-27: "The inline search under 'Dingen die je leuk zou vinden' on /for/ and other
inline searches should be the same as the inline search on the list pages."

The list page's search is the add panel, `AddProduct`: one field ("Zoek, of plak een link…", the
magnifier, the barcode button), catalogue results and shops we do not mirror in rows, a pasted link,
the step where you adjust the wording and add a note before "Zet erop", and "Voeg een offline artikel
toe" (with a photo) from the moment it opens. Five other places searched for a product without
leaving the page, and each had drawn its own: `/for/{token}` (a field, a barcode, "Iets toevoegen",
results as a grid of cards), Find a gift's search card without a list (no barcode, its own
placeholder, rows of its own), a shared list's "suggest something" (a grid of cards), and an answer's
product picker on Ask others (a grid of cards, the field not a form). Copies drift the moment one
gains a feature; that is how pasted links and the barcode reached some of them and not others.

## The shared piece

`resources/js/Components/ProductSearch.tsx` holds what every one of them has:

- `SearchField`: the field, the barcode (fills the field and searches in place) and the magnifier.
  A form, so Enter searches and a phone shows a Search key; `nested` draws the same thing without a
  form where it sits inside one (the answer on Ask others).
- `HitList` and `HitRow`: the rows. A row is either one button (`onPick`, the list page, where
  choosing leads to the wording step) or a row with its own control at the end (`action`) and the
  title linking to the product.
- `useListSearch(base)`: the request to `/list-search`, on Enter only (the live half costs requests
  to shops), latest answer wins. A refused request (signed out, throttled) is reported as a failure,
  not as "nothing found".
- `OwnItemFooter` ("Voeg een offline artikel toe") and `searchPanel` (the frame).

`AddProduct` is built from these, and the callers keep only what really differs: what a press does.

## Each search, and what it became

| Where | Before | Now |
|---|---|---|
| List page (`AddProduct`) | the reference | built from `ProductSearch`; unchanged to use |
| `/for/{token}`, "Dingen die je leuk zou vinden" | own form to `/for/{token}/suggest?q=`, barcode, "Iets toevoegen", a grid with its own save button | **`AddProduct` itself**, for that person's list, when the server says the visitor may add to it (`canAdd`). Signed out: the same field and footer, which open the sign-in dialog |
| Find a gift, a person or relationship chosen | a look-alike box that hands over to `AddProduct` | the same, now drawn with `SearchField` (so it gained the barcode) and `OwnItemFooter` |
| Find a gift, nobody chosen or signed out | own field and rows, catalogue only | `SearchField` and `HitRow`; catalogue, shops we do not mirror and a pasted link, each row with the save picker (`SaveToList`). Signed out, the field leads to `/search` |
| Shared list, "suggest something" | own field, a grid of cards | `SearchField` and `HitRow`, the Suggest (or Add) button at the end of each row; "add something of your own" (`ManualItem`) under the results, where the panel has its footer |
| Ask others, products on an answer | own field (not a form), a grid of cards | `SearchField nested` and `HitRow`, "Add" at the end of each row |

## Decisions

- **`/for/{token}`: the list page's panel, not a copy.** The panel posts to `/list-items`, which is
  behind sign-in and asks `ListAccess::canEdit()`. The page used to decide from "is somebody signed
  in" on the client; the server now sends `canAdd`, asking the same two questions, and
  `RecipientProfileTest` checks that the route takes the post exactly when `canAdd` is true (and
  refuses another account naming the list, and a signed-out visitor). No server-side allowance was
  needed: the list here is owned by whoever opened the link, so a signed-in visitor already could.
- **The panel is open on arrival there, but does not take the cursor** (`autoFocus={false}`, new on
  `AddProduct`, default on). It is the third section of the page; arriving must not scroll down to it
  or raise a phone's keyboard. After each add it opens again, empty, for the next thing.
- **"Laat me ideeën zien" stays**, as a second button under the panel with its grid of ideas ranked
  for the person themselves. Its save button is now the site's own (`SaveToList`), aimed at this
  list (`into`); `onSaved` (new on `SaveToList`) redraws the list under it, which is on screen. For a
  signed-out visitor that button asks them to sign in and keeps the product for afterwards
  (`PendingSave`), which lands it in their default list.
- **What a signed-out visitor typed is not carried across the sign-in.** The site's replay
  (`PendingSave`) is for a chosen product, and a search term is not one; inventing a second replay
  for words was not worth it.
- **`/for/{token}/suggest?q=` still works** (it is a real address): the panel opens with that search
  run.
- **Find a gift without a list has no "offline article".** The typed-by-hand form belongs to a list,
  and there is none yet; the save picker needs a product. A pasted link does work there: `SaveToList`
  took a `url` and saves it the way the panel does, as a hand-written item whose page is read
  afterwards.
- **Find a gift, signed out, searches on `/search`.** Until this change the card called
  `/list-search` for a signed-out visitor, which is signed-in only (it reads shops live, and
  `AddProductTest::a_guest_cannot_search_from_a_list` keeps it so), got a refusal and said "nothing
  found". The route stays closed; the field now leads to the public search page, where Bewaar asks
  them to sign in.
- **The shared list and Ask keep their own search requests.** Both are a GET back to their own page
  with `?q=`, gated by the share token or the question, and both search the catalogue only. They
  took the field and the rows, not `/list-search`: a stranger on a shared list is not signed in, and
  a second endpoint would be a second place the token has to be checked.

## Left as they are, on purpose

- The site header's search, the home page's `SearchCard`, the 404 page's field and the search page
  itself: they go to `/search` by design, to browse, not to pick a product for something.
- The brand page's "narrow within this brand" words: a filter on the page, not a product picker.

## Files

- `resources/js/Components/ProductSearch.tsx` (new), `AddProduct.tsx`, `SaveToList.tsx` (`url`,
  `onSaved`)
- `resources/js/Pages/Recipients/SelfDescribe.tsx`, `app/Http/Controllers/RecipientProfileController.php`
  (`canAdd`, `listTitle`)
- `resources/js/Pages/Gift/Wizard.tsx`, `resources/js/Pages/Lists/Shared.tsx`,
  `resources/js/Pages/Ask/Show.tsx`
- `tests/Feature/RecipientProfileTest.php`, `tests/Feature/OneStepListTest.php`

## See also

[list-surfaces.md](list-surfaces.md), [find-a-gift.md](find-a-gift.md),
[gifting-lenses.md](gifting-lenses.md), [pasted-links.md](pasted-links.md),
[save-button.md](save-button.md), [ask-others.md](ask-others.md).
