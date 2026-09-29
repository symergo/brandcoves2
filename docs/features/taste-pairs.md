---
name: Taste as pairs of opposites (Handig/Leuk/Mooi and values removed)
area: Gifting
status: Active
date_added: 2026-09-29
---

# Taste as pairs of opposites

> **Words, to avoid a mix-up:** to the owner "the vibe" means the pairs of opposites, and they
> stay ("the vibe should not be removed", confirmed 2026-09-29). What was removed is the old
> three-word question the code called `vibe` (practical / playful / beautiful).

**Since 2026-09-29 a person's taste is one thing: which way it goes on the pairs of opposites**
(Handig | Design, Modern | Vintage, Sober | Kleurrijk, Natuurlijk | Technisch, …; `App\Enums\Preference`).
Two other taste questions were removed everywhere, on the owner's word:

- **"Hoe mag het voelen?"**, the vibe: practical / playful / beautiful (Handig / Leuk / Mooi).
  Owner: "remove also 'hoe mag het voelen' everywhere, this should be covered by the vibes", the
  "vibes" being the pairs, which say how a present should feel far more precisely than three words.
  Confirmed when asked: the three words go, "the vibe pairs should stay".
- **"Waar hecht je waarde aan?"**, the values: sustainable / local / handmade. Owner: "remove values
  everywhere". Feeds rarely say either, so the signal was weak and mostly guessed from title words.

## What "everywhere" covered

- **Every form**: Find a gift (its old vibe step now holds only the pairs, "Welke kant gaat hun
  smaak op?"), the person page's "Over" form, the self-describe link (`/for/{token}`), Ask others,
  My taste, This or that's result, the "why this fits" reasons under an idea.
- **How ideas are chosen**: the engine no longer scores either one or names either as a reason;
  This or that and Swipe gifts no longer learn them; a saved person, a gift profile card, a Cove
  plan's brief and your own taste no longer carry them. An old brief that still has `vibe` or
  `values` keys is read without them rather than refused.
- **Tagging**: `vibe:` and `values:` are no longer in `GiftTags::vocabulary()`, so the tag API
  refuses them and the admin tagging brief no longer asks for them.

## Where the weight went

Scores are only ever compared with each other (no fixed threshold anywhere), so moving points is
safe. The vibe's weight went to the pairs, which now carry the feel; the values' weight was not
handed on:

| Profile | Before | After |
|---|---|---|
| default | interest 40, budget 20, surprise 20, vibe 10, values 10 | interest 50, budget 20, surprise 20, preference 10 |
| for someone | preference 5, vibe 10, values 10 | preference 15 |
| for myself | preference 10, vibe 15, values 15 | preference 25 |

## A nicer shape for the pairs

`Components/TastePairs.tsx`, shared by Find a gift and My taste: one pill per pair with its two
ends as halves, so a pair reads as one choice and its ends as each other's opposite. Picking an end
clears the other, picking the lit end again clears the pair, and once three pairs are chosen the
others dim (somebody who picks six has described nothing).

## What is left in the data (expand / contract)

Nothing reads or writes these for gifting any more, and they stay until a later release drops
them, so a rollback never meets a schema it cannot read:

- `recipients.vibe`, `recipients.values`; `user_tastes.vibe`, `user_tastes.values` (written empty);
  `community_questions.vibe`, `community_questions.values` (old questions no longer show them);
  the `vibe` / `values` keys inside `gift_profile_cards.profile`, `cove_plans.brief`,
  `gift_landings.brief`.
- `vibe:` and `values:` tags already on products.
- `App\Enums\Vibe` stays for `community_questions.vibe`'s cast and the `gift_angles.vibe` column
  (`AngleMap`, `WidenGiftAngles`), which is scheduled without a vibe and so only ever builds the
  "any vibe" rows. `gift.vibes.*` and `gift.values.*` strings stay for the enum's label.

A later cleanup can drop those columns and keys, the vibe rows of `gift_angles`, and the enum.
