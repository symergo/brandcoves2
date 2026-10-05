# List budget

What you mean to spend is set on a **list**, not on a person. Added 2026-10-05.

## Why

The owner, 2026-10-05: "Budget is geen persoonseigenschap maar een lijstkenmerk" (a budget is not a
property of a person but of a list). Mum has one birthday list and one Christmas list, and what you
spend on each differs. A budget on the person forced one number on both. The person page showed it
as a fact about Mum, which it never was: it is a fact about the giver's plans.

## How it works

- `wishlists.budget_min` and `budget_max`, nullable integer cents (invariant 7). Set in the list's
  settings panel (`ListTools`, euros in, cents stored by `WishlistController::update`, the top may
  not be under the bottom) and shown under the list's name on the list page.
- `App\Services\Wishlist\ListBudget` is the one place that answers "what is the budget for this
  person". It reads **the person's list**: their newest `for_someone` list, the same one Find a gift
  saves into (`GiftResults::recipientList`). Everything that used to read the person's budget asks
  here: `TasteBrief` (Find a gift opening with a person chosen), `NextSteps`, `AskPrefill` (a list's
  own budget first) and the recipient picker in `GiftController`.
- What Find a gift, This or that (`TasteController`) and Taste together (`TasteTogether`) learn or are
  told about a budget is kept with `ListBudget::rememberFor`, on that list. When an account's person
  has no list yet, it makes them the list Find a gift would make, so a budget is never dropped.
  Outside a request (no market to make it in) nothing is kept.
- The person page, My people and the person's edit form no longer show or take a budget. A budget
  sent by an old page is ignored.

## The move

1. `2026_10_05_000100_add_budget_to_wishlists` adds the columns and copies each person's budget onto
   every `for_someone` list about them. A person with a budget and no list loses it: there was
   nowhere to put it, and making lists nobody asked for in a migration was judged worse. On the
   local production copy that was one person of three.
2. `recipients.budget_min` and `budget_max` are dropped by a **later** migration, shipped in a
   separate deploy (expand/contract): if the first deploy is rolled back, its code still finds the
   columns.

Tests: `ListBudgetTest`, plus the budget assertions in `GiftWhispererTest`, `GiftHistoryTest`,
`TasteDiscoveryTest`, `TasteTogetherTest` and `AskOthersReachTest`.
