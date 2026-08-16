---
name: How well do you know them?
area: Gifting / Growth
status: Active
date_added: 2026-08-09
---

# "How well do you know them?"

Four products, one of them really on their list. Pick it. Five rounds, a score,
a shareable grid.

The only feature here with no v1 equivalent, and it earns its place by solving
the hardest problem in the whole product: **nobody fills in a wishlist.** A list
that exists only to help other people is a chore. A list your friends compete on
is a reason to build one.

## Why this shape

The Daily Cove already proved the loop on this codebase, and the reasoning in
[daily-cove.md](daily-cove.md) transfers wholesale:

- The share is a **score, not a link-beg**. Nobody feels marketed to by a row of
  squares.
- It carries **no spoiler**, so posting it costs the poster nothing.
- It is powered by an asset a content site does not have — here, that we hold the
  list.

The grid follows `GuessBand::emoji()`'s discipline: readable without colour, and
it has to survive being pasted into a plain-text message.

Everyone playing one quiz answers the same questions, so a posted result is a
conversation rather than a broadcast. That is why **the rounds are generated once
and stored** on `list_quizzes.rounds` rather than regenerated per player — and it
is also what makes two scores comparable at all.

## The decision that makes or breaks it: distractors

A quiz is only a quiz if the wrong answers are plausible. Drawing random
`giftable` products makes every round trivial — nobody confuses a chainsaw for a
pair of earrings — and **a game you cannot lose is not worth sharing**, which
removes the entire reason the feature exists.

So distractors are near-misses, chosen with the *same* similarity function the
suggestion engine uses to break up near-duplicates: category 0.6, brand 0.2,
Jaccard title overlap 0.4, plus a nudge for a price within 25%. That function
exists to push similar things apart; here it runs backwards to pull them
together. **Reusing it means there is one notion of "alike" in the codebase
rather than two that can drift.**

`every_distractor_is_plausible` asserts a floor on that similarity. It is the
test that stops the game silently becoming trivial as the catalogue shifts —
which would look exactly like a working feature.

Two smaller rules in the same spirit:

- **Nothing else from the list may be a wrong answer.** Two correct options makes
  a round unwinnable, which reads to the player as a bug in the scoring.
- **A round is dropped rather than padded.** Too few plausible candidates and the
  round is skipped; a round with two options is a coin toss.

## Rules that are not negotiable

- **The owner cannot play their own quiz.** Meaningless, and it must never become
  a channel that tells them anything about what has been claimed.
- **It runs only on a list that is already shared.** A quiz reveals what is on
  the list, so publishing one over a private list would be a leak that never went
  through the sharing switch. Un-sharing the list takes the quiz with it.
- **No claim state anywhere.** Invariant #4 covers the quiz exactly as it covers
  the list.
- **The payload never contains the answer.** `ListQuiz::questions()` strips it.
  Obvious, and exactly the sort of thing that survives into production because
  the page looks right.
- **Below five items, no quiz.** Say so rather than shipping a two-question one —
  `DigestBuilder` refuses to send an empty digest for the same reason: a thin
  version teaches people the thing is not worth opening, which is the one
  irreversible thing a shareable artefact can do.
- **One attempt per player.** Replaying until you score five out of five is not a
  score anybody would want to post.

Playable signed-out, on the anonymous cookie identity via
`Owner::identityHash('quiz')` — the purpose salt is why that method takes an
argument. Asking for a signup before the first guess loses the player, and the
share artefact is worthless if nobody ever gets one.

## Where it ends

On the list. Every wrong answer is a thing they genuinely want, one tap from
being claimed. **The game's commercial job is to end on a gift somebody buys** —
without that it is a toy.

The owner sees an aggregate ("7 played, average 3/5") carrying nothing about
claims and nothing about who played.

## Files

- `app/Services/Gift/QuizBuilder.php` — pure; owns the distractor rules
- `app/Models/ListQuiz.php`, `ListQuizAttempt.php`
- `app/Http/Controllers/ListQuizController.php`
- `resources/js/Pages/Quiz/Play.tsx`
- `tests/Feature/ListQuizTest.php`

## Later

**Match-the-gift-to-the-person** across a family or a Secret Santa group — a set
of people who each have a list — follows almost free from this machinery, but
only after the one-person version has proven itself.


## One go each, enforced

Fixed 2026-08-16. The docblock above the attempt write said *"Replaying until you score five out of
five is not a score anybody would want to post"* — and the code used `updateOrCreate`, so a replay
silently overwrote the first score. The intent was documented and the implementation did the
opposite.

`quiz.play_again` — *"You have already played. One go each, otherwise the score means nothing."* —
turned out not to be a dead button label but the **refusal message**, written and never wired. It is
wired now; no new copy was needed.

The bound is honest rather than absolute: a signed-out attempt hangs off the anonymous cookie, so
this stops an accidental replay and a casual second go. Somebody determined to clear their cookies
gets another turn, and no amount of server-side work changes that for a game deliberately playable
without an account.

Separately, the "back to the list" link on the result screen was labelled `lists.share` — "Share",
the name of a different control on a different page. It uses `lists.view_list`, which already
existed in all four languages.
