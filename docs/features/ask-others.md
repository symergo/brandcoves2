---
name: Ask others
area: Discovery / Community
status: Active
date_added: 2026-08-16
---

# Ask others

A board where somebody describes who they are buying for and other people suggest something.

## The gap it fills

Every other way into this site assumes you can already describe what you want. Search needs a noun.
The Gift Finder needs six answers about a person. A Cove is a theme we chose. "She's turning forty,
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

**The vocabulary is the Gift Finder's own.** `interests` holds `Interest` values and `vibe` a
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

Under **Discover**, in three places: the header menu, a card on `/discover-cove`, and a card in the
front page's Discover band. It is the only entry in any of them whose content comes from other
visitors rather than from us, which is the argument for putting it there — Daily, Surprise and the
Coves are all this site showing you something it chose, and Ask is the one where the answer comes
from a person.

The hub also lists the six most recent questions. An unanswered one is the most effective invitation
the feature has: somebody who happens to know the answer recognises it on sight, which is a far
better reason to click than a card explaining what a question board is.

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

## SEO

The board and its questions are indexable, because a question with good answers on it is exactly the
page that should rank and a login wall is how it never does.

A question is `noindex, follow` until it has **at least one published answer**. Before that it is a
thin page made of one stranger's sentence; it becomes indexable the moment it is worth landing on.

The slug is decoration and the id is identity, exactly as on a product page — `/ask/{id}/{slug}`,
with a stale slug redirecting rather than 404ing, so retitling never strands a shared link.

**Not in the sitemap yet.** `SitemapController` does not know about `/ask`, so questions are
discoverable through the site and through links but are not submitted. That is the obvious next
step and it is deliberately not done here: a sitemap of user-generated pages wants a floor on
quality (answered, and not recently rejected) rather than "every published row", and that is a
decision worth taking on its own.

## Files

- `app/Enums/ModerationStatus.php`
- `app/Models/CommunityQuestion.php`, `CommunityAnswer.php`, `CommunityAnswerPick.php`
- `app/Services/Community/PostScreen.php`
- `app/Jobs/TriageCommunityPost.php`
- `app/Http/Controllers/AskController.php`
- `app/Filament/Resources/CommunityPosts/` — the two queues, defaulting to pending
- `database/migrations/2026_08_16_000200_create_the_community_ask_tables.php`
- `resources/js/Pages/Ask/Index.tsx`, `Show.tsx`
- `resources/js/Components/CoveIcon.tsx`, `CoveIllustration.tsx` — the `ask` mark
- `lang/*/site.php` — `ask.*`
- `config/giftcoves.php` — `ai.caps.community_triage`
- `tests/Feature/AskOthersTest.php`, `tests/Feature/CommunityTriageTest.php`

## See also

- [ai-invariant.md](ai-invariant.md) — why the model is only ever reached from a job
- [navigation.md](navigation.md) — what hangs under Discover, and why
- [wishlists.md](wishlists.md) — the moderation-surface reasoning that kept the suggestion `note`
  unbuilt, and which this feature is the considered version of
