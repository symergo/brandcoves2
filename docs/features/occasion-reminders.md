---
name: Occasion reminders
area: Gifting / Notifications
status: Active
date_added: 2026-09-01
---

# "Your mother's birthday is in two weeks."

Three things in this product carry a date, and all three were written, validated
and scrubbed long before anything read them:

| where | column | reminded since |
|---|---|---|
| a person you shop for | `recipients.birthday` | the job's first version |
| a Secret Friend exchange | `secret_santa_groups.exchange_date` | the job's first version |
| the occasion on a list | `wishlists.event_date` | **2026-09-01** |

The third is the one an owner types into the Gelegenheid panel. It was rendered
on the shared page, sat in the sitemap of nothing, and did not cause a single
notification until now.

## The windows are a setting

They were `const LEAD_DAYS = [14, 3]` on the job, so changing them was a deploy
— for a pair of numbers whose right value is a judgement about how people shop
rather than a fact about the code.

`config('giftcoves.reminders.lead_days')`, default **30, 15, 2**, edited at
**Operations → Reminders** and stored in `connector_settings` through
[`ReminderSettingsStore`](../../app/Services/Settings/ReminderSettingsStore.php)
— the same overlay `AiSettingsStore` is, so every existing caller keeps reading
the config and there is one way to ask the question.

A single lead has to be either too early to be useful or too late to be
actionable. Thirty days is "there is time to find something good", fifteen is
"decide", two is "it is now".

**Filtered twice, deliberately.** The store sorts, de-duplicates and caps what an
administrator types; the job filters again on read, because config is also
reachable from `.env`, a test and anything calling `config()->set()` — and a `0`
there would remind everybody about today, every day, forever.

## Fire once per occurrence

A reminder that repeats gets muted, and once muted the *real* one is muted too —
so the failure mode of over-notifying is not noise, it is silence at the moment
that matters. A redeploy replaying a morning is the ordinary way this happens.

Dedupe is `(user, kind, payload->key)` against the notifications already written.
No extra table, and it cannot drift out of step with what was actually
delivered. The key carries the year and the lead, so one date fires at most once
per window per year.

## Email

The in-app inbox is read by somebody who came back to the site, and the whole
premise of a reminder is that they have not. So the same reminder is queued as
[`OccasionReminderMail`](../../app/Mail/OccasionReminderMail.php), controlled by
`giftcoves.reminders.email`.

**The notification row is the ledger for both channels.** Mail goes out only on
the pass that wrote the row — otherwise the first morning after email was
switched on would re-send every reminder ever recorded, and a queue retry after
the row exists would send twice.

**It carries no list contents.** A title, a date, a lead time and a link; never
what is on the list, what has been claimed, or by whom. A reminder lands in an
inbox that may be read on a shared screen or forwarded, and on a wish list the
one person who must not learn what has been bought is the person it is addressed
to. `ListInvitationMail` refuses product data for the same reason.

## Whose date it is decides what the sentence says

On a list **about somebody else** the occasion is the recipient's and the owner
is a co-giver, so "Dad's graduation is in 15 days" is exactly the nudge. On a
**wish list of your own** the occasion is your own event, and the useful sentence
is not "buy something" but "your list is about to matter — is it ready?".

## The owner, and only the owner

The people who most need reminding are whoever claimed something, and they
cannot be reached: a claim is stored as a one-way hash precisely so the list
cannot say who made it — invariant 4. Reaching them would mean undoing the thing
that makes the feature work. An anonymous owner is skipped by `whereNotNull`
rather than deeper in, because `notifications.user_id` is NOT NULL and there is
nowhere to put the row.

## Every string is resolved in the market's language, explicitly

A queued job has no request and therefore no locale: it runs in whatever
`app.locale` says, which is English. Each `__()` is passed the language of the
market the reminder is about, `EventType::label()` gained an optional
`$language` for the same reason, and the mail view sets it again so the button
and the footnote match the sentence above them. A reminder about a Dutch list
arriving half in English is the bug all of that prevents.

## Where it is

| | |
|---|---|
| Job | [app/Jobs/SendOccasionReminders.php](../../app/Jobs/SendOccasionReminders.php) |
| Schedule | [routes/console.php](../../routes/console.php) — daily at 08:10 |
| Settings | [app/Services/Settings/ReminderSettingsStore.php](../../app/Services/Settings/ReminderSettingsStore.php), `config/giftcoves.php` → `reminders` |
| Admin | [app/Filament/Pages/ReminderSettings.php](../../app/Filament/Pages/ReminderSettings.php) — Operations → Reminders |
| Mail | [app/Mail/OccasionReminderMail.php](../../app/Mail/OccasionReminderMail.php), `resources/views/mail/occasion-reminder.blade.php` |
| Copy | `site.reminders.*` — one `lead` with `:days`, replacing the per-value `lead_14` / `lead_3` |
| Tests | [tests/Feature/OccasionReminderTest.php](../../tests/Feature/OccasionReminderTest.php) |

## See also

- [wishlists.md](wishlists.md) — invariant 4, and why claimers are unreachable
- [email-templates.md](email-templates.md) — the reminder's wording is editable
- [secret-santa.md](secret-santa.md) — the exchange date this also watches
