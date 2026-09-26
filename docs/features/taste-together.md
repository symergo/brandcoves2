---
name: This or that together
area: Gifting / Lists
status: Active
date_added: 2026-09-26
---

# This or that together: several people, one profile

The owner's request (2026-09-26): "several people play This or that about the same person, and the
answers combine into one profile", for example everyone on a group list playing about the birthday
person, or a giver inviting a friend.

[This or that](taste-discovery.md) is choosing between two products a dozen times, from which we
work out interests, a budget and what to leave out. One giver's guess is one person's guess. Five
people who each know the birthday person a little are better evidence together than any of them
alone, and a group list already has those five people.

## Where it is

| | |
|---|---|
| Giver's side | a card on the list page for the list's person (`Lists/Show`, `TasteTogetherPanel`), for the list's owner only; on group lists and on lists for somebody |
| Giver's routes | `POST` / `DELETE /{market}/recipients/{id}/taste-together`, `POST .../apply` (behind `auth`, owner-scoped, `throttle:20,1`) |
| Player's page | `/{market}/t/{token}`: the ordinary This or that page in mode `together` (`Gift/Taste.tsx`) |
| Controller | `App\Http\Controllers\TasteTogetherController` (extends `TasteController` for its rounds, validation and result) |
| Service | `App\Services\Gift\TasteTogether`; combining is `TasteProfiler::combined()` |
| Tables | `taste_invites` (the link), `taste_runs` (one per player) |
| Copy | `site.gift.together.*`, `site.help.find_taste_together`, four languages |
| Tests | `tests/Feature/TasteTogetherTest.php`, the `combined` cases in `tests/Unit/TasteProfilerTest.php` |

## How it works

1. The giver presses "Make a link" on the list for the person. One open link per person at a
   time: pressing again hands back the same link, so the answers are not split over two.
2. Whoever holds the link plays at `/t/{token}` without an account. The page shows the person's
   name as the giver saved it and nothing else the giver wrote (no notes, no stored taste).
3. At the end the player's choices are kept as one row in `taste_runs`, and the player sees their
   own reading, never the combined one.
4. The giver sees how many played and what they found together, and can press "Add this to
   :name".
5. "Stop the link" makes the address answer 404. What was chosen through it stays visible to the
   giver until it is pruned. A new link starts afresh, with a new address and no answers.

## Decisions, and why

- **Choices are stored, never a profile.** A run holds product ids and what was pressed, only the
  rounds that answered something. The combined profile is worked out again from every run
  whenever the giver looks, by reading the tags and prices from the catalogue
  (`TasteChoiceReader`). The same rule as the one-person tool: a request can say "I picked 41",
  never "41 is a cooking present".
- **Combining is one tally over all runs** (`TasteProfiler::combined`), as if one person had played
  every round. The existing rules already make that fair: an interest needs two good rounds, so two
  friends who each picked cooking once agree on it where neither alone would; avoid needs two bad
  rounds and no good one, so one friend's dislike is never enough and one friend's pick overrules
  everybody's dislikes; the budget is the middle half of every price anyone picked. No player
  counts for more than the rounds they played (at most 24, what the page can send).
- **A player never writes to the person.** Only the giver's "Add this to :name" does. A link that
  escaped the group cannot fill somebody's profile with strangers' guesses; at worst it adds runs
  the giver can see the count of, and stop.
- **Adding is exactly "Save for :name"** from the one-person tool (`TasteController::save`): the
  taste through `describeTaste()` as a guess, so it is refused when the person described their own
  taste (`TasteSource::Self`); it adds to what is stored and removes only contradictions
  (`TasteProfile::mergedWith`); the budget is written directly, because what the group will spend
  is the giver's fact, not the person's taste.
- **Nothing about a player reaches the giver.** The giver gets a count and the combined result: no
  names, no times, no run on its own. Each run carries `participant_hash`, the player's cookie
  identity hashed with `taste-together|{invite id}` as the purpose (`Owner::identityHash`). The
  invite in the purpose means the same visitor hashes differently on every link, and no value can
  be matched against a claim, a vote or another link. The hash exists only so a second play from
  the same browser replaces the first rather than counting twice. It is `$hidden` on the model.
  One honest limit: when only one person has played, the "combined" result is that person's
  reading. The player page says the giver sees what was found together, never their name or own
  answers, which stays true.
- **At most 25 players per link** (`TasteTogether::MAX_PARTICIPANTS`). A family or an office is
  under twenty; past that the link has escaped its group. A full link shows a thank-you instead of
  the game, and a finish that arrives after it filled up is shown its result but not kept. The
  count-then-insert runs under a row lock on the invite, so two players finishing at the same
  moment cannot both take the last place.
- **Rate limits.** The player's page shares `/l/{token}`'s 60 a minute; finishing, which writes a
  row, is 10 a minute. The giver's three actions are 20 a minute.
- **On both group lists and lists for somebody.** The owner asked for group lists; a list for
  somebody is the only other page where a giver sees their person, and "invite a friend" was in
  the request too. It is one card for the owner, and never shown to a collaborator.
- **The token is a `ShareCode`** (ten characters, about 50 bits), like a list's share link, for
  the same reason: it is the whole permission to play.
- **No AI** anywhere on these routes (invariant 1). The feature test mocks `AiClient` and asserts
  it is never called.

## Retention

`bc:prune-personal-data` removes a run 180 days after it was last written, then a link that is
older than that and has no runs left, so a link still being played is never cut off. Six months
covers a birthday planned well ahead and is past any one occasion. What the giver added to the
person stays on the person, with the rest of what they keep there. Both windows are stated on the
privacy page (en and nl), and `LegalPagesTest` checks the page and `PrunePersonalDataCommand::RETENTION`
agree. `bc:scrub` rotates the tokens after a production dump is restored.
