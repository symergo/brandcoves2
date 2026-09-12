---
name: Email templates
area: Content / Operations
status: Active
date_added: 2026-09-01
---

# The words in an email, without a deploy

Page copy has been an editor's since [page templates](page-templates.md)
shipped. Email copy was still a pull request, a build and a release — for one
sentence. That left the strangest split in the product: the words on a screen
somebody *chose* to open were editable, and the words arriving uninvited in
their inbox were a developer's.

## The model

An **overlay**, not a replacement. `lang/{language}/site.php` stays the default
and is never removed; a row in `mail_templates` replaces the subject and the
prose for one template in one language.

| | |
|---|---|
| absent row | the email reads exactly as it ships |
| `enabled = false` | the same, and what was written is kept |
| `enabled = true` | the editor's subject and body, rendered through `mail.templated` |

`enabled` rather than deleting, because an editor with second thoughts at 11pm
should not have to reconstruct the previous version from a screenshot.

**Language sits on the row**, exactly as it does on `page_blocks`. Dutch and
French prose do not decompose the same way, and a shared row with four bodies
forces a translation parity nobody asked for. A missing language is not a hole
either: it falls back to the shipped copy, which is always complete.

## What an editor may change, and what they may not

The **prose**: subject and body. Not the structure — the button, its
destination, the fallback URL line, the layout. Those are the parts that fail
*silently*: a template that lost its button is an email nobody can act on, and a
URL typed into a body is wrong the moment the market changes.

So an override renders through one generic view. The editor writes the
sentences; `UsesTemplate` supplies everything that has to work.

## Two emails are deliberately not offered

- **The Cove digest** carries products. A body is not its content — a list of
  picks is — and an editable paragraph around them would be a text box
  pretending to be the email.
- **The Secret Friend assignment** carries the drawn name. Its entire job is to
  reveal it, and a rewrite that failed to is a broken draw rather than a wording
  choice.

The admin screen says both out loud rather than quietly omitting them.

`MailTemplates::KEYS` is an **allowlist**, so a stray row for an undeclared
template cannot reach an email — asserted by
`a_template_that_is_not_in_the_registry_is_ignored`.

## Placeholders

`:name` style, the same syntax the language files use — an editor who has seen
one has seen both, and a second convention for the same idea is a second thing
to explain. Each template declares the names it can fill, and the admin screen
lists them.

A token that is not declared is **left on the page as written**. A visible
`:whatever` is a bug somebody reports; a silent gap is one nobody notices.

## The fallback is the thing under test

An overlay whose default path is wrong breaks every email at once, silently, for
everybody. So most of `MailTemplateTest` asserts that an untouched install sends
exactly what it always sent — including that it renders the *shipped view*
rather than the generic one.

## Where it is

| | |
|---|---|
| Table | `mail_templates` — [migration](../../database/migrations/2026_09_01_000400_email_copy_becomes_editable.php) |
| Model | [app/Models/MailTemplate.php](../../app/Models/MailTemplate.php) |
| Store | [app/Services/Mail/MailTemplates.php](../../app/Services/Mail/MailTemplates.php) |
| Mailable side | [app/Mail/Concerns/UsesTemplate.php](../../app/Mail/Concerns/UsesTemplate.php), `resources/views/mail/templated.blade.php` |
| Admin | [app/Filament/Pages/EmailTemplates.php](../../app/Filament/Pages/EmailTemplates.php) — Operations → Email templates |
| Wired | `MagicLinkMail`, `CoveConfirmationMail`, `ListInvitationMail`, `OccasionReminderMail` |
| Tests | [tests/Feature/MailTemplateTest.php](../../tests/Feature/MailTemplateTest.php) |

## The look of every mail, 2026-09-12

The masthead and the theme are ours, published from the framework into
`resources/views/vendor/mail`. Laravel's stock header prints `config('app.name')`, which
is whatever `APP_NAME` says in that environment and "Laravel" wherever it is unset; the first
list price digest rendered with that word above it. The brand is not an environment variable,
so `html/header.blade.php` writes the mark (`/icons/giftcoves-512.png`, the PNG because mail
clients render SVG unreliably) and the word GiftCoves once, and `message.blade.php` does the
same in the footer.

`html/themes/default.css` carries the site palette from `resources/css/app.css`: accent links
without underline, a tinted table header row with a rule between rows, the accent button.
It is **not an external stylesheet**: the renderer inlines every rule into `style` attributes
at send time, so a mail carries its own styling and the only `<style>` block left in the head
holds the two mobile media queries. The file has to stay plain CSS for that reason: no
imports, no variables.

The theme applies to every markdown mail, editable or not, which is the point: the words may
be an editor's, the frame is one frame.

## See also

- [page-templates.md](page-templates.md) — the same idea for pages, and where the
  language-on-the-row reasoning comes from
- [occasion-reminders.md](occasion-reminders.md) — one of the four
- [cove-subscriptions.md](cove-subscriptions.md) — the confirm mail, and the
  digest that is not editable
