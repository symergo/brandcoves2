---
name: Ask others
area: Discovery / Community
status: Active
date_added: 2026-08-16
---

# Ask others

> **Since 2026-09-29** there is no vibe (Handig / Leuk / Mooi) and no values (sustainable / local /
> handmade) anywhere; taste is the pairs of opposites only. See [taste-pairs.md](taste-pairs.md).
> Mentions of either below are history.

A board where somebody describes who they are buying for and other people suggest something.

## The gap it fills

Every other way into this site assumes you can already describe what you want. Search needs a noun.
Find a gift needs six answers about a person. A Cove is a theme we chose. "She's turning forty,
she has everything, help" is none of those — it is a question for a person, and until now there was
nowhere to put one.

It sits under **Discover**, not Organise. Organise is for keeping track of what you already decided;
this is a way of finding something when you cannot describe it well enough to search for it. It is
also the only surface on the site whose content comes from other visitors rather than from us, which
is the reason most of this document is about moderation.

## This is the first surface that publishes what a visitor wrote

Everything else a person types here is private by construction: a list is theirs, a suggestion goes
to one owner, a Secret Santa exclusion is read by a draw algorithm and by nobody. This is the first
table whose rows are meant to be read by strangers on an indexable page — so moderation is a column,
not a plan.

(Since 2026-09-27 a question can also be asked of your people only, which is never on the board and
is not read first; see "Ask the community or ask your people" below. Everything in this section is
about the board.)

**Nothing that can be reached from a request handler is able to publish anything.** A post is
created `pending`; `TriageCommunityPost` is the only thing in the codebase that can set
`published`. That is invariant #1 doing double duty — a visitor request must never cause AI spend,
and "post a question" is the most obviously abusable trigger there could be — but it is also simply
the right shape: posting returns immediately and says "we are reading this", which is honest whether
the answer takes two seconds or until somebody opens the admin tomorrow.

`AskOthersTest::a_new_question_does_not_appear_on_the_board` is the load-bearing test in the
feature. Every other guard here can be right while that one is broken, and if it is broken the board
is an open publishing endpoint on our own domain.

### Three outcomes, and every failure is the safe one

| Outcome | When |
|---|---|
| **Published** | the flat screen found nothing *and* the model said `publish` |
| **Rejected** | the model said `refuse`, with its reason kept on the row |
| **Pending** | everything else |

"Everything else" is the important column: AI switched off, the daily cap reached, the API down, a
malformed reply, a verdict the model invented, an uncaught exception, the job failing twice. All of
them leave the row exactly as it was created, which is unpublished. A bug in the triage path cannot
put unreviewed text on the site; it can only make the admin queue longer.

With `AI_ENABLED=false` the whole feature still works — the Filament queue stops being a fallback
and becomes the entire moderation system, and a human publishes everything. That is the documented
degradation, and it is why the flat screen exists rather than handing everything to the model.

### The flat screen runs first, and only ever holds

`app/Services/Community/PostScreen.php` catches links, obfuscated links (`example (dot) com`),
email addresses, phone numbers and shouting. Three reasons it is not left to the model:

1. It works with the model switched off, which is what makes "a human reads the rest" a workable
   fallback rather than an unread pile.
2. It does not drift between model versions. The rules that matter most here are exactly the flat
   ones.
3. It costs nothing, and a link-stuffed post is both the commonest abuse and the cheapest to catch —
   `a_link_is_held_without_asking_the_model` asserts the model is never called for one, so somebody
   posting spam cannot spend the AI budget doing it.

Everything here **holds** rather than rejects. A regex has no business accusing anybody of anything,
so false positives cost one person a few hours' wait, which is what lets the patterns be blunt.
Contact details are checked before links, because an email address contains a domain and would
otherwise be reported as the wrong thing.

## An answer carries products, not links

`community_answer_picks` holds `product_groups` ids. This is the difference between the feature being
useful and being a liability: a pick renders as an ordinary product card with a live price for the
right market, and every outbound click leaves through `/go/{offer}` where the scheme is checked
(invariant #5). **There is no field to paste a URL into**, which is why there is no rule about
pasting URLs.

Picks are re-checked against the market on the way in rather than trusted — the ids come from the
client, and a hand-built request naming a product from another catalogue would render a price in the
wrong currency for a shop that does not deliver here (invariant #2).

Three per answer. Enough for "one of these three", few enough that an answer is a recommendation
rather than a catalogue.

## Your own held post is shown to you

A post is read before it appears, which is right and is also the exact moment the feature looks
broken: you press Ask, the board reloads, and your question is not on it. `mine` carries your own
unpublished questions back to the board, and `isVisibleTo()` lets you open your own held question and
see your own held answer. It is not a disclosure — it is your own writing.

To everybody else a held question is a **404**, not a 403. "This exists but you may not see it" is
itself information.

## Optional structure on a question

Added 2026-08-16. A free-text question is the point of the board — "she has everything, help" is
exactly what search cannot take — but answers are noticeably better when the asker has said
*coffee, practical, under €40*, and most people will tick that if the ticking is free.

**The vocabulary is Find a gift's own.** `interests` holds `Interest` values and `vibe` a
`Vibe`, so an answerer's product search can be seeded from a question with no translation layer, the
two surfaces cannot drift into two ideas of what "cooking" means, and the structured half of the
board is localised for free through `label()`.

**All of it is nullable and stays that way.** The question is the required part; somebody who types
one sentence and presses Ask gets a question on the board. In the form the whole block is folded
behind "Say a bit more about them" — a form that opens with nine fields is a form people close.

`CommunityQuestion::tags()` turns the ticked values into labels and **skips any value no longer in
the enum**, so retiring an interest quietly removes it from old questions rather than printing
`photography` in the middle of a Dutch sentence.

## Where it is reachable from

Under **Discover**, in two places: the header menu and a card on `/discover-cove`. The front page's
Discover band also carried it until that band was removed on 2026-09-13 (see
[homepage.md](homepage.md)). It is the only entry in any of them whose content comes from other
visitors rather than from us, which is the argument for putting it there — Daily, Surprise and the
Coves are all this site showing you something it chose, and Ask is the one where the answer comes
from a person.

The hub also lists the six most recent questions. An unanswered one is the most effective invitation
the feature has: somebody who happens to know the answer recognises it on sight, which is a far
better reason to click than a card explaining what a question board is.

### Easier to reach (2026-09-26)

The owner found it too hard to find: only the last line of Find a gift's results, a band on
Discover that appeared only with three questions, and a few search states. Now also:

- **Find a gift, the fourth way.** After "Voor wie?", beside the questions, This or that and a type:
  a card *Vraag het aan anderen* that opens the form filled in (below). See
  [find-a-gift.md](find-a-gift.md).
- **Discover, always.** A short invitation with a *Stel een vraag* button (`/ask?new=1`, which
  opens the form at once), even with no questions. The list of questions still appears under it
  only with three or more.
- **The list page.** On your own gift list or group list, *Vraag het aan anderen* is an item in the
  Meer menu (`/ask?list=<id>`). It first shipped as a button next to the add control; the owner
  moved it into the menu the same day (2026-09-26) and asked for the button to go. Not on a wish
  list of your own: that list is about you, and "what should people buy me" is not a question for
  strangers.

### Filled in, never with a name

`App\Services\Community\AskPrefill`. The three ways in carry only who it is about: `?relationship=`
(a vocabulary value), `?person=` (a saved person's id) or `?list=` (a list's id). The rest is
looked up on the server, for the owner only; somebody else's person or list fills nothing.

- **Title**: "Cadeau-ideeën voor mijn mama?" (`ask.prefill_title` + `ask.prefill_who.<kind>`,
  written per language because French and Spanish need the right possessive). Only when the
  relationship reads as the closed vocabulary: a relationship typed by hand ("Tante Mieke") is left
  out, because free text about a person can hold their name.
- **The optional half**: interests (the fixed list only; a typed word has no chip), taste, what
  matters, age group as its label ("50-64 jaar"), budget in euros; from a list also the occasion
  and date ("Verjaardag, 12 oktober").
- **Never** the name, the notes, the birthday or what they were given.
- From Find a gift, what was said in the tab but is not on a saved person (a typed budget, ticked
  interests) comes through `sessionStorage` (`resources/js/askBrief.ts`), read once and only when
  the address says `from=gift`. Find a gift never puts answers in an address.

The form opens unfolded when something was filled, so the asker sees what will be posted and can
change or clear it. Nothing is posted until they press Ask, and moderation is the same as for a
question typed from scratch.

### Answers onto the list

A question asked from a list keeps `community_questions.wishlist_id` (checked again on the way in:
only your own gift or group list; anything else is dropped without a word). On the question page
**the asker alone** gets `into` = that list, so every product in an answer saves straight onto it
("save to Verjaardag mama"), with a line saying so and a link back. Nobody else ever learns which
list it was; the question does not name it. `ON DELETE SET NULL`: deleting the list never deletes a
public question.

## Sent to your people (2026-09-26)

`App\Services\Community\QuestionToPeople`, run by the queued `SendQuestionToPeople`, which
`CommunityQuestion::publish()` queues after the commit. `publish()` is called by the triage job and
by the admin's Publish button, so every way onto the board sends it and nothing short of the board
does. **Held and refused questions are never sent**: sending one to twenty people would be
publishing it by another route.

- **Who**: every account linked to the asker by a friendship (My people with an account). Not a
  saved person without an account, not somebody who only opened a link, never the asker.
- **What**: an inbox row, kind `ask.people_question`, "Bram vraagt: “…”" in the receiver's
  language (their chosen market, else the question's), linking to the question. And an email
  (`PeopleQuestionMail`) to receivers who have not turned the email off. Only the asker's name and
  the question's title, both public on the board. Nothing from any list.
- **Once**: `community_questions.people_notified_at` is claimed by an update that only succeeds
  while it is null, so two workers or a question refused and published again still send once.
- **One a day**: at most one per asker per receiver in a rolling 24 hours, read from the inbox's
  `payload->asker_id`. The second question of an evening is on the board for anybody who follows
  the first.

### The switches, all on by default

Three nullable timestamps on `users`, null is on, so every account has the owner's default with no
backfill. On the notifications page, beside the reminder email switch, explanations behind (i):

| switch | column | side |
|---|---|---|
| Stuur mijn vragen naar mijn mensen | `ask_people_off_at` | asker |
| Toon me de vragen van mijn mensen | `people_questions_off_at` | receiver: inbox and email |
| Ook per e-mail | `people_question_emails_off_at` | receiver: email only |

The email's unsubscribe link (signed, GET and RFC 8058 POST, CSRF-exempt like the reminders' one)
sets only the email column: the question still reaches the inbox, as the reminder emails' stop
link does. `AskPeopleSettingsController`.

**Decided without the owner**: the brief asked for one receiver setting and "email only for
receivers who get email notifications". There is no general email-notifications switch on this
site (the only one is the reminder emails'), so the email got a switch of its own under the
receiver's, which is also what the unsubscribe link needs to turn off. The form says "Gaat ook naar
je mensen" with (i) while the asker's switch is on.

## Ask the community or ask your people (2026-09-27)

Owner: "2 options: ask the GiftCoves community, ask your people on GiftCoves (provide a share link
after posting)." The form now opens on **Aan wie vraag je het?**, two cards:

| | De GiftCoves-gemeenschap (`public`) | Je mensen op GiftCoves (`people`) |
|---|---|---|
| Who sees it | everyone, on the board; indexable once answered; in the sitemap | the asker's friends and whoever holds the link; `noindex, nofollow`; on no public list |
| Address | `/ask/{id}/{slug}` | `/ask/p/{code}`, never the id |
| Read first | yes, `TriageCommunityPost` | no |
| The asker's people | told once it is published, if the asker's switch is on | told at once, whatever the asker's switch says |
| After posting | back to the board, "we lezen dit" | the question page, with the share popup already open |

`community_questions.audience` (string + CHECK, default `public`) and `share_token` (a
`ShareCode`, unique where set), with a CHECK that a people question has a code and a board
question has none. The logic is `App\Services\Community\PeopleQuestions`.

**The community stays the default from every way in**, Find a gift and a list page included. It
is the feature people already know, and the narrower audience should be something the asker chose,
not something that happened because of where they clicked from.

### Why a people question is not read first

The board is moderated because it publishes a stranger's writing on an indexable page of ours. A
people question is not published: it goes to people the asker chose, as a shared list, a suggestion
or an invitation does, and none of those is read first. Holding it would also break the one thing
asked for: a link to send straight after posting is useless if it opens on "we are still reading
this". So it is created `published` (which is what makes it answerable, and keeps the
status-and-date CHECK true) with `audience = people`. It still shows in the admin's question list,
with an audience column and filter, so a reported one can be refused; that closes the link to
everybody but its asker.

**Answers on it are still read first**, by the same job. Whoever holds the link can answer, and a
link travels further than the asker may have meant. A held answer costs its writer a short wait.
Decided without the owner; an easy switch if friends' answers should appear at once.

### Why a link code and not the id

Ids are sequential, and "only your people" must not mean "anybody who counts". The code is the
permission, exactly as on a shared list (the same 10-character `ShareCode`, about 50 bits), the
GET is throttled at 60 a minute, and `/ask/{id}` answers 404 for a people question, even to its
asker, who has the link. `bc:scrub` rotates the codes. The model hides `share_token` from
serialisation.

### Nothing that lists questions to strangers can reach one

`CommunityQuestion::published()` now means **on the board**: published *and* `audience =
public`. Every caller (the board, Discover, the sitemap, the board's answer route) wanted exactly
that, and the next listing somebody writes gets the safe set without having to know people
questions exist. A people question is found by its code or as one of your friends'.

### Where the asker and their friends find it again

- **Jouw vragen** on `/ask` now lists all your questions, not only the held ones, and a published
  one carries a share button (the popup: *Link kopiëren*, *Stuur via…*, the question as the
  message). Your own board questions are left out of the board list below it, so nothing shows
  twice. A held question has no share button: its link is a 404 to everybody else.
- **Van je mensen**: your friends' people questions (six, newest first), because the notification
  is dismissed and the one-a-day limit can keep a second question out of the inbox. Only friends
  you have now: removing a friend takes their questions off your page, as it takes their lists.
- The question page shows *Alleen voor je mensen* with (i) above the title, and the asker a *Delen*
  button. Guests can read and get the sign-in link to answer; they come back to the same page.

### Notifications

The same `SendQuestionToPeople` job, queued by `PeopleQuestions::ask()` after the commit, with the
same `people_notified_at` claim. Two differences, in `QuestionToPeople`: the link is the code URL,
and **the asker's "Stuur mijn vragen naar mijn mensen" switch does not stop it**. That switch means
"also send my board questions to my people"; choosing *Je mensen* on the form is the later and more
specific decision. The receivers' two switches and the one-a-day limit apply unchanged.

## Schema notes

- **`status` is a string with a CHECK**, per the enum-ish convention: altering a native PG enum
  cannot run inside a transaction, so every future value would be a deploy hazard.
- **Three states, not a boolean.** `published_at IS NULL` cannot tell "nobody has looked at this
  yet" from "somebody looked and said no", and those need different screens, different counts, and
  different things said to the author.
- **`(status = 'published') = (published_at IS NOT NULL)`** is a CHECK on both tables. The board
  filters on one and orders on the other, so a row where they disagree is either invisible while
  claiming to be live or live with no place in the ordering. `publish()` and `refuse()` on the models
  are the only writers, and they move both columns together.
- **A rejected post is kept, not deleted.** It is the evidence for why an account was warned, and
  deleting it makes every moderation decision unauditable.
- **`answers_count` counts published answers only.** A question showing "3 answers" and then
  displaying none — because all three are held — is worse than showing nothing. Maintained by
  `CommunityAnswer`'s model events, so the triage job and the admin cannot disagree about it.

> **A bug the counter test caught.** The event first branched on `wasRecentlyCreated`, which stays
> true for the rest of an instance's life — so a model created, then published, then refused took
> the "was it just created?" branch all three times and stopped counting after the first. It now
> asks `wasChanged('status')`, which is the actual question.

> **A raw key on the ask form, 2026-09-14 to 2026-09-26.** The values fieldset is headed by
> `gift.step_values`, which the Gift Whisperer's rework of 2026-09-14 deleted because its own form
> stopped asking; the ask form still used it and showed the key. The clean-up of 2026-09-26 put the
> string back in all four languages.

## SEO

The board and its questions are indexable, because a question with good answers on it is exactly the
page that should rank and a login wall is how it never does.

A question is `noindex, follow` until it has **at least one published answer**. Before that it is a
thin page made of one stranger's sentence; it becomes indexable the moment it is worth landing on.

The slug is decoration and the id is identity, exactly as on a product page — `/ask/{id}/{slug}`,
with a stale slug redirecting rather than 404ing, so retitling never strands a shared link.

**In the sitemap, answered questions only.** `SitemapController` lists the board (`/ask`) and up to
2,000 published questions that have at least one published answer. That is the same floor as the
`noindex` rule above: a sitemap naming a noindex page asks for a crawl it then refuses.

## Files

- `app/Enums/ModerationStatus.php`
- `app/Models/CommunityQuestion.php`, `CommunityAnswer.php`, `CommunityAnswerPick.php`
- `app/Services/Community/PostScreen.php`
- `app/Jobs/TriageCommunityPost.php`
- `app/Http/Controllers/AskController.php`
- `app/Filament/Resources/CommunityPosts/` — the two queues, defaulting to pending
- `database/migrations/2026_08_16_000200_create_the_community_ask_tables.php`
- `app/Services/Community/AskPrefill.php`, `QuestionToPeople.php`, `app/Jobs/SendQuestionToPeople.php`,
  `app/Mail/PeopleQuestionMail.php`, `resources/views/mail/people-question.blade.php`,
  `app/Http/Controllers/AskPeopleSettingsController.php`, `resources/js/askBrief.ts`
- `database/migrations/2026_09_28_000300_ask_others_reaches_your_people.php`
- `tests/Feature/AskOthersReachTest.php`
- `app/Enums/AskAudience.php`, `app/Services/Community/PeopleQuestions.php`,
  `resources/js/Components/AskShareDialog.tsx`,
  `database/migrations/2026_09_28_001100_a_question_can_ask_only_your_people.php`,
  `tests/Feature/AskYourPeopleTest.php`
- `resources/js/Pages/Ask/Index.tsx`, `Show.tsx`
- `resources/js/Components/CoveIcon.tsx` — the `ask` mark
- `lang/*/site.php` — `ask.*`
- `config/giftcoves.php` — `ai.caps.community_triage`
- `tests/Feature/AskOthersTest.php`, `tests/Feature/CommunityTriageTest.php`

## See also

- [ai-invariant.md](ai-invariant.md) — why the model is only ever reached from a job
- [navigation.md](navigation.md) — what hangs under Discover, and why
- [wishlists.md](wishlists.md) — the moderation-surface reasoning that kept the suggestion `note`
  unbuilt, and which this feature is the considered version of
