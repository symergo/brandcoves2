# Accounts in the admin panel

**Status:** Active — added 2026-09-12.
**Where:** `/admin/users`, in the navigation under *Operations > Accounts*.
**Code:** `app/Filament/Resources/Users/`. **Test:** `tests/Feature/UserAdminTest.php`.

## What it does

A list of every account, with an edit page per account. It answers three questions an owner
actually has:

- **Who is this?** Search by address. The name sits under the address, because most accounts have
  none: the magic-link sign-in only ever asked for an email. The row also shows the preferred
  market, how many lists and recipients the person has, when they joined, and whether they opted
  in to the email digests.
- **Who may open this panel?** An *Administrator* toggle on the edit page, plus a *Panel password*
  field that appears with it. Filters on the list narrow it to administrators only.
- **How do I delete an account?** A delete action on the row and on the edit page, behind a
  confirmation that spells out what goes with it.

## Decisions, and why

**There is no create page.** An account on this site is a proven email address, and the magic link
or Google sign-in is the proof. A row typed into a form is an address nobody has demonstrated they
own, which is also how a typo becomes an account that receives somebody else's reminders. The one
account that has to exist before anybody can sign in is the first administrator, and
`bc:make-admin` makes that one from a shell. `UserResource::canCreate()` returns false and the test
pins it.

**The admin flag is written with `forceFill`, and this page is the only place that may.**
`is_admin` is deliberately not mass-assignable: that guard is what stops a stray
`User::create($request->all())` from minting an administrator, and `AdminPanelTest` asserts it
holds. Filament's default save is a mass assignment, so without the override in
`EditUser::handleRecordUpdate()` the toggle would flip on screen, save without an error and change
nothing. That is the worst kind of failure to hand somebody who has just granted access: it looks
done.

**Promoting somebody asks for a password.** The public site signs people in without one; the panel's
login form does not. An account with the flag and no password is a login that can never succeed,
which reads as "the panel is broken" rather than "a field was missing". So the password field is
required when the toggle is on and the row has no password yet, and optional afterwards. It is only
ever written when something was typed: saving the form with the field empty keeps the existing
hash rather than blanking it. The model's `hashed` cast does the hashing; the form never sees a
hash.

**You cannot demote or delete yourself.** The toggle is disabled on your own row and the delete
action is hidden there. A disabled field is not sent with the form, so even a crafted request leaves
the flag alone. The failure this prevents is the last administrator locking themselves out with one
mis-click, which is fixable only from a shell.

**Email uniqueness folds case.** The database index is on `lower(email)`, so two spellings of one
address are one account. A rule that compared exactly would pass the form and the save would then
500 on the index. The form lowercases and trims the address on the way in, and the rule checks the
lowercased value.

**Email opt-in can be turned off here, but should never be turned on.** The helper text says so. The
toggle exists for the support case, "please stop emailing me", not for the marketing one. Consent
is the person's to give.

**What is deliberately not on the form.** Birthday, who may see it, avatar, friendships, lists,
recipients, the inbox. They belong to the person; an administrator can see how much of it there is
(the counts on the list) and nothing more. This is a support screen, not a profile editor.

## What deletion takes with it

The foreign keys decide, not this screen. Wishlists, recipients, friendships, follows, blocks,
list invitations, community questions and answers, quiz runs and the inbox cascade. Cove plans,
templates and API keys the person authored stay, with the author set to null. Claims the person made
on other people's lists are keyed by a hash, not a foreign key, and are **not** released. The
confirmation names the first group and promises nothing about the second.

The nightly retention run (`bc:prune-personal-data`) is unrelated: it enforces the published windows
on logs, unconfirmed subscribers and expired tokens, and never deletes an account.

## Related

- [auth.md](auth.md) — why the site has no passwords and how an account comes to exist.
- `bc:make-admin` in [.claude/CLAUDE.md](../../.claude/CLAUDE.md) — the shell path, still the only
  way to make the first administrator.
