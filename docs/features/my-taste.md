---
name: My taste
area: Gifting / People
status: Active
date_added: 2026-09-29
---

# My taste ("Mijn smaak")

**Your own gift taste, kept on your account, which your friends' gift searches start from.** The
owner asked "where can the user set his/her gift tastes?" (2026-09-29). Until then the answer was
"only indirectly": on the `/for/{token}` link a giver sends you, which writes onto *that giver's*
saved person, or on a gift profile card you hand out. Nothing a person kept about themselves.

It is at `/{market}/my-taste`, in the account menu after My people (`myCovesLinks`).

## What it holds, and what it does not

Interests (the chips and your own words, up to 8), how it should feel (one vibe), which way your
taste goes (up to 3 poles, one per axis), what matters to you (values), what to avoid, and an age
group. The same vocabularies and bounds as Find a gift's own answers, so a value here is the value
the engine reads.

**No budget, on the owner's word** ("remove budget, this is a filter that depends on the giver").
What somebody spends is decided in their own search; the budget step stays theirs.

One row per account in `user_tastes`; a taste that says nothing leaves no row, and clearing it is
how you withdraw the consent the privacy policy names. Deleting the account deletes it (cascade).

## Three ways to fill it

- **Fill it in** on the page.
- **Play This or that for yourself** and press "Keep in My taste" on the result (`POST
  /my-taste/learn`). As with every saved This or that result, the page sends its choices and the
  taste is worked out on the server from the catalogue, then merged into what you already said
  (`TasteProfile::mergedWith`). The age is left alone.
- **Swipe for yourself, signed in** (owner, the same day: "include results from swiping and
  vibe"). Swipe gifts sends its swipes to the same endpoint as one-card choices, right a like and
  left a dislike, every ten swipes and on Stop, the latest hundred each time (hence the endpoint's
  cap of 100). The profiler reads interests, **the vibe** and taste poles from them as from This or
  that's single cards: a vibe counts only when it clearly leads, and an interest passed on twice
  lands under "rather not". Merging makes a resend harmless. The popup says so in its top bar
  ("also to My taste"), and only then: for somebody else, or signed out, nothing is kept.

## Who reads it (`OwnTaste`)

- **Your friends, and only while you are friends.** When a giver's saved person is linked to your
  account ("Dit ben ik", or a friend saved from the people cards) *and* the two of you are friends
  on GiftCoves, `TasteBrief::fromRecipient` lays your taste over what the giver noted. That one
  place covers Find a gift, the reminder email's ideas and the person's page. The friendship is
  checked at every read, because a link outlives an unfriending.
- **Your word goes over the giver's**, the same order `TasteSource::Self` has over `Suggested`:
  every part you filled in replaces theirs, a part you left empty leaves theirs. What to avoid is
  the exception: both lists count, since leaving out something either of you named costs nothing.
  The giver's budget is untouched.
- **The giver sees it filled in.** Find a gift's card for that person carries your taste
  (`ownTaste: true`), so choosing them fills the questions with your answers.
- **You, for yourself.** "Voor mezelf" in Find a gift fills the questions from it.

## Files

- `database/migrations/2026_09_29_000100_a_person_keeps_their_own_taste.php`, `app/Models/UserTaste.php`
- `app/Services/Gift/OwnTaste.php` — who may read it, and how it lays over a giver's picture
- `app/Services/Gift/TasteBrief.php` (`fromRecipient`), `app/Http/Controllers/GiftController.php`
  (`tasteOf`, `myTaste`)
- `app/Http/Controllers/MyTasteController.php`, `resources/js/Pages/MyTaste.tsx`
- `resources/js/Pages/Gift/Taste.tsx` (`KeepAsMine`), `resources/js/Pages/Gift/Wizard.tsx` (`useMe`)
- `resources/legal/{en,nl}/privacy.md` — the row, the retention and the friends paragraph
- `tests/Feature/MyTasteTest.php`

## Not done

- The gift profile card still exists beside it; it is the thing you hand to somebody who is not
  your friend on GiftCoves.
