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
   **Your people as cards** since 2026-09-27 (owner: "the cards of the people you know / are connected
   with instead of just the name"): for a signed-in visitor the saved people and the friends on
   GiftCoves come from the same `MyPeople` service as My people, one card each with the initial, the
   relationship, the next date and up to three interests. A saved person is chosen with one tap. A
   friend nobody saved yet is saved first (`POST /recipients` with `friend_id`, My people's "Bewaar
   wat je over … weet") and then chosen, because Find a gift works on a saved person whose taste it
   reads and whose list it fills. The cards are `PersonPicker` since the consistency review's round
   3 (2026-09-27), shared with the list wizard and This or that; a visitor who is not signed in gets
   the same cards for their saved people (name, relationship, interests) instead of the name chips.
   **For myself** since 2026-09-28 (owner: "add also for myself"): a "Voor mezelf" button under the
   kinds. It clears a saved person and a relationship picked before, and posts `for_me`, which the
   server reads as the flag to set both aside even when the form still carries them, and to score
   with `SuggestionProfile::forMyself()`. The questions that speak about the person (interests, age,
   taste, the two way hints) switch to a `_me` key in the second person; vibe, budget and avoid read
   the same either way, so they have no second form. This or that gets it as `?for=me` and skips its
   own "for someone or for yourself?" question. The "search and save" card searches in place with
   the save picker, as for *Skip*: your own lists are the ones to choose from.
2. **Three ways, side by side**, under a "For Mum · change" line:
   - **Answer a few questions**: the old wizard's questions (interests, age, taste, budget, avoid),
     minus its own "who" step, which step 1 replaced.
   - **Choose between two things**: This or that, with who it is for in the link
     (`/gift/taste?person=<id>` or `?relationship=mother`). Either one skips This or that's own
     "for someone or for yourself?" question.
   - **Start from a type**: the persona Coves, four of them, the ones written for this kind of
     person first. When a market has no persona yet the column is left out and the other two share
     the width (owner's rule: nothing for a column means no column).
   - **Ask others** (added 2026-09-26, owner's request): the ask form at `/ask`, already filled in.
     The link carries only who it is for (`?from=gift&person=<id>` or `&relationship=mother`), the
     same rule as This or that's link; the server fills the title ("Cadeau-ideeën voor mijn
     mama?"), interests, taste, what matters, age group and budget from the saved person, never
     their name or notes. What was said in this tab and is not on the person (a typed budget,
     ticked interests) travels through `sessionStorage` (`resources/js/askBrief.ts`), never the
     address. The asker checks it and presses Ask; moderation is unchanged. Details:
     [ask-others.md](ask-others.md), "Filled in".
   Four cards side by side on a wide screen (three without personas), two by two on a tablet.

   **Two rows since 2026-09-27** (owner's review). *Zoek zelf*: questions, This or that, and the type
   card; then *Of vraag het iemand*: ask others, and ask the person themselves. Three and two cards
   (two and two without personas), so no card is ever alone on a row. Each card has its icon beside
   the title, which makes them shorter.
   - **"Weet je al wat je zoekt?"**, a full-width card above both rows (owner: "a search card that
     puts items straight on the list"). For a saved person its button POSTs to
     `/people/{id}/list` (`PersonController::listFor`, JSON), which returns the list for them and
     makes it the first time, then opens the list page's own add panel (`AddProduct`) for that list:
     catalogue results, shops we do not mirror and something typed by hand, as on the list itself.
     Pressed, not on page load, so choosing a person never makes a list. Since the owner's second
     look the same day it looks like a list's search box from the start ("Zoek, of plak een link…",
     the magnifier, "Voeg een offline artikel toe"), not a button: the first search or the offline
     link fetches the list and hands over to `AddProduct`, which starts with that search already run
     (`initialTerm`) or on the typed-by-hand form (`startManual`), so nothing is asked twice. **A relationship counts as a person too** (owner: "there is a person picked, either a
     friend or a relationship"): with "Collega" chosen, the first search POSTs to
     `/people/for-relationship/list` (`PersonController::listForRelationship`), which saves a person
     named after the relationship ("Collega") and makes their list, and finds that same person again
     next time. They appear on My people, where they can be renamed or removed. Only when nobody is
     chosen, or for a visitor who is not signed in, does the card search in place (`/list-search`)
     with the save picker on each result instead of going to /search. Without a saved person it
     is the site search, where Bewaar asks which list.
     **Since the owner's third look (2026-09-27)** the card's field and rows are the list panel's own
     (`ProductSearch`, [inline-product-search.md](inline-product-search.md)): the barcode, shops we do
     not mirror and pasted links included. A signed-out visitor's search goes to `/search`: the
     in-place search is signed-in only, and the card had been getting a refusal and showing
     "nothing found".
   - **The type card is a dropdown** ("Kies een type…", up to 30 persona Coves, this kind of person's
     first). It was four cards inside a card, the tallest thing on the page, and every other card was
     stretched to its height.
   - **Ask others has a second action**: "Deel de lijst voor {naam} en laat anderen iets
     voorstellen". It POSTs to `/people/{id}/share-list` (`PersonController::shareList`), which finds
     the list for this person, or makes it the way the results page does
     (`GiftResults::recipientList`), and opens it with `?panel=share`. A POST because it may make a
     list; a link must not. Sharing itself stays the owner's press on that panel: nothing here makes a
     list public. Friends and family then suggest items on the shared list (`SuggestionController`).
     Without a saved person it links to a new list instead.
   - **"Vraag het {naam} zelf"** (new): the person's own link (`/for/{token}`, the same one My people
     offers), to copy or share. There they play This or that, suggest gifts, fill in what they like,
     or press "Dit ben ik". With an account behind them the card links to their page instead, and
     without a saved person it explains that the link belongs to a saved person and leads to My
     people.
   - **"Dit ben ik" now also connects the two** (`RecipientProfileController::claim`, via
     `Friends::link`, source `shared_list`). Until then it only bound the saved person to their
     account, and the giver still saw none of their wish lists, which a friend's page shows. Pressing
     it on a link the giver sent is at least as deliberate as opening each other's shared list, which
     already connects people.
3. **One results page** (below).

**What you say about a saved person is always kept on them** (owner, 2026-09-27: "the info about
the person when searching gifts is not saved to the profile"). There was a tick, "Onthoud deze
antwoorden voor Mama": first off, which forgot the answers unless somebody found it, then on by
default. The owner removed it on 2026-09-28, so there is no one-off search for a saved person any
more; a search for a kind of person ("een mama") writes nothing on anyone. What the person described
about themselves through their own link still wins over a guess (`describeTaste`, as before). This
or that played for a saved person keeps its result on them on arrival, without the "Bewaar bij Mama"
press that was the only way before; played for nobody in particular, the buttons stay.

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
7. Last line: *Nothing that fits? Ask other people* (`/ask`). From Find a gift itself it opens the
   form filled in, like the fourth way; from a landing page it is the plain board.

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

## Thumbs up, thumbs down (2026-09-27)

The owner's request: "Add thumbs up or down to the gift suggestions and use this info to teach the
engine."

### What a thumb does

Every card on the results page has two small line icons (`ToolIcon` `thumbsUp` / `thumbsDown`), with
their meaning in the button labels and one (i) beside the heading (`gift.thumbs_hint`).

- **Thumbs up** keeps the card, drawn pressed; pressing it again takes the thumb back. It is sent on
  its own (`POST /{market}/gift/feedback`, `GiftFeedbackController`), without re-ranking the board
  under the visitor. Cards already liked come back pressed (`GiftResults::cards`, `vote`).
- **Thumbs down is the old "Something else"**: on the questions' board it is the swap
  (`GiftController::swap`), which now also records the vote, so the replacement and the learning
  are one request. On This or that's result and a gift landing page, which cannot re-rank on the
  spot, the card leaves the board and the vote is sent on its own. The "Iets anders" link is gone:
  two controls that both mean "not this" was one too many.
- Where: the questions, This or that's result (for a giver only: not on the person's own page or a
  "help me find out" link) and the gift landing pages. The landing pages are cached per day, so
  their thumbs teach everybody else, not the page itself.

### What it teaches, per saved person (`recipient_feedback`)

When the ideas are for one of your people, the thumb is stored against that person
(`recipient_feedback`: person, product, `up`/`down`, one row per person and product) and the engine
reads it whenever a brief is about them: Find a gift, This or that for them, and the reminder
emails' ideas (`TasteBrief::aboutRecipient`, set only by a caller that scoped the person to the
owner).

- **A thumb down is final for that person**: the product is excluded before retrieval, like a past
  gift, on every board and in every later sitting. The session's `RejectionMemory` still covers the
  sitting for everybody else.
- **Liked and disliked products lend their likeness** (`PersonFeedback`, pure): each product is its
  interests (editors' and crowd tags), its category and its brand. **Up is +1, down is -0.5**,
  because a "no" is often about that product (a colour, one they have, the price), not about cooking
  or the brand, and the product itself already never returns. **Interest 0.5, category 0.35, brand
  0.15**: an interest is what the person is about, a category what kind of present, a brand the
  weakest hint. Each part is clamped to one thumb's worth, so three liked cookbooks do not bury the
  person's other interests.
- The result (-1..1) is the `feedback` signal, **weight 12** for someone else: a candidate sharing a
  liked idea's interest, category and brand gains 12 points, a little over vibe (10), well under
  interest fit (40). It reorders good answers; one click never replaces the brief. Zero on your own
  list, where there is no saved person.
- **It does not touch the person's interests or avoid list.** A thumb is its own signal: rewriting
  what the owner typed from one click would be the site deciding for them, and it would be invisible.
- Nothing new on the person page. A "what you liked" list there could come later (not built).

### What it teaches everybody (`gift_votes`)

Every thumb also counts for the crowd: one row per voter and product (`gift_votes`: product, a
one-way code of the visitor made with `Owner::identityHash('gift-vote')`, the kind of person it was
for in the `RecipientType` vocabulary or null, the vote). Pressing twice, or changing your mind,
replaces the row, so **one person can never move a product twice**.

- **Nothing counts below `giftcoves.gift.feedback.min_voters` different people (5,
  `GIFT_FEEDBACK_MIN_VOTERS`)**, checked in the query's `HAVING` and again in `CrowdVotes`, and never
  below two whatever the environment says. It is the privacy guarantee: below it, one person's taste,
  or their opinion of their mum's, would show in a stranger's results. **Deliberately not tied to
  `GIFT_MIN_OWNERS`**, which production has at 1 for the list signals: a thumb costs one click.
- Votes for the same kind of person decide when there are enough of them; otherwise all votes on the
  product. A saved person's free-text relationship ("mama") is read as the vocabulary, so a vote for
  Mama and one for "a mother" meet.
- Approval is `(up - down) / votes`; a net "no" is **halved** (a thumb down is more often about one
  person's taste than about the product). Confidence is 0.7 at the threshold rising to 1.0 at twenty
  people on a log scale, the crowd picks' curve.
- The `crowd_votes` signal, **weight 6** for someone else (a little over half of `crowd`, 10: a
  thumb costs a second, keeping something on a list is a stronger act), 3 on your own list.
- Counted when read, one grouped query over the candidates by the unique index, not kept as
  counters: counters drift from their rows, and pruning votes after a year would leave counts nobody
  can explain.
- Without a saved person (a kind of person, or Skip) only this and the session's `RejectionMemory`
  apply.

### Privacy

- The thumbs for a saved person are the owner's data about somebody else: they cascade with the
  person, and the person with the account. Only the owner's own briefs read them.
- A crowd vote holds no identity, only the one-way code; it is deleted **365 days after it last
  changed** (`PrunePersonalDataCommand::RETENTION['gift_votes']`), and with the account
  (`User::booted` computes the code and deletes its rows, since the database cannot join a code to an
  account).
- `bc:scrub` deletes both tables. The privacy page (en, nl) has a row in "What we process", a
  paragraph under Recipients and under "What lists teach together", and two retention rows; the
  terms' "How results are ranked" says the thumbs weigh in.
- `GroupMerger` moves both tables to the winning product, keeping the winner's row where both had
  one, or a product turned down for somebody would return under the winner's id.

### Files

`app/Services/Gift/GiftFeedback.php` (store, read), `PersonFeedback.php` and `CrowdVotes.php` (the
arithmetic, pure), `SuggestionEngine` (`feedback`, `crowd_votes`, the exclusion),
`app/Http/Controllers/GiftFeedbackController.php`, migration
`2026_09_28_001000_gift_ideas_learn_from_thumbs`, `resources/js/Components/GiftResults.tsx`
(`thumbs`), tests `tests/Feature/GiftFeedbackTest.php` and `tests/Unit/PersonFeedbackTest.php`.

## No AI

Nothing here calls a model: the suggestion engine, the crowd picks, the offline ideas, the thumbs and
the Community Coves are retrieval and arithmetic (invariant 1). `FindAGiftTest` and
`GiftFeedbackTest` mock `AiClient` to refuse any call.

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
