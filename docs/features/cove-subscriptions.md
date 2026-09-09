---
name: Cove subscriptions
area: Discovery / Email
status: Built
date_added: 2026-08-09
---

# Cove subscriptions

**The Daily Cove as an email: the whole article with its links, the price list under it, double
opt-in, one-click unsubscribe, and no Amazon product data anywhere in it.**

## What the email carries

The edition's own words in full, one paragraph after another, with the product tokens resolved to
links on the product page. Under the prose, every product the email may name, with its lowest price
and how many shops carry it. Then the button to the page.

Until 2026-09-09 it was a teaser: the first paragraph, four bare titles and a button. Read next to
the page it linked to, that looked like a mail that had been cut off, and the owner said so. The
prose is ours, so there was never a reason to withhold it; the teaser was a way of keeping Amazon out
of the email, and the rule below does that on its own.

### What stays out, and why

Two separate Amazon rules apply to email, and dropping the affiliate link clears only one of them.

| Rule | What it restricts | Does linking to our own page help? |
|---|---|---|
| Associates Operating Agreement | Special Links in email | Yes |
| PA-API licence | *Product Advertising Content* — titles, images, prices — displayed anywhere but your own site | **No.** The restriction is on the content, not the destination |

So an email carrying an Amazon product's title breaches the second rule even when every link points at
giftcoves.com. See [amazon-compliance.md](amazon-compliance.md).

> A product may be named in the email only when we hold that name from a **non-Amazon** source.

`DigestBuilder::mayName()` asks the *offers behind the group*, not the group. The group's denormalised
title came from whichever offer won, and if that was Amazon then the title is Product Advertising
Content wherever it appears — putting it next to a compliant link does not launder it.

An Amazon-only pick is therefore left out of the price list, and its token in the prose is reduced to
the writer's own label with no link. The paragraph about it is still sent: those are our sentences,
not Amazon's data, and the page renders the same paragraph. Excluded finds are **counted, not
silently dropped**: "and three more on the page" is both true and a reason to click, and it means an
edition that is mostly Amazon still produces a sendable email.

### The prose is rendered by the page's renderer

`DigestBuilder` hands the editorial to `CoveMarkup::paragraphs()` with the same `Allowlist::full()`
the Cove page uses, narrowed in one way: the product allowlist holds only the groups the email may
name. A token for an Amazon-only pick is rejected by the renderer and degrades to its label, exactly
as a hallucinated brand does on the page. The digest knows nothing about the token grammar, and a link
that works on the page works in the mail.

The paragraphs come back as HTML and the template prints them as HTML blocks, not Markdown. That is
deliberate twice over: the renderer has already escaped and linked them, and a feed title with a stray
`*` or `_` in it must not be read as emphasis by the mail's Markdown pass. The renderer writes
site-relative paths, which a mail client has no origin to resolve against, so the builder pins them to
`APP_URL` before they leave.

### Links go to the product page

```
/{market}/p/{id}/{slug}
```

They used to go to `/search?q={ean}`, on the reasoning that the search page queried Amazon live and so
showed the fuller comparison. It does not: Amazon is not a live search connector, so a barcode search
shows one result under a heading that reads "results for 6977728941431", which is what a reader saw
after clicking a product in the mail. The product page holds every offer we have, re-checks bol at
render, and carries the Amazon search hand-off for a group with a barcode. It is also the URL the
editorial API already reports for a find. Changed 2026-09-09.

### The footer link is an HTML anchor

The footer sits inside `<small>`, which makes it an HTML block to CommonMark, and CommonMark does not
parse Markdown inside an HTML block. A `[Unsubscribe](url)` written there went out as those literal
characters in every digest sent before 2026-09-09. The RFC 8058 header was always correct, so
one-click unsubscribe in Gmail worked throughout; the visible link did not.

## Double opt-in

A signup creates an unconfirmed row and sends exactly one email. Nothing else is ever sent until
`confirmed_at` is set.

The legal argument is the weaker one — GDPR consent must be demonstrable, and a confirmation click is
the only evidence that survives a complaint. The operational argument matters more day to day: a form
anyone can type any address into is a way to send mail to people who never asked, and the first time
that happens at volume the domain's sending reputation is gone. Recovering one takes months; not
losing it costs a click.

Rate limited **per address as well as per IP**. Per IP alone still allows a distributed signup flood at
one victim's address, which is a mailbombing service with our domain on it.

### Two tokens, different lifetimes

| | Lifetime | Why |
|---|---|---|
| `confirm_token` | 48 hours, single use, cleared on confirmation | A link in an abandoned mailbox must not be able to re-confirm an address that has since left |
| `unsubscribe_token` | Permanent, never rotates | It has to keep working in the footer of an email sent three years ago. An expiring unsubscribe link fails exactly when someone is annoyed enough to use it |

## Leaving

`GET` as well as `POST`, and deliberately not behind a confirmation step. An email client cannot POST
from a footer link, and a reader who cannot leave in one click marks the mail as spam instead — which
costs the sending domain far more than an unsubscribe does. The token is unguessable, so the only
person who can trigger it is someone holding an email we sent.

The POST is RFC 8058 one-click, declared in `List-Unsubscribe` and `List-Unsubscribe-Post`. Gmail and
Yahoo require it of bulk senders, and without the `-Post` header the first one is decorative.

The row survives with a timestamp rather than being deleted: it is the evidence that someone opted
out, and deleting it means a later signup form cannot tell that they did. Re-subscribing reuses the
row, which is what keeps the `(market, email)` unique index — and therefore "one copy of each
edition" — meaningful.

## The form is not an oracle

Every response is identical whatever happened: new address, already confirmed, previously
unsubscribed. Otherwise anyone could type an address into the form, read the response, and learn
whether that person reads this site. Same reasoning as the magic-link flow.

## Sending

`SendCoveDigest`, scheduled at 09:15 — three hours after the build and fifteen minutes after the 09:00
drop. The gap is the point: an email that arrives before the page it links to is a link to a 404 in
every inbox at once, and unlike a broken page a sent email cannot be fixed.

Three guards, each closing a way this could embarrass us:

- **No published edition, no email.** A digest linking to a page that does not exist is worse than
  silence.
- **No sendable content, no email.** `DigestBuilder` returns null when every find is Amazon-sourced and
  A mail that only says "a page exists" teaches people the digest is not worth
  opening — the one irreversible thing a daily email can do.
- **`last_sent_on` per subscriber.** A retried job that already mailed half the list must not mail that
  half again. Written immediately after each send, so a crash costs one duplicate rather than two
  hundred.

Chunked at 200, and one market at a time, four minutes apart, so five sends do not open five SMTP
connections at once.

## Validation

`email:rfc`, deliberately **without** `dns`. A DNS lookup catches typo'd domains and does so with a
blocking network call in the middle of a form submission — variable latency, and a failing resolver
turns "subscribe" into a 422 for everyone. The confirmation email already catches an undeliverable
address: nothing is ever sent to an unconfirmed row, so a bad domain simply never confirms.

## Personal data

`cove_subscribers` holds email addresses, so **`bc:scrub` deletes the table outright** rather than
anonymising it. Everything else the scrubber touches keeps its row because something joins to it;
nothing joins to a subscriber, and a mailing list is the one shape of data where a laptop copy could
genuinely send mail to real people if a misconfigured `MAIL_MAILER` ever pointed at a real transport.

Subscribers are not `users`. Most will never make an account, and requiring signup to receive a daily
email is how you lose the subscription — the same reasoning that lets wishlists work before signup.

## One confirmation, in the card rather than at the top (2026-08-31)

`CoveSubscribe` replaces the form with the confirmation, deliberately: leaving the form visible
invites a second submission, which is a second confirmation email to somebody who already has one.
It read that confirmation from `flash.status` — which the layout's `FlashMessage` also draws, at the
top of `<main>` — so one press produced the identical sentence twice on the same page.

The card keeps it, because that is where the field was. `store()` no longer flashes on any of its
three paths and the component asks `form.wasSuccessful` instead.

**All three returns are still a bare `back()`**, which is the property that matters here: new
address, already subscribed and previously unsubscribed must be indistinguishable from the response,
or the form becomes a way to ask whether an address reads this site. Removing the flash removed it
from all three at once; adding a message to any one of them would break that, and the class docblock
says so.
