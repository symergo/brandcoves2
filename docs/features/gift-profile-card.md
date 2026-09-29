---
name: Gift profile card
area: Gifting
status: Active
date_added: 2026-09-26
---

# Gift profile card: "My gift profile: coffee, walking, around €30 to €60"

> **Since 2026-09-29** there is no vibe (Handig / Leuk / Mooi) and no values (sustainable / local /
> handmade) anywhere; taste is the pairs of opposites only. See [taste-pairs.md](taste-pairs.md).
> Mentions of either below are history.

The owner's request (2026-09-26): after somebody plays [This or that](taste-discovery.md) about
themselves, offer a card with a public link. Opening the link lands in Find a gift filled in
with that profile, no account needed, and invites the visitor to make their own card. Opt-in,
revocable, no name unless the owner types one, not indexed.

Why: the people who buy for you are the ones who most need to know your taste, and "send me a
link" is how that already travels. A card is also the one This or that result that brings a new
visitor in: the person who opens it is a giver, and the button next to the ideas is "Make your own
card".

## Where it is

| | |
|---|---|
| Offered | the result of This or that when choosing for yourself: "Me" at `/gift/taste`, and the person's own `/for/{token}/taste` (`MakeCard` in `Gift/Taste.tsx`) |
| Routes | `POST /{market}/gift/card` (make, `throttle:10,1`), `GET` and `DELETE /{market}/gift/card/{token}` |
| Controller | `App\Http\Controllers\GiftProfileCardController` |
| Service | `App\Services\Gift\GiftProfile` (what is stored, Find a gift's answers, the summary line) |
| Page | Find a gift (`Gift/Wizard.tsx`) with a `card` prop, rendered by `GiftProfileCardBanner` |
| Table | `gift_profile_cards` |
| Copy | `site.gift.card.*`, `site.help.find_taste_card`, four languages |
| Tests | `tests/Feature/GiftProfileCardTest.php`, `tests/Unit/GiftProfileTest.php` |

## Decisions, and why

- **Opt-in, one press.** Nothing is stored until the person presses "Make my card". The name field
  is empty by default and optional; without a name the card is titled "A gift profile".
- **Worked out on the server, from the choices.** The page sends its choices, never a profile, the
  same rule as saving a result: the profile is computed from the catalogue at the moment the card
  is made. A request that also sends a `profile` or `interests` is ignored (tested).
- **The conclusion, never the choices.** The row holds interests, what to leave out, a budget, and
  where the tags gave one a vibe, taste poles and values (`GiftProfile::fromProfile`). No product
  ids, no scores, no round count. Every value is checked again on the way out against the lists the
  Find a gift validates with (`GiftProfile::clean`), so a card stored under yesterday's vocabulary
  cannot break today's form.
- **Opening seeds the wizard with a GET.** `/gift/card/{token}` renders Find a gift with its
  answers filled in (`brief`), euros for the budget as the wizard's field is, and an avoided
  interest in its tag spelling `interest:gaming` so the engine excludes it by tag
  ([taste-discovery.md](taste-discovery.md)). The visitor's own saved people are not offered on
  this page (`recipients` is empty): they are looking at somebody else's card. "See ideas" posts
  the answers the ordinary way; Find a gift's own rule, that a brief travels in a POST and not
  in a URL, is kept for everything the visitor changes. The card's token is in the URL, and that
  is what the maker chose to share.
- **Not indexed.** `noindex, nofollow` in the server's meta and in the page's head. It is somebody's
  taste, not a page for search.
- **Revocable, without an account.** Making a card returns a random key (40 characters); only its
  SHA-256 is stored (`owner_key_hash`, `$hidden`). The key is also kept in the maker's session, so
  the card itself shows "Remove this card" in the same browser, and a signed-in maker is recognised
  by their account anywhere. A wrong key and a missing card both answer 404, so the delete route
  cannot be used to find out which tokens exist.
- **Shows no owner.** `owner_user_id` is `$hidden`; the page carries only the title, the summary
  line and the links.
- **The summary line** is interests by their label in the reader's language, then the budget with
  This or that's own wording ("around €30 to €60"). It is also the page's meta description, so a
  link pasted into a chat previews as the card.
- **No AI** (invariant 1): a lookup and a render.

## Retention

A card is meant to be opened, so it lives while people open it: `last_opened_at` is refreshed at
most once a day when the card is shown, and `bc:prune-personal-data` removes a card nobody has
opened for 365 days. A taste from a year ago is out of date anyway, and one that nobody opened in a
year has done its job or never will. The maker can remove it any time before that. Both are stated
on the privacy page (en and nl), where the card is listed under consent (Art. 6(1)(a)), withdrawn by
removing it. Deleting an account deletes its cards (the foreign key cascades). `bc:scrub` rotates
the tokens and clears the names after a production dump is restored.
