---
name: How lists work
area: Core / Frontend
status: Active
date_added: 2026-09-06
---

# The list help pages

**`/{market}/lists-help` is an index of nine topics; `/{market}/lists-help/{topic}` is one short
page per capability, in the reader's language, with the prose kept out of the shared translation
payload.**

Saving a product is one tap on a bookmark, and the bookmark sits on a card among a dozen other
things to tap. People do not find it; the ones who do find it do not always realise a list has to
exist first. So the question arrives as *"how do I use this"* rather than as a question about any one
control, and there was nowhere to send it.

Linked from underneath `/lists`, from the help hub at `/help`, and, since 2026-09-08, from the
sitemap for every topic.

## One page became nine (2026-09-08)

The first version, 2026-09-06, was one page: three steps with screenshots. Two days later a list
could be shared with friends by name, bought from, voted on, chipped in to, talked over, quizzed
and reminded about, Secret Santa drew names beside it, and none of that was explained anywhere.
The owner asked for all of it, on one page or several with an index. One page with all of it would
be a manual nobody scrolls, so it is an index and nine topics, in the order somebody meets the
features:

| Topic | What it covers |
|---|---|
| `saving` | the three steps with screenshots, the wizard, starting signed out |
| `kinds` | wish list, gift list, group gift; the one fixed choice; occasions and dates |
| `items` | the bookmark, adding from the list, own items, copying, price drops, removing |
| `sharing` | private by default, link, friends by name, who may add, who sees claims, address |
| `claiming` | reserving, releasing, bought, suggesting, the quiz |
| `group` | group gift, voting, chipping in, the discussion board |
| `santa` | Secret Santa: group, invite, draw, repair, attach a list, reminders |
| `friends` | becoming friends, what a friend sees, birthdays, reminders, notifications |
| `alerts` | price drop on the card, back in stock, following a search |

Secret Santa was one section under group gifts in the first draft; the owner asked "what about
secret friend?" and it became a topic of its own, because it is a page of its own on the site and
people search for it by name.

## The prose is in `lang/{language}/help_lists.php`, not `site.php`

`site.php` is shipped whole to the browser with every page. Nine pages of prose would have ridden
along with the product grid. The help texts live in their own language file, are read on the server
by `ListHelpController`, and reach the page as props, so a topic's words reach only the visitor who
opened it. `lists_help.link` is the one key that stayed in `site.php`, because `Lists/Index`
renders it client-side.

A body is plain text: paragraphs separated by a blank line; a paragraph whose lines all start with
`- ` is a list, and one whose lines all start with `1. ` is the numbered steps of an instruction.
That is the whole markup, and `HelpTopic.tsx` renders it; anything richer would be an argument for
a different tool.

## Instructions, not descriptions (2026-09-08, second pass)

The first draft of the nine pages described what each feature was. The owner's reaction was "ik mis
screenshots" and "en instructies". So every section that is something you *do* is now numbered
steps that name the buttons word for word, in the four languages, with the labels checked against
the language files (a renamed button is a renamed help line), and every topic but two carries a
picture of the screen the steps happen on. Ten pictures per language now: the three of the save
flow, the wizard's first step, adding a product, the share panel, a shared list as a visitor sees it
with the claim buttons, the Secret Santa page, the friends page, and following a search.

## Keyword anchors

The owner asked for "SEO links: keywords as anchors". A link is written `[words](path)` in the
language file, with a market-relative path (`lists`, `friends`, `santa`, `notifications`, `search`,
`lists-help/sharing`), and the controller turns it into the market's URL, so one Dutch file serves
`be-nl` and `nl-nl` with the right prefix. The special path `cove` resolves to the market's own
Cove segment, which differs per language. The words linked are the ones a person would search for
(*verlanglijstje*, *Geheime Vriend*, *delen*, *Meldingen*) and the target is the page that answers
them. The topics also link each other where one mentions what another explains. Tests assert that
every resolved link on a `be-nl` page starts with `/be-nl/` and that every `lists-help/x` link in
the four files names a topic that exists.

## The four languages are checked against each other

The Dutch file is the original. A test requires English, French and Spanish to carry the same
topics, the same number of sections per topic, the same `shot` keys and no empty strings. That is
what catches a translation that fell behind after the Dutch text grew a section, which is the way
multi-language help pages usually rot.

## The screenshots are taken by a committed script

`scripts/help-screenshots.mjs`. It signs in, drives the real interface and photographs it.

Instructions illustrated with last year's buttons are worse than instructions with none, and a folder
of images nobody can regenerate becomes exactly that inside two releases. A script makes re-taking
them one command, so it actually gets done:

```bash
node scripts/help-screenshots.mjs     # composer dev + docker compose up -d must be running
```

It now takes ten pictures per language and finds every button by its own label, read from the
language file with `php -r`, so a renamed button fails loudly rather than silently photographing
the wrong thing. The seeder grew two options for it: `--shared` puts the demo list on link sharing
and prints the share link, which a second, signed-out browser opens to photograph the visitor's
view; `--like=koptelefoon` fills the list with products matching a term, because random picks put
lingerie on the help page the first time.

Three decisions inside it are worth knowing, because each was a wrong screenshot first:

- **It photographs a throwaway account**, created by `bc:seed-help-demo` (local only, refuses in
  production). The only accounts on a development machine are the developer's own, carrying real
  gift lists, invariant 4, and committing pictures of those would publish them.
- **It re-seeds per market.** The save panel lists every list an account has, whatever market it was
  made in, so seeding three markets in turn and photographing the third produced a panel reading
  "Mijn verlanglijstje / My wish list / Anniversaire de Lea": three languages illustrating one step.
- **It starts from a card whose picture actually loaded**, tested with `naturalWidth` rather than
  `:has(img)`.
- **It opens the list that has things on it**, chosen by the thumbnails on its card. The empty
  demo list opens the add-product panel by itself, so there was no button to photograph there.

One thing it needs: a development database that is up to date. On 2026-09-08 the signed-in search
page 500'd on a missing `search_alerts` table because four migrations had not been run locally, and
the script reported "no save control on this page", which reads like an empty catalogue. Run
`php artisan migrate` first.

## Languages, and the one that borrows

Screenshots are per language because the interface is: a Dutch panel does not teach a French reader
where *nouvelle liste* is.

| Language | Screenshots |
|---|---|
| Dutch | its own, from `be-nl` |
| French | its own, from `be-fr` |
| English | its own, from `en` |
| Spanish | **the English set** |

`es` has no catalogue, so there is no product page in that market to photograph, and inventing one
would put a picture on the site of something that does not exist. A test pins the fallback so it
stays deliberate.

## Files

- `app/Http/Controllers/ListHelpController.php` — `TOPICS`, the link resolver, the screenshot map
- `resources/js/Pages/Lists/HelpIndex.tsx`, `HelpTopic.tsx`
- `lang/{nl,en,fr,es}/help_lists.php` — the prose
- `app/Console/Commands/SeedHelpDemoCommand.php` — the demo account, local only
- `scripts/help-screenshots.mjs`
- `public/help/lists/{nl,fr,en}/` — the images
- `tests/Feature/ListHelpPageTest.php`

## See also

- [wishlists.md](wishlists.md), [sharing.md](sharing.md), [friends.md](friends.md),
  [secret-santa.md](secret-santa.md), [list-board.md](list-board.md), [list-quiz.md](list-quiz.md),
  [copying-items.md](copying-items.md), [occasion-reminders.md](occasion-reminders.md),
  [search-alerts.md](search-alerts.md) — what the pages are explaining
- [search-help.md](search-help.md) — the same idea for the search box, and the reason this sits
  beside `/lists` rather than with the legal pages
