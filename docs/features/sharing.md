---
name: Sharing
area: Gifting / Growth
status: Active
date_added: 2026-08-09
---

# Sharing

Getting a link out of the site and into a conversation. One menu
([ShareMenu](../../resources/js/Components/ShareMenu.tsx)) and one row
([ShareRow](../../resources/js/Components/ShareRow.tsx)), used by every surface whose whole purpose is
handing something to another person: a wishlist, a quiz score, a Secret Santa invite, and a recipient
link.

This is a growth path, not a convenience. A gift list that never leaves the browser has no second
visitor, and every one of these screens exists to produce one.

## The bug that produced this

`navigator.share` is the right primitive where it exists — it offers every app on the device, in the
order the person actually uses them — and the first version used it alone, falling back to copying
the link when it was missing.

`navigator.share` does not exist on most desktop browsers, and **desktop is where lists get built**.
So on a laptop the button said "Share", silently copied, showed "Copied", and WhatsApp was nowhere.
It looked like it worked, which is why it survived to staging.

The quiz result was worse. That screen had its own copy of the same logic, so the one page whose
entire point is posting a score to friends could not post it anywhere. Two implementations of one
idea meant the second one never got the fix the first one needed.

Now: **native sheet first when the device has one, explicit channels always.**

## The channels are not interchangeable

Treating them as one "share a link" abstraction is how a share button posts an empty message. What
each one actually accepts:

| Channel | Accepts | Consequence |
|---|---|---|
| WhatsApp | arbitrary text | The link rides inside the message. **The one that matters** — a gift list lives in a group chat |
| Telegram | url + text, separately | Both passed |
| Facebook | a URL, nothing else | Prefilled text was removed years ago and is dropped *silently*, so a score passed to it posts a bare link |
| X | url + text, separately | Both passed |
| Email | subject + body | Text becomes the subject, text-plus-link the body |
| Instagram | **nothing** | No URL scheme accepts a link or a caption from the web |

Instagram is offered as "copy for Instagram" rather than as a button. A button that appears to work
and does nothing is worse than an honest line of text, and this is the case where there is no
technical option to fall back to — the limitation is Instagram's, and the only thing we control is
whether we admit it.

## Why the link is visible, not just copyable

`ShareRow` shows the URL in a `<code>` next to the buttons. People check a URL before pasting it into
a group chat, and a button that claims to have copied something is worth less than the thing itself.

It was extracted because the Secret Santa invite was a lone "copy" button with the URL nowhere in
sight, while a wishlist showed the link, offered the sheet and confirmed the copy. Two ways of doing
one thing on one site is an interface bug even when both work, and the sparser one was on the screen
whose entire purpose is getting a link to other people.

## `navigator` is read after mount

`navigator` does not exist during SSR, and reading it while rendering would make the server and the
client disagree about whether the native option is there — a hydration mismatch on a component whose
only job is to be pressed. The capability check therefore runs in a `useEffect`, so the first paint
shows the channel list and the native item appears when the client confirms it exists.

## What sharing does not do

**It does not decide who may see the thing.** Visibility lives on the record; see
[wishlists.md](wishlists.md) for the claim rules and invariant 4 — the owner of a list must never
learn what has been claimed from it. `ShareMenu` takes a URL that the page has already decided is
shareable, and a private list simply has no share URL to give it.

## Files

- `resources/js/Components/ShareMenu.tsx` — the menu, the channels and their quirks
- `resources/js/Components/ShareRow.tsx` — link, share, copy
- `resources/js/Pages/Lists/Show.tsx` — list, quiz and recipient links
- `resources/js/Pages/Quiz/Play.tsx` — the score
- `resources/js/Pages/Santa/Group.tsx` — the invite
- `lang/*/site.php` — `lists.share_*`, `lists.copy_link`, `lists.copied`

## See also

- [social-cards.md](social-cards.md) — what the shared link renders as once it lands
- [list-quiz.md](list-quiz.md) — the score being shared
- [secret-santa.md](secret-santa.md) — the invite being shared
