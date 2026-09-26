---
name: Invitation emails
area: Accounts / Email
status: Active
date_added: 2026-09-26
---

# Inviting somebody sends them an email

"Nodig uit op GiftCoves" on My people ([my-people.md](my-people.md)) takes an email address. Until
2026-09-26 nothing was sent to it, and the (i) said so: "We mailen hen niet, dus laat het ze zelf
weten." The owner asked for that sentence to go, for the invitation to be emailed, and for people
who receive one to be able to say it is spam.

The reason nothing used to be sent still stands (see [friends.md](friends.md)): an email to an
address somebody typed is a way to mail a stranger on a member's behalf. So the email comes with
brakes, and most of this document is about them.

## The email

`App\Mail\FriendInviteMail`, view `mail/friend-invite`, strings under `site.invite_mail` in four
languages. In the language of the market the member was on when they invited (the only guess we
have at the other person's).

> **Anna nodigt je uit op GiftCoves**
>
> GiftCoves is een plek voor verlanglijstjes en cadeau-ideeën. Neem je de uitnodiging aan, dan zijn
> jij en Anna verbonden: jij ziet de lijsten die Anna met je deelt, en Anna ziet de lijsten die jij
> deelt.
>
> [Uitnodiging aannemen]
>
> Geen interesse? Dan hoef je niets te doen.
>
> *Je krijgt deze e-mail omdat Anna je e-mailadres op GiftCoves invulde. [Niet gevraagd? Dit is spam]*

The button opens `/{market}/login?email=…`, the sign-in page with the address filled in (the page
drops anything that is not an address). Signing in by magic link creates the account, and
`LinkSharerAsFriend` turns the waiting invitation into the connection on that sign-in, exactly as
before emails existed. Nothing new was needed on that side.

The member's name only: not their address (the reader may be a stranger), nothing from any list.

**The same email for an address with an account and one without.** An existing account is already
connected when the email goes out; its button simply signs them in. Anything that differed would tell
the member which it was, so `FriendInviteMail` takes no argument that depends on it, and
`the_email_and_the_answer_are_the_same_with_or_without_an_account` compares the two rendered emails.

**Queued**, like the list-sharing email (`ListSharer::notify()`), not sent inline like the magic
link: nothing here expires in fifteen minutes, and a slow mail server must not slow the form. A mail
that cannot be queued is logged and the invitation stands.

**Not an editable template** ([email-templates.md](email-templates.md)): the spam line and the
sameness above are what make it safe to send, and an editable body is one edit from breaking either.

## Limits on the member

In `config('giftcoves.invites')`, each with its reasoning in a comment:

| limit | value | what the member sees |
|---|---|---|
| addresses per rolling 24 hours | 20 (`daily_limit`) | an error under the field; nothing recorded |
| emails per address, per member | one per 30 days (`repeat_days`) | "You invited this address in the last 30 days already, so we are not emailing it again." The connection is still made or kept (a birthday typed the second time is saved) |
| their own address | never | "That is your own email address." |

The route's `throttle:10,1` stays on top: it stops speed, the daily limit stops volume.

The own-address answer used to be silent (it returned like every other path). It says so now,
because a member knows their own address and nothing is disclosed; a silent "Invitation sent" to
yourself would be a lie.

`friend_invite_mails` holds one row per address a member invited, with a keyed hash of the address
(never the address), and is what both limits count. It is written whether or not an email went out
(`status`: `sent`, `suppressed`, `sender_muted`), so an address that asked for no emails uses up the
member's day exactly like one that did not. Otherwise the limit itself would tell them.

## "Niet gevraagd? Dit is spam"

In the footer of every invitation email, and as `List-Unsubscribe` + `List-Unsubscribe-Post`
(RFC 8058 one-click), so the mail client's own unsubscribe button does the same thing.

`/{market}/invites/not-wanted/{inviter}/{hash}`, signed (`URL::signedRoute`, no expiry: a link
that dies after a week is one that makes people press the spam button instead). It needs no account.
The signature is what stops anybody silencing another address or complaining against another member;
`a_tampered_spam_link_is_refused` changes each part and expects a 403.

Pressing it:

1. puts the address on the **suppression list** (`invite_suppressions`, a keyed hash, never the
   address). No invitation email from anybody reaches it again. Invitations to it are still
   recorded and still connect people on sign-in, silently, so the member cannot tell;
2. records a **complaint** against the member who sent it (`invite_complaints`, one per member and
   address: pressing twice is one complaint);
3. shows a page in the market's language: "Je krijgt geen uitnodigingen meer via GiftCoves.", that
   the person who invited them is not told, and an **undo** button. Undo removes the suppression and
   withdraws the complaint against that member: somebody who pressed by mistake should not leave a
   mark on the friend who invited them.

### Opening the link is not pressing it

The link in the email opens a page with one button, and the button does it. This is a deliberate
difference from the reminder emails' stop link, which acts on the GET. Company mail filters open
every link in an email to scan it; here a press also counts a complaint against a person, so a GET
that complained would let a virus scanner stop a member's invitations after three colleagues'
scanners had a look. The mail client's one-click button POSTs, which no scanner does, so that path
stays one step. The page reads the current state rather than a flash, so opening the link again
later still shows the truth and the undo.

Both POSTs are exempt from CSRF (`*/invites/not-wanted/*` in `bootstrap/app.php`), like the other
signed stop links: the mail client has no session. The page's buttons are plain HTML forms, so they
work without the page's script.

### Why a hash, and whose key

The suppression list has to outlive everything else we know about an address: it is the record of
somebody who wants nothing from us. Keeping their address to honour that would be the opposite of
what they asked. `InviteMailer::hash()` is an HMAC of the lower-cased address, keyed with
`CLAIM_HASH_SECRET` rather than `APP_KEY` for the reason that secret exists: rotating `APP_KEY` must
not quietly empty the list and start mailing people who asked us to stop. A plain sha256 would be
reversible by hashing a list of addresses.

## Complaints stop a member's emails

At `complaint_limit` (3) complaints, a member's invitations are still recorded and still connect
people, but are no longer emailed (`status = sender_muted`). The member is told nothing: the page
says "Invitation sent" as always. One complaint can be a misunderstanding; three different people
saying so is a pattern.

Admins see it at **/admin, Community > Invitation complaints** (`InviteComplaintResource`): one row
per member with complaints, their count, whether their emails are stopped, how many invitations they
sent in the last 90 days (for scale), and the first and latest complaint. The navigation badge counts
members whose emails are stopped. "Clear complaints" lifts the stop for a member a look shows to be
a misunderstanding; the suppressed addresses stay suppressed.

## Retention

In `bc:prune-personal-data` and in the privacy policy (en, nl), which `LegalPagesTest` holds
together:

| what | kept |
|---|---|
| a pending invitation (`friend_invites`, holds the address) | until the person signs in, or 365 days after the member last invited them (`updated_at`, touched on every invite). It had no window before this |
| the invitation log (`friend_invite_mails`, hash) | 90 days: the limits need 30, the admin screen uses 90 |
| complaints (`invite_complaints`, hash) | 365 days, which also lifts a stop nobody renewed |
| suppressions (`invite_suppressions`, hash) | until undone; never pruned, on purpose |

The published limits (20 a day, 30 days, 3 complaints) are checked against config by
`the_published_invitation_limits_are_the_ones_the_code_applies`. `bc:scrub` empties all three new
tables.

## What this does not close

An address with an account is connected at once and appears on the member's My people straight
away; one without does not. The sentence and the email are identical, the page is not, and it was
not before this change either. Closing it means holding every invitation until the other person
accepts, which is friend requests (see [my-people.md](my-people.md#no-pending-requests)).

A complaint does not delete the pending invitation (`friend_invites`, with the address) that the
member made. The owner's brief was that invitations keep being recorded silently; the pending one
goes after a year, and the privacy policy tells an invited person how to ask for removal sooner.

## Files

| what | where |
|---|---|
| Limits, the send, the spam link, suppression, complaints | [`App\Services\Social\InviteMailer`](../../app/Services/Social/InviteMailer.php) |
| Order of the checks, and what the member is told | [`App\Services\Social\FriendInvites::invite()`](../../app/Services/Social/FriendInvites.php), `App\Enums\InviteOutcome`, `FriendController::store()` |
| The email | `App\Mail\FriendInviteMail`, `resources/views/mail/friend-invite.blade.php` |
| The spam page | `InviteNotWantedController`, `resources/js/Pages/Invites/NotWanted.tsx` |
| Pre-filled sign-in | `MagicLinkController::show()`, `Pages/Auth/Login.tsx` |
| Admin | `App\Filament\Resources\InviteComplaints` |
| Tables | `2026_09_28_000400_invitations_are_emailed` |
| Tests | [`FriendInviteMailTest`](../../tests/Feature/FriendInviteMailTest.php) |

## Invitations landing in spam (2026-09-26)

The first staging tests landed in spam. What was checked: giftcoves.com's SPF allows OVH
(`include:mx.ovh.com ~all`) and staging sends through OVH's mailbox (`ssl0.ovh.net`, as
hello@giftcoves.com), so SPF passes; DMARC is `p=quarantine`; but **no DKIM record exists**, so
nothing signs the mail. Unsigned mail from a young domain, inviting a stranger to click a button, is
exactly what filters distrust. The fix that matters is **enabling DKIM in the OVH control panel**
(the DNS is at OVH, so it adds its own records). The footer link no longer says "spam" ("Niet
gevraagd? Laat het ons weten"): the word itself counts against a mail in several filters. The link
still does exactly the same.
