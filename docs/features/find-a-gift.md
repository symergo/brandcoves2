---
name: Find a gift
area: Gifting
status: Active
date_added: 2026-09-26
---

# Find a gift: one flow, one results page

`/gift` is **Find a gift** (nl *Cadeau vinden*, fr *Trouver un cadeau*, es *Encontrar un regalo*).
It asks who the present is for, offers three ways to look, and ends every way on the same page of
ideas.

## Why

A UX audit on 2026-09-26 counted about twelve separate doors to "find a gift": the Gift Finder
(the questions), This or that, the search box reading gift searches, the gift landing pages, persona
Coves, community Coves, Ask others, profile cards, This or that together and the person page. Each
had its own flow and its own results, and what the site knows showed up on some of them only:
"chosen by others" on two, the ideas without a shop on two, Coves others made on one, the next step
after a past gift on one. The owner approved item 6 of the proposal: one entry and one results page.

## The flow

1. **Who is it for?** One of your saved people, or a kind of person (partner, mum, dad, a
   grandparent, a son or daughter, a friend, a colleague, a brother or sister, a teacher, a host), or
   *Skip*. The kinds are the closed vocabulary products are tagged with (`RecipientType`), so the
   answer meets editors' `recipient:` tags, the gift landing pages and the persona Coves as one value
   instead of free text to be guessed at.
2. **Three ways, side by side**, under a "For Mum · change" line:
   - **Answer a few questions**: the old wizard's questions (interests, age, taste, budget, avoid),
     minus its own "who" step, which step 1 replaced.
   - **Choose between two things**: This or that, with who it is for in the link
     (`/gift/taste?person=<id>` or `?relationship=mother`). Either one skips This or that's own
     "for someone or for yourself?" question.
   - **Start from a type**: the persona Coves, four of them, the ones written for this kind of
     person first. When a market has no persona yet the column is left out and the other two share
     the width (owner's rule: nothing for a column means no column).
3. **One results page** (below).

A gift profile card (`/gift/card/{token}`) opens straight on the questions, filled in from the card,
as before. `/gift?for=<person>` (reminder emails, the person page) opens straight on the results.

## The one results page

`App\Services\Gift\GiftResults` builds it on the server and `resources/js/Components/GiftResults.tsx`
draws it. Top to bottom:

1. What was said (the questions' chips with *Adjust*) or what was learned (This or that's profile,
   with *keep this on a person*, or *make a gift profile card* when it was about yourself).
2. The cards: what each fits, and "chosen by others for someone like this".
3. What to do next: *Eight more* and *Start over* (questions), *Choose again* and *Refine with the
   questions* (This or that), and *Open as a page* wherever a landing page exists.
4. Ideas without a shop.
5. The next step after what a saved person was given, and the way to their page.
6. Coves others made for someone like this.
7. Last line: *Nothing that fits? Ask other people* (`/ask`).

Every way in gets every section. The questions' board already had most of them; This or that's
result now has *Open as a page*, the Coves others made, the next steps and the saved person's list,
and the gift landing pages have the same cards, ideas without a shop, Coves others made and the ask
line. A section added to `GiftResults` reaches all of them.

## Decisions taken without the owner

- **This or that keeps its own page** (`/gift/taste`) and its own POST. It is a way in, not a
  results page, and its rounds need the space; only its result became the shared results.
  No URL was absorbed, so nothing redirects: `/gift`, `/gift/taste`, `/gift/card/{token}`,
  `/gift-ideas/...` and `/for/{token}/taste` all still answer as before.
- **Who it is for may sit in a URL; the answers may not.** A saved person's id (checked against the
  owner, and ignored otherwise) or a vocabulary value says nothing about the person. The brief
  (interests, budget, what to avoid) still only travels by POST.
- **"Refine with the questions"** posts what This or that learned to `POST /gift`, so the same board
  opens with *Adjust*, *Eight more* and *Something else* beside it. This or that's result cannot
  offer those itself: its brief comes from choices, not answers. The learned price band travels as
  `budget_min` and `budget_max`; the questions only ever ask for a ceiling, so a typed ceiling drops
  the learned floor.
- **The persona column is a list of four, not filtered.** The Coves for the chosen kind of person
  come first, then those for anybody, then the rest: a type is recognised on sight, and "the home
  cook" can be the right shelf for a colleague. A persona's kind is its plan's
  `brief.relationship`; most personas have none yet, so they rank as "for anybody".
- **A landing page's cards changed.** They were search-result cards (discount, number of shops);
  they are the gift cards now, with what each fits and "chosen by others". The engine's verdict is
  cached with them (`bc:gift-landing:v2:*`), so the page still runs the engine once a day per budget.
- **Two cards across on a phone**, where the questions' board was one: a landing page holds
  twenty-four, and one at a time pushed everything under them far down.
- **The name.** "Cadeauzoeker" / "Gift Finder" became "Cadeau vinden" / "Find a gift" everywhere it
  named the page, in the four languages, because the header item and the page must say the same
  thing (navigation.md, "one name per page").

## No AI

Nothing here calls a model: the suggestion engine, the crowd picks, the offline ideas and the
Community Coves are retrieval and arithmetic (invariant 1). `FindAGiftTest` mocks `AiClient` to
refuse any call.

## Files

- `app/Services/Gift/GiftResults.php`: cards, the sections under them, the person's list, reading a
  free-text relationship as the vocabulary
- `app/Http/Controllers/GiftController.php` (the flow and the questions),
  `TasteController.php` (`carried`, the giver's extras, `refine`), `GiftLandingController.php`
- `resources/js/Pages/Gift/Wizard.tsx` (who, ways, questions, results),
  `resources/js/Pages/Gift/Taste.tsx`, `resources/js/Pages/GiftIdeas/Landing.tsx`,
  `resources/js/Components/GiftResults.tsx`
- `tests/Feature/FindAGiftTest.php`

## See also

[gift-whisperer.md](gift-whisperer.md) (the questions and the engine),
[taste-discovery.md](taste-discovery.md), [gift-landing-pages.md](gift-landing-pages.md),
[gift-personas.md](gift-personas.md), [crowd-picks.md](crowd-picks.md),
[offline-ideas.md](offline-ideas.md), [community-coves.md](community-coves.md),
[gift-history.md](gift-history.md), [ask-others.md](ask-others.md), [navigation.md](navigation.md).
