# Feedback

One page, in the main menu: **tell us what is wrong**. No account, one required field.

- Page: [`Feedback.tsx`](../../resources/js/Pages/Feedback.tsx),
  [`FeedbackController`](../../app/Http/Controllers/FeedbackController.php)
- Model / table: [`Feedback`](../../app/Models/Feedback.php), `feedback`
- Admin queue: [`FeedbackResource`](../../app/Filament/Resources/Feedback/FeedbackResource.php)
- Tests: [`FeedbackTest`](../../tests/Feature/FeedbackTest.php)

## Why the site needs one

Every quality problem this catalogue has is visible to a visitor long before it is visible to us: a
price that moved after ingestion, a dead affiliate link, a product filed under the wrong brand, a
translation that reads like a machine wrote it. Until now the only channel was the imprint address,
which almost nobody uses, so those errors simply stayed.

## Decisions

**In the header, not the footer.** The footer is found by people looking for it. The header is found
by people who have *just hit the problem*, which is the only moment the report actually gets written.

**A page, not a floating widget.** A widget is permanent chrome on every screen in service of an
action almost nobody takes on a given visit — and it cannot be linked to.

**No account.** The reports worth having come from people annoyed enough to type and not invested
enough to register.

**One required field.** The message. The page is prefilled from the `Referer` (host-checked, query
string dropped — see below) and editable; the address is optional. A report form that opens on five
required fields collects reports only from people already determined to file one, which is not the
population worth hearing from.

**The address hint sits next to the field**, not in the privacy policy: "only so we can reply to
this" is the question being asked at the moment the cursor is in the box.

## Spam, and why there is no captcha

A per-IP rate limit (5/hour) and a honeypot field. This form is used a handful of times a day at
most, and a challenge in front of it costs every honest reporter more than the spam costs us.

**A rejected submission is answered exactly like an accepted one.** Past the limit the response is
still the thank-you. Telling a script which of its attempts landed is how it learns to tune itself,
and a human who has hit the limit is better served by "thanks" than by an error about a quota they
did not know existed. The rate limit therefore lives in the controller rather than in `throttle`
middleware, which would answer 429.

The honeypot is `website`, hidden from people and from screen readers, `tabIndex={-1}`,
`autoComplete="off"`. Validated as `max:0` so a bot gets an ordinary 422.

## `Referer` is visitor-controlled

`FeedbackController::refererPath()` checks the host against the request's own before using it.
Without that check an off-site link could put any string it liked into a field the page renders back
to the visitor. The query string is dropped: it adds nothing to a bug report and can carry whatever
was typed somewhere else on the site.

## Personal data

The email column is nullable and the message is free text, so both are personal data the moment
somebody uses them that way — a free-text field is whatever the person put in it, which is sometimes
their own name.

- **Retention: 365 days**, in `PrunePersonalDataCommand::RETENTION['feedback']`, enforced nightly.
  Message and address are deleted together rather than the message being kept as anonymised prose.
- Named in both privacy policies — the legal-basis table (Art. 6(1)(f)) and the retention table.
  `LegalPagesTest` asserts the published windows are the ones the code enforces.
- **No IP address and no user agent are stored.** Neither answers a question about the feedback; both
  would make this a log of who complained. The IP is used for the rate limit and hashed into the
  limiter key, never written to a row.
- `user_id` is `nullOnDelete`: deleting an account must not delete the bug report it left behind.
  The report is about the site, and it is anonymous once the account is gone.

## The admin queue is half the feature

A feedback form with nowhere to read it throws messages away politely, which is worse than not having
one — the visitor spent the effort and believes somebody will look. `FeedbackResource` is a read-only
list with a navigation badge counting the unhandled rows, defaulting to the waiting ones. `handled`
and `reopen` are both actions, because "handled" is a human judgement and the default filter hides
what it marks: without an undo, one mis-click removes a report from the only view of it.

`Feedback::$hidden` keeps `email` out of anything the site serialises; the panel reads the attribute
directly, which is the one place it is meant to be legible.
