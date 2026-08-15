---
name: Cove subscriptions
area: Discovery / Email
status: Built
date_added: 2026-08-09
---

# Cove subscriptions

**A daily teaser email for the Daily Cove: double opt-in, one-click unsubscribe, and no Amazon
product data anywhere in it.**

## Why a teaser and not the edition

Two separate Amazon rules apply to email, and dropping the affiliate link clears only one of them.

| Rule | What it restricts | Does linking to our own page help? |
|---|---|---|
| Associates Operating Agreement | Special Links in email | Yes |
| PA-API licence | *Product Advertising Content* — titles, images, prices — displayed anywhere but your own site | **No.** The restriction is on the content, not the destination |

So an email carrying an Amazon product's title breaches the second rule even when every link points at
giftcoves.com. See [amazon-compliance.md](amazon-compliance.md).

The email therefore carries our own words, up to four non-Amazon finds and one
link. **A digest with nothing to filter cannot be got wrong later** — the alternative, a full edition
with Amazon items stripped, makes every future template inherit a filter someone has to remember.

### Links go to a barcode search

```
/{market}/search?q={ean}
```

Not a product page and certainly not a merchant. `SearchService` treats a GTIN as an exact identity
*and* queries the live sources, so the reader lands on the full comparison — Amazon included, fetched
live, on our page where it is licensed to appear. The email itself carries a number and our own words.

For a group identified by `brand|title` there is no barcode, so the link falls back to the product
page: sending someone to a text search of a product title is a worse page than the product.

### The rule the builder enforces

> A product may be named in the email only when we hold that name from a **non-Amazon** source.

`DigestBuilder::mayName()` asks the *offers behind the group*, not the group. The group's denormalised
title came from whichever offer won, and if that was Amazon then the title is Product Advertising
Content wherever it appears — putting it next to a compliant link does not launder it.

Excluded finds are **counted, not silently dropped**: "and three more on the page" is both true and a
reason to click, and it means an edition that is mostly Amazon still produces a sendable email.

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
