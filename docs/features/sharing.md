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

The fix at the time was to add the explicit channels and offer the sheet as the first row of the
menu, labelled "More apps…". That was still backwards, and it stayed that way for a while: a phone —
where sharing actually happens — got a hand-rolled dropdown of five web fallbacks, with the one thing
that knows which apps are installed offered last and described as an afterthought.

Now: **where `navigator.share` exists the button IS the sheet, and there is no menu.** It is not one
more destination in a list, it is a better version of the entire list. The explicit channels are the
desktop control, and neither pretends to be the other.

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

## Why the link is visible, and why it is a field

People check a URL before pasting it into a group chat, and a button that claims to have copied
something is worth less than the thing itself.

`ShareRow` was extracted because the Secret Santa invite was a lone "copy" button with the URL
nowhere in sight, while a wishlist showed the link, offered the sheet and confirmed the copy. Two
ways of doing one thing on one site is an interface bug even when both work, and the sparser one was
on the screen whose entire purpose is getting a link to other people.

It showed the URL in a truncated `<code>`: readable, and nothing else. It is a read-only `<input>`
that selects its whole contents on focus, because the people whose clipboard is unavailable had no
way to get the link at all — you cannot reliably select `text-overflow: ellipsis` text on a phone.
One tap now gives them the browser's own copy.

### Two buttons, two jobs, two different clipboards

Copy is the solid button and Share is the outlined one, because they are not alternatives. Copy is
for the destination this page cannot know about — a work chat, a note to self, a text message — and
is what people reach for most. Share is the one that knows about WhatsApp.

They both said **"Copy link"** and put different things on the clipboard: the row's copied the bare
URL, the one inside the menu copied the URL with a sentence in front of it. Same words, a centimetre
apart, two results. The row copies the link; the menu copies the message, and says so.

### The clipboard is not always there

`navigator.clipboard` is undefined outside a secure context — every plain-http address, including the
LAN one this gets tested on — and it rejects when the document is not focused. Both threw into
nothing: the button did visibly nothing, with no explanation. Now the field is selected and the
status line says to press Ctrl+C, which is one keystroke and is at least true.

### Confirmation is spoken, not swapped into the label

"Copied" replaced the word "Share" for two seconds, which changed the button's width, shuffled the
row around it, and said nothing at all to a reader who cannot see it. It is a `role="status"` line
that holds its height whether or not it has anything to say.

### The menu is a real menu

It carried `role="menu"` and none of the behaviour — which is worse than no role at all, because a
screen reader announces a menu and then the arrow keys scroll the page. Opening it moves focus to the
first destination; ↑/↓/Home/End move between them; Escape and Tab close it and hand focus back to the
button that opened it. The Instagram line sits outside the `role="menu"` element: a paragraph is not
a menu item, and in menu mode a screen reader is entitled to skip anything that is not one — which
would drop the one line there that exists to explain an absence.

Each destination carries a mark ([ShareIcon](../../resources/js/Components/ShareIcon.tsx)), in the
site's own line-art hand rather than the platforms' brand assets. A share menu is the one place icons
earn their keep — the reader is not reading the list, they are looking for the app they already had
in mind — and six official logos would bring six palettes into a design with one accent.

## `navigator` is read after mount

`navigator` does not exist during SSR, and reading it while rendering would make the server and the
client disagree about whether the native option is there — a hydration mismatch on a component whose
only job is to be pressed. The capability check therefore runs in a `useEffect`, so the first paint
shows the channel list and the native item appears when the client confirms it exists.

## The pages a link lands on must be server-renderable

`ShareMenu` was careful about `navigator`; the pages around it were not careful about `window`.

`Lists/Shared`, `Quiz/Play` and `Recipients/SelfDescribe` each read the token straight out of
`window.location.pathname` **in the component body**. `window` does not exist while the server
renders, so all three threw `ReferenceError` and Inertia did what it is designed to do — fell back
to client-side rendering, silently. The pages worked, and worked *badly*: they arrived as an empty
shell that had to download and boot React before showing anything, on precisely the three URLs a
stranger opens from a link they were sent, usually on a phone, always without a warm cache.

Nothing failed loudly, which is why it survived. The proof is a `POST` of a page object to the SSR
bundle:

```bash
node bootstrap/ssr/ssr.js &
curl -s -X POST http://127.0.0.1:13714/render -H 'Content-Type: application/json' --data @page.json
# before: {"error":"window is not defined","component":"Lists/Shared", …}
# after:  {"head":[…],"body":"<script data-page=…"}
```

The token comes from `usePage().url`, which Inertia hands to both renderers. The quiz's share URL is
now minted server-side as `quiz.shareUrl` rather than read from `window.location.href` — a component
that reaches for `window` to build a link cannot be server-rendered at all.

`window` inside an event handler or a `useEffect` is fine, and stays: those run only in a browser.
The rule is about the render path.

## What sharing does not do

**It does not decide who may see the thing.** Visibility lives on the record; see
[wishlists.md](wishlists.md) for the claim rules and invariant 4 — the owner of a list must never
learn what has been claimed from it. `ShareMenu` takes a URL that the page has already decided is
shareable, and a private list simply has no share URL to give it.

## Files

- `resources/js/Components/ShareMenu.tsx` — the native sheet, or the channels and their quirks
- `resources/js/Components/ShareRow.tsx` — the link field, copy, share
- `resources/js/Components/ShareIcon.tsx` — the destinations, drawn
- `resources/js/Components/ListTools.tsx` — the sharing panel these sit inside; see
  [wishlists.md](wishlists.md)
- `resources/js/Pages/Lists/Show.tsx` — list, quiz and recipient links
- `resources/js/Pages/Quiz/Play.tsx` — the score
- `resources/js/Pages/Santa/Group.tsx` — the invite
- `lang/*/site.php` — `lists.share_*`, `lists.copy_link`, `lists.copy_message`, `lists.copy_manual`,
  `lists.copied`

## See also

- [social-cards.md](social-cards.md) — what the shared link renders as once it lands
- [list-quiz.md](list-quiz.md) — the score being shared
- [secret-santa.md](secret-santa.md) — the invite being shared
