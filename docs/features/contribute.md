---
name: Contribute ("Denk mee")
area: Core / Community
status: Active
date_added: 2026-09-27
---

# Contribute: feedback, a voting board, and suggestions

The owner's request of 2026-09-27: *"Put a CTA bar under the title to mention that people are
welcome to provide feedback, suggest features and vote on new features in the pipeline"*, on every
page, visible but not in the way, and closable; *"create also a 'contribute' page with feature
suggestions"*, with a small voting board the owner manages in /admin; and *"populate the feature
ideas already"*.

Three parts:

1. **The bar**: one line above the site header, in every market and language.
2. **The page** `/{market}/contribute`, headed "Denk mee" (nl), "Contribute" (en), "Contribuez"
   (fr), "Contribuye" (es): the feedback form, the board of ideas with votes, and a form to suggest
   one.
3. **The admin**: Community > Feature ideas, where the owner adds and edits ideas, sets their
   status, and publishes or rejects what visitors suggest.

Files:

- Bar: [`ContributeBar.tsx`](../../resources/js/Components/ContributeBar.tsx), drawn by
  [`SiteLayout.tsx`](../../resources/js/Layouts/SiteLayout.tsx);
  [`App\Support\ContributeBar`](../../app/Support/ContributeBar.php) decides whether it shows (the
  shared `contributeBar` prop in `HandleInertiaRequests`);
  [`ContributeBarController`](../../app/Http/Controllers/ContributeBarController.php) records a close.
- Page: [`Contribute.tsx`](../../resources/js/Pages/Contribute.tsx),
  [`ContributeController`](../../app/Http/Controllers/ContributeController.php).
- Rules: `app/Services/Contribute/` — `FeatureBoard` (order, language, what a reader is sent),
  `FeatureVoting`, `FeatureSuggestions` (the daily limit), `FeatureIdeaSeeder`.
- Data: `feature_ideas`, `feature_votes`
  (migration `2026_09_28_000900_feature_ideas_and_votes`), models `FeatureIdea`, `FeatureVote`,
  enum `FeatureStatus`; moderation reuses `ModerationStatus`.
- Admin: [`FeatureIdeaResource`](../../app/Filament/Resources/FeatureIdeas/FeatureIdeaResource.php).
- Shipped ideas: [`resources/content/feature-ideas.php`](../../resources/content/feature-ideas.php),
  `php artisan bc:seed-feature-ideas`.
- Tests: `ContributeTest`, `FeatureIdeaAdminTest`; `LegalPagesTest` holds the published retention.

## The bar

"GiftCoves groeit met jou mee: geef feedback, stel iets voor of stem op wat we bouwen. **Denk mee
→**", with a close button. Below `lg` it says only "Jouw mening telt." and the link, so it
is one short line on a phone; the long sentence would wrap to two there.

**The whole row is the link** (owner, 2026-09-27), not only "Denk mee →": a one-line bar is one
target, and the sentence is what people read and tap. Only the close button sits outside it.
Hovering anywhere underlines "Denk mee".

**Above the header, and tinted** (owner, 2026-09-27, the same day it was built: "find a better
place", "i prefer more visible", "what do you think of above the page?"). It first sat under the
header in the card colour, where it read as part of the page and was easy to miss. Now it takes the
market bar's slot above the header: the two never show together (below), so the top of the page
holds one question to the visitor at a time, and up there it reads as the site asking. A floating
tab was considered and not built: on a phone it would sit over the page, beside the save toast and
the cookie banner. The tint is the accent at 10% with the icon on every width; never the solid
accent, which belongs to the one main action of each page.

**Closing is remembered in a cookie the server reads, not in localStorage.** The pages are
server-rendered. With localStorage the server cannot know the bar was closed, so it would draw the
bar, and the page would jump up by a line once the JavaScript hid it, on every page, for exactly the
people who asked for it to go away. A cookie (`bc_contribute_bar`, a year, `httpOnly`) is read when
the shared props are built, so the first paint is already right. It is written only by
`POST /contribute-bar`, which the close button sends by fetch while hiding the bar at once; a failed
post is swallowed and the bar comes back on the next page, which is the honest result of a close
that was not recorded. Unprefixed and POST, like `/market` and `/consent`: it is about the visitor,
and a GET would be a link that closes it for whoever clicks.

The cookie remembers an interface choice and nothing about the visitor, so it needs no consent under
Article 5(3) ePrivacy; the privacy policy lists it with the other necessary cookies ("Five that are
necessary"). A year, then the bar may come back once, by which time the board has moved on.

**When it does not show:**

- **While the market bar is up.** A first visit could otherwise get two bars stacked around the
  header: two questions at once, and on a phone two lines of chrome before the page. The market
  question comes first; this bar appears on the next page after it is answered or closed.
- **While the adding-mode bar is up** (Components/AddingToBar): that one says what the whole site is
  doing right now, and a second bar under it would bury it.
- **For crawlers**, which keep no cookie: the bar would be in every search snippet.
- **On some pages**, by page component (the server builds the shared props before it knows the page):
  the contribute page itself; signing in; and the pages a non-member was sent to by somebody else to
  do one thing for them (accepting or refusing an invitation, describing themselves for a giver, a
  quiz, This or that, joining a Secret Friend). "Help us build GiftCoves" is not their question yet.
  There are no print or embedded pages in the app; `/admin` is Filament, not this layout.

## Where the page is linked

The bar, the footer (next to "How it works"), and a line at the end of `/help` under the report form
("Een idee voor GiftCoves, of wil je stemmen op wat we bouwen? Denk mee →"). **Not the account
menu:** it holds My Coves, My people, Saved Coves and Secret Friend, then Notifications, Help, Admin
and signing out, and the owner has already trimmed it twice (docs/features/navigation.md). Everything
in it is about your own things; this page is about the site. Once the bar is closed the footer and
/help still reach it. It is in the sitemap, like /help.

`/contribute` in every market, like `/people`: the heading is translated, the path is not. The
codebase localises a path only where the word is what people search for (the Daily Cove, gift
landing pages); nobody searches for this one.

## The page

One column, as /help: there is nothing to put beside it. Three sections, in the owner's order:

1. **A short intro and the feedback form.** `FeedbackForm` from /help, the same component and the
   same `POST /feedback`, so the honeypot, the rate limit and the owner's email come with it
   (docs/features/feedback.md). It does not take the focus here (`autoFocus={false}`), because on
   this page it is one of three things and grabbing a phone's keyboard on arrival would hide the
   board.
2. **The board**: a card per idea with a status pill, the title, a sentence, and the vote count. A
   signed-in reader gets a toggle button (an up arrow, `ToolIcon` `vote`, "Stem" / "Gestemd" and the
   count, `aria-pressed`); a guest sees the count and one "Meld je aan om te stemmen" line with a
   `SignInLink`. How voting works is behind the (i) next to the heading. Done ideas fold away under
   "Al gebouwd (n)" at the end: they are news, not a question.
3. **Suggest something**: title (required, 5 to 120 characters) and an optional longer text, for
   signed-in readers. Under it, "Jouw voorstellen, die we nog lezen" lists the reader's own pending
   suggestions, so a suggestion visibly arrived even though it is not on the board; the flash says
   the same. What happens to a suggestion is behind the (i).

**The order of the board** (`FeatureBoard`): building, planned, considering, done. Within
"considering" the votes decide, then the admin's `sort`; within the others `sort` first, then votes.
A reader is sent the text, the status, the count and whether they voted. **Never who voted and never
who suggested an idea**: a vote is a signal to us, not a statement to other visitors, and a name on
a suggestion invites a pile-on when it is declined.

**The language.** The reader's language, falling back to the language the idea was written in, then
to any language that has text (`FeatureIdea::text()`). A Dutch suggestion on the French page shows
in Dutch until the owner fills in the French: an untranslated card beats an empty one, and
publishing should not have to wait for four translations.

## Votes

**Signed in only.** An anonymous count is one refresh or one private window away from any number, and
the board exists to tell us what people want. The account is also what makes "one vote per person"
mean anything: `feature_votes` is unique on (idea, user). Voting twice leaves one vote, and pressing
again takes it back (`DELETE`); both are idempotent, so a double tap or a stale page never errors.

A pending or rejected idea cannot be voted on by guessing its id (404), and neither can a done one:
there is nothing left to decide, and a count still climbing on something finished reads as a request
nobody heard.

## Suggestions and moderation

**Nothing a visitor writes reaches the board until a person publishes it** at /admin, Community >
Feature ideas. The navigation badge counts what is waiting. `moderation` is `pending`, `published` or
`rejected` (the same `ModerationStatus` as the community posts): "not published" hides two different
things, not looked at yet and looked at and declined, and the queue has to tell them apart.

**No AI.** The site's first invariant is that AI never runs inside a web request, and a queued screen
would be a second opinion on a queue of a handful of rows a week that the owner reads anyway: reading
them is the point of the board.

**Limits.** A burst limit on the route (10 a minute) and `FeatureSuggestions::PER_DAY` (5 a day per
account). Past the daily limit the answer is an honest "you can again tomorrow", unlike the
anonymous feedback form's silent thank-you: this is a signed-in person, not a script probing where
the line is.

**Stored in the visitor's language only.** The admin form has a section per language; the owner fills
in the others when it is worth it.

## Data

`feature_ideas` keeps every language on one row: `title` and `body` are jsonb keyed `nl`, `en`, `fr`,
`es`, and `language` is the one it was written in. The site's other translated text (page blocks,
email templates) is one row per language, and that pattern was considered and not used: a vote is for
the idea, not for its Dutch wording, and one row per language would split each count four ways or
need a group key to add them back up. Status, moderation, source and language are strings with CHECK
constraints, never Postgres enums, as everywhere here. `source` is `seed`, `owner` or `visitor`.

## The shipped ideas

`resources/content/feature-ideas.php`, in all four languages, plain words and no dates, nothing about
Amazon (`FeatureIdeaAdminTest` checks the last two things and that every language is filled). The
owner's list of 2026-09-27, checked against [strategy.md](../strategy.md) and the feature docs:

| Idea | Status | Note |
|---|---|---|
| Een Cove volgen | considering | only the Daily Cove by email exists (cove-subscriptions.md) |
| Een knop in je browser | considering | the extension in `extension/` is an editors' import tool with an API key, not this |
| Verjaardagen in je eigen agenda | considering | no calendar feed exists |
| Zoeken op wat je bedoelt | **done** | intent search, built 2026-09-26 (intent-search.md) |
| Een productpagina met meer | **done** | price range, saved by, found in Coves, built 2026-09-26 (product-signals.md) |
| Openbare Coves | **done** | publishing a list as a Community Cove, built 2026-09-26 |

**Open ideas only when truly new; built ones stay as done** (owner, the same day, before the board
went live). First: "check the features listed: what is truly a new idea, remove the implemented ones or
the ones that we are implementing. Truly new is eg a browser plugin". Five came off because they were
our own plans or the owner's open decisions, not visitors' wishes: friends saved as people (step 3 of
my-people.md), a shorter interest list, price watch on every list at once, Spanish gift pages, the
Secret Friend draw on a profile. A board of what we already mean to do asks visitors to vote on our
to-do list.

Then: "you can keep the already built part, also add in the future". So the three built ones stay as
**done**, and **every feature a visitor would notice gets an entry here as `done` when it ships**, in
four languages, described as it works. "Al gebouwd" on the board is where that shows.

**Seeding.** `php artisan bc:seed-feature-ideas` (`--dry-run`, `--replace`) is idempotent, matched on
`seed_key`, and never overwrites an idea edited in the admin: saving a shipped idea there turns its
`source` from `seed` to `owner`, and the seeder only rewrites `seed` rows. `--replace` overrides that
and asks first. **A deploy gets the ideas without anybody running it**: migration
`2026_09_28_000910_the_feature_ideas_move_in` runs the same seeder on any database that already holds
Dailies, and cannot fail the deploy (the seed runs in a savepoint and a failure is reported and
swallowed, since a failing migration is an outage here). It skips testing and fresh, empty databases,
for the reason the advice Coves' migration records; on a fresh database run the command after
`migrate`, as CLAUDE.md lists.

To take a shipped idea off the board, **reject it rather than deleting it**: a deleted row has no
`owner` row to protect it, and the next seed recreates it.

## Personal data

A vote and a suggestion are tied to an account, so both are personal data.

- **Votes**: kept until the person takes the vote back, the idea is deleted (cascade), or the account
  is deleted (cascade). Other visitors see counts only.
- **Suggestions**: a published one stays on the board; when its author deletes their account it
  stays, and `suggested_by` becomes null (`nullOnDelete`), as feedback does. A rejected one is deleted
  365 days after the decision (`decided_at`), by `bc:prune-personal-data`
  (`RETENTION['feature_suggestions_rejected']`). Only visitors' suggestions: an idea the owner wrote
  and rejected is not personal data.
- **Legal basis**: legitimate interests (Art. 6(1)(f)), ours in knowing what to build and theirs in
  being counted. Both privacy policies say so, with the retention, in "The Contribute page" / "De
  pagina Denk mee"; `LegalPagesTest` checks the published window against the code.
- **`bc:scrub`** deletes visitors' suggestions that are not published (free text from a real account
  that nobody else has seen). Published ideas stay so a restored board looks like production; their
  votes point at the scrubbed accounts.
- **Account deletion** takes the account's votes with it.

## Not built

- Comments on an idea, and merging duplicate suggestions (the admin rejects the duplicate for now).
- Telling a suggester by email that their idea was published or declined.
- The ideas in the `bc:export-content` envelope: they are managed in production's admin, not
  authored here.

## Cached (2026-09-27)

The board's ideas and vote counts are cached for five minutes, shared by every reader, and forgotten
when somebody votes or an idea is saved (so a voter sees their vote counted, and an idea published in
the admin is on the board at once). Which ideas the reader voted for is read per request. See
[speed.md](speed.md), "Cove pages".
