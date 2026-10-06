---
name: Gift history and reminders with ideas
area: Gifting / Notifications
status: Active
date_added: 2026-09-26
---

# "Last year the moka pot, this year the grinder"

## 2026-09-29: "Wat je gaf" removed

The owner removed the **"Wat je gaf"** section of a saved person's page, and the code behind it:
the gifts a giver *noted*, typed by hand or with **"Dit gaf ik"** ("I gave this") beside an item
on their own list about the person. Gone with it: the "Op je lijsten voor :name" section and its
buttons, the two routes (`people.gifts.store`, `people.gifts.destroy`), `PersonController::store()`
and `destroy()`, the `RecipientGift` model and `Recipient::gifts()`, `GiftHistory::unmarkedItems()`
and `canMark()`, the `noted` source on `PastGift`, the `history` / `unmarked` / `thisYear` page
props, and the copy that described them.

**What remains** is the other half of the history: the giver's **own claims** ("Ik koop dit", and
the same marked as bought) on lists about the person, read live by the giver's own claim hash.
They still keep those products (and their merged twins) out of Find a gift, This or that, the
swipe deck and the reminder ideas for that person (`GiftHistory::excludedGroupIds()`), and they
are what **"Geïnspireerd op hun lijsten"** (called "De volgende stap" until 2026-10-06, renamed by the owner) follows on from. It is hidden while nobody has a list for the person (`NextSteps::forRecipient`). The page just no longer lists them.

**The `recipient_gifts` table stays, unread**, until a later release drops it (expand/contract:
a rollback must not meet a schema it cannot read). Nothing writes it and nothing reads it;
`GroupMerger` no longer moves its rows. It still holds personal data about people who are not the
user, so `bc:prune-personal-data` keeps deleting a line once its year is ten years back, and a
person deleted still takes their lines with them (cascade).

The sections below are the original design. Where they describe noted gifts, "I gave this" or
the list of past gifts on the page, that part is history, not current behaviour.

## The original design (2026-09-26)

Two requests from the owner, 2026-09-26, built together because the second needs the first:

1. **Gift history per person.** Remember what a user gave each of their saved people, never
   suggest the same thing again for that person, and suggest "the next step": things that follow
   on from a past gift.
2. **Reminders with ideas ready.** About two weeks before a saved person's birthday or the
   occasion on a list about them, email three ideas that fit them, their budget and their history,
   with one click to Find a gift or onto their list.

A "saved person" is a `recipients` row: somebody a user buys for (see
[gifting-lenses.md](gifting-lenses.md)). They had no page of their own until now.

## The person's page

`/{market}/people/{id}`, the owner's only (behind sign-in, owner-scoped, 404 for anybody else,
`noindex`). Since 2026-09-27 it is titled with the person's name and opens with a profile (what
you know about them, their wish lists, your lists for them; see
[my-people.md](my-people.md#the-persons-page)). Below that it shows **Geïnspireerd op hun
lijsten** ("De volgende stap" until 2026-10-06): up to four ideas inspired by what is on the lists
for them, things that go with it, and more of the same brand or category (the owner's words).
Since 2026-10-06 it starts from **every item on those lists, bought or not**, the newest eight
(`NextSteps::listed()`); it started from your own claims only, so a full list with nothing bought
yet gave nothing. A fourth reason joined the three: **same category** (0.45, the weakest, card text
"Zelfde soort als …"). What was given and what is on the lists is never suggested. No lists, or
empty ones: the section is hidden.

Until 2026-09-29 it also showed **What you gave** (newest first, with a remove button on the lines
you wrote) and **"I gave this"** (a line typed by hand, or a button beside each item on your own
lists about this person). Both were removed; see the top of this page.

The buttons to Find a gift and This or that moved into the profile's header.

Linked from a list about somebody (under the description, owner only), from Find a gift's
results when a saved person is chosen, from the reminder email, and since 2026-09-26 from **My
people** (`/{market}/people`, [my-people.md](my-people.md)), the page that lists every saved person
together with your friends on GiftCoves. Before that there was no list of saved people anywhere.
The link's label is "Naar :name" (`gift_history.link`) since 2026-09-29; it said "Wat je :name
gaf" while the page listed what you gave.

## What the history holds

`App\Services\Gift\GiftHistory`, from one source since 2026-09-29:

| source | where | stored |
|---|---|---|
| your own claims | "I'll get this" on a list about this person, still held; "I have bought it" marks it as bought | read live from `wishlist_items` |

The second source, "written down" (typed on the page, or an item marked "I gave this", in
`recipient_gifts`), was removed; see the top of this page.

### Only your own claims, and only to you (invariant 4)

A claim is a one-way hash of the claimer. The history finds claims by computing the **owner's own
hash** and matching it exactly; it never asks "is anything claimed". So another giver's claim is
invisible here, to everybody. The history is used only on pages the owner alone opens, so the
person the list is about learns nothing either.

### Claims are read, not copied

A claim handed back leaves the history at once, which is right (they are not getting it after all),
and there is no second copy of claim state to go stale. The price: a claim on a list that is later
deleted leaves the history with it (until 2026-09-29 "I gave this" was how to keep it for good;
now nothing does). Decided without the owner; copying claims into a table would have been a second store of claim state, which is the
thing [gifting-lenses.md](gifting-lenses.md) warns grows into a leak.

### Which lists are "about this person"

- lists naming this person as their recipient (your gift lists and group lists);
- when the person is linked to an account: **their own wish lists**, and lists other people made
  about the same account. That is where "I'll get this" on Mum's own wish list lives.

Lists your sister made about Mum under her *own* saved person are only found when both saved
people are linked to Mum's account; nothing else says two saved people are the same human.

### A year, not a date

"Last Christmas" and "her 60th" are what people remember. A day would be a question nobody can
answer and nothing reads. `given_year`, CHECKed to a sane range. (That was the noted gifts'
column; a claim's year is the year it was claimed, or marked as bought.)

## Never the same thing again

Find a gift (every action: suggest, swap, more, and `/gift?for=<person>`) and This or that
(when played with `?person=<id>`) add the person's past gifts to the brief's exclusions. **Merged
products count as the same gift**: a merge keeps the old product row pointing at the new one
(`GroupMerger`), so the exclusion adds both the product a past gift was merged into and anything
merged into it. (`GroupMerger` also moved `recipient_gifts.group_id` to the product that is kept,
until 2026-09-29; a claim's list item is moved by the merge like any other.)

`SuggestionEngine` and `TasteBrief` are unchanged: the exclusion goes in through
`TasteBrief::excluding()`, which already existed for rejected and already-shown products.

## The next step

`NextSteps` fetches candidates three ways, and `NextStepScorer`, a pure class with its own unit
test, decides. The ranked ids are kept an hour (2026-09-27) under a key made of everything that
goes in: the past gifts, the budget, the year and every excluded product. A change to any of them is
a new key, and the products are loaded fresh, so stock still counts. See [speed.md](speed.md),
"Lists, people and gifts".

| reason | evidence | weight |
|---|---|---|
| often together | `product_links`: five or more people keep both on their lists | 0.5 at five people to 1.0 at twenty |
| goes with | the complement word lists, `resources/content/gift-complements.php` | 0.8 |
| same brand | the same maker | 0.6 for another kind of thing (other category), 0.4 for the same kind |

- **The strongest reason wins; a second adds a quarter of its weight.** A Bialetti grinder after a
  Bialetti moka pot is "goes with", a little stronger for the brand.
- **Lists beat the word lists only when many people agree** (thirteen or more). At the five-person
  floor a link is 0.625, under a complement's 0.8: an editor's knowledge that a razor needs blades
  is stronger evidence than five people happening to keep two things on one list.
- **The brand is weakest on its own**, because a brand's range is wide.
- **Never another of the same thing.** A candidate sharing 60% or more of its title words with a
  past gift (the six-cup moka pot after the three-cup) is dropped, however many people link them.
  The same test keeps near-twins apart in the row (the same beans in two sizes).
- **Recent gifts count more**: each year back multiplies by 0.8, to a floor of 0.4. This year and
  last year both count fully.
- **Spread**: each past gift gets at most two places before every other past gift has had its
  turn; then the row is filled from the rest, so one past gift can still fill it.
- **Within the person's budget**, when one is saved, and never what is already on their lists.
- Candidates are in stock, priced, pictured, not merged away, and either `giftable` or
  `worth_showing`: coffee beans are often not classed a gift alone, and after a moka pot they are
  exactly one.

### The complement word lists

Seventeen families (coffee, tea, wine, barbecue, cooking, baking, gardening, photography, gaming,
reading, listening, running, yoga, crafts, home fragrance, shaving, board games), each with the
things that *trigger* it and the things that *go with* it, in the four catalogue languages. A word
matches at the start of a word, ignoring case and accents, so "grinder" finds "grinders" and "tea"
does not find "steak". The file's header lists short words left out because they match too much
("mando" finds "mandoline", "stof" finds "stofzuiger"); check a new short word before adding it.
The lists are a start written without the owner; they are the part most worth an editor's pass.

## Reminders with ideas ready

The reminder mechanism already existed ([occasion-reminders.md](occasion-reminders.md)): windows
of 30, 15 and 2 days, in-app and by email, deduplicated through the notification row. It was
extended, not duplicated.

- **Which window.** The ideas ride on the configured window nearest two weeks
  (`reminders.ideas_lead_days`, 14): with the shipped windows, the fifteen-day one. Not a window
  of its own: a fourth email a day before or after the fifteen-day one is the over-notifying the job
  avoids. Decided without the owner.
- **Which reminders.** A saved person's birthday, and the occasion on a list about somebody
  (`for_someone` or `group` with a recipient). Not a wish list of your own (the occasion is yours),
  not a friend's birthday (a friend is not a saved person with a taste profile), not a Secret Friend
  exchange. A saved person's `occasion` field ("christmas") carries no date and does not start a
  reminder of its own; a list about them dated at Christmas does.
- **Which ideas.** `ReminderIdeas`: the best next step first when there is one, then the
  SuggestionEngine with the brief Find a gift would build from the saved person, the occasion
  set to the reminder's, and past gifts and whatever is on their lists excluded. Three in all. No AI
  (the engine is retrieval and arithmetic); the tests mock `AiClient` to refuse.
- **Once per person, occasion and year.** The key `{person}:{occasion}:{year}` is written into the
  notification's payload as `ideas_key`, and a reminder carries ideas only when no notification for
  that user has the key yet. A birthday and a birthday list for the same person on the same day are
  two reminders and one set of ideas. No extra table, as with the reminder's own ledger.
- **Only in the email, only when it goes.** No email (off everywhere, off for this user, no address)
  means no ideas are chosen and no key is written. The in-app row links to `/gift?for=<person>`,
  which shows the ideas live.
- **One click.** "More ideas in Find a gift" opens `/gift?for=<person>`, which runs the Gift
  Finder on the saved person straight away. Each idea's "Add to the list for Mum" opens the person's
  page with `?add=<product>`, the product shown on top with its save button: one press there.
  **Not a GET that adds**: mail scanners open every link in a message, and would put all three ideas
  on the list before the reader saw them. Decided without the owner.
- **The ideas are not list contents.** The reminder mail's old rule was "no products, no prices, no
  claim state". The ideas are catalogue products about somebody other than the reader, with a
  price; nothing from any list, nothing about claims. The rule was about lists, and holds.
- **An edited template keeps them.** The ideas and the stop link are added under an editor's
  wording as well as under ours (`mail/partials/reminder-ideas`), because the template owns the
  prose, never the structure ([email-templates.md](email-templates.md)).

### Stopping the emails

There was no per-user email switch. `users.reminder_emails_off_at` is one, for **all reminder
emails** (the unsubscribe link in a reminder has to stop reminders, not only their ideas):

- every reminder email carries a signed link, `/{market}/reminders/stop/{user}`, in the footer and
  as `List-Unsubscribe` with one-click POST (RFC 8058), like the Cove digest. GET and POST, no
  sign-in needed, CSRF-exempt; the signature is the credential, it never expires (an unsubscribe
  link that expires fails when somebody is annoyed enough to use it), and it can only turn emails
  off. Rotating `APP_KEY` invalidates old links; that is the price of not adding a token column;
- the notifications page has the switch, for turning them back on.

Off keeps the inbox row: the reminder is still recorded, it just does not travel.

## Two bugs found on the way

`SendOccasionReminders` read a saved person's market with Eloquent's `value('market')`, which
returns the cast `Market` enum, and `(string)` cannot convert an enum. So the job threw for every
saved person who had a list, and for every friend-birthday reminder whose user had a list: a
birthday reminder reached only people nobody had made a list for. Fixed with `toBase()`; the new
reminder tests all have a list.

An edited reminder template never changed the email's body. A Mailable hands every public property
to its view, after and over what `content()` passes, and `OccasionReminderMail::$body` (the shipped
sentence) was public, so it replaced the editor's body in every email; only the subject changed.
The existing test read `content()->with`, not the rendered mail. `$body` is protected now, and
`ReminderIdeasTest` renders an edited reminder.

## Personal data

`recipient_gifts` is personal data about a person who is not the user. It goes with the person
(cascade) and with the account, and `bc:prune-personal-data` deletes a line once its year is ten
years back (`PrunePersonalDataCommand::GIFT_HISTORY_YEARS`): long enough for "what did I give her
for her 60th", and past it a line no longer says what to avoid. Since 2026-09-29 nothing writes or
reads the table, and a line can no longer be removed on its own from the page; the pruning stays
until the table is dropped. Stated on the privacy page (en, nl), under Recipients and in the
retention table, which still describes noting what you gave (not yet updated). Claims add no new
storage.

## Where it is

| | |
|---|---|
| Schema | `2026_09_28_000100_remember_what_was_given`: `recipient_gifts`, `users.reminder_emails_off_at` |
| History | `app/Services/Gift/GiftHistory.php`, `PastGift.php` (`app/Models/RecipientGift.php` removed 2026-09-29) |
| Next step | `app/Services/Gift/NextSteps.php`, `NextStepScorer.php`, `NextStep.php`, `NextStepCandidate.php`, `resources/content/gift-complements.php` |
| Reminder ideas | `app/Services/Gift/ReminderIdeas.php`, `app/Jobs/SendOccasionReminders.php`, `app/Mail/OccasionReminderMail.php`, `resources/views/mail/partials/reminder-ideas.blade.php` |
| Pages | `PersonController` → `Recipients/Show.tsx`; `Components/NextSteps.tsx`; Find a gift (`GiftController`, `Gift/Wizard.tsx`); This or that (`TasteController::result`, `?person=` in `Gift/Taste.tsx`) |
| Stop link, switch | `ReminderEmailController`, `Notifications.tsx` |
| Copy | `site.gift_history.*`, `site.reminders.*` (ideas, stop), `site.help.find_history`, `site.help.find_reminders` |
| Config | `giftcoves.reminders.ideas`, `giftcoves.reminders.ideas_lead_days` |
| Tests | `tests/Unit/NextStepScorerTest.php`, `tests/Feature/GiftHistoryTest.php`, `tests/Feature/ReminderIdeasTest.php` |

## Not done

- Drop the unread `recipient_gifts` table in a later release (and its pruning with it).
- The complement word lists have had no editor's pass.
- Lists about the same human under two unlinked saved people (yours and your sister's) are not
  joined up; nothing in the data says they are one person.

## From a person's page: the wizard, not the board (2026-10-05)

"Cadeau vinden" on a person's page and in My people went to `/gift?for=<id>`, straight to eight
ideas. The owner found no way to swipe from there: the board has the ideas and a search, not the
ways in. Both buttons now go to `/gift?person=<id>`, which opens the wizard with that person chosen
(their interests, budget and the rest, as picking them on the first step does) on the ways step:
search, swipe, the questions, This or that, a type, asking others. The state is set as the wizard
starts, not after, so "Voor wie?" never flashes. `?for=` is unchanged for the reminder email, where
the ideas are the point. Both accept only the owner's own person; any other id opens the plain
wizard.

