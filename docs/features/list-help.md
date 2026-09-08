---
name: How lists work
area: Core / Frontend
status: Active
date_added: 2026-09-06
---

# The list help page

**`/{market}/lists-help` — three steps, three screenshots, in the reader's language.**

Saving a product is one tap on a bookmark, and the bookmark sits on a card among a dozen other
things to tap. People do not find it; the ones who do find it do not always realise a list has to
exist first. So the question arrives as *"how do I use this"* rather than as a question about any one
control, and there was nowhere to send it.

Linked from underneath `/lists`, and from nowhere else.

## The order is the order it happens in

A list has to exist before anything can go on it, so the tidy explanation begins with "make a list".
Nobody does that. People find something they like and *then* want to keep it, and the interface is
built for that order — the save panel offers to create a list at the moment one is needed.

So the page follows the same order, and **"how do I make a list" is answered inside step two** rather
than parked in front of it as homework. There is a section on making one further down for the person
who came looking for exactly that, and it names both routes.

## The screenshots are taken by a committed script

`scripts/help-screenshots.mjs`. It signs in, drives the real interface and photographs it.

Instructions illustrated with last year's buttons are worse than instructions with none, and a folder
of images nobody can regenerate becomes exactly that inside two releases. A script makes re-taking
them one command, so it actually gets done:

```bash
node scripts/help-screenshots.mjs     # composer dev + docker compose up -d must be running
```

Three decisions inside it are worth knowing, because each was a wrong screenshot first:

- **It photographs a throwaway account**, created by `bc:seed-help-demo` (local only, refuses in
  production). The only accounts on a development machine are the developer's own, carrying real
  gift lists — invariant 4 — and committing pictures of those would publish them.
- **It re-seeds per market.** The save panel lists every list an account has, whatever market it was
  made in, so seeding three markets in turn and photographing the third produced a panel reading
  "Mijn verlanglijstje / My wish list / Anniversaire de Lea": three languages illustrating one step.
- **It starts from a card whose picture actually loaded**, tested with `naturalWidth` rather than
  `:has(img)` — the markup carries an `img` either way, and the first result for "koptelefoon" has
  one whose file 404s. The first version opened on an empty grey square, which reads as a broken page
  rather than as an instruction.

It also crops. Full-page screenshots were the first attempt and they are close to useless here: at
1280 wide, the thing being pointed at is one small button in a picture of a whole shop.

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
would put a picture on the site of something that does not exist. Wrong-language images are the
lesser of the two failures — a step with no picture beside two that have one reads as a page that
failed to load — and a test pins the fallback so it stays deliberate.

One thing the English set shows honestly: `en` product titles arrive from Dutch-language feeds, so
the English screenshots contain Dutch product names. That is what an English visitor genuinely sees
today. It is a catalogue problem rather than a screenshot problem, and faking it here would hide it.

## Two questions answered on the page

Both are the reason somebody hesitates at this exact moment, so they are answered here rather than
left to the privacy page: **who can see a list**, and **whether the person it is for finds out what
has been bought**. The second is invariant 4 stated in plain words — claims are hidden from the
owner unless they asked otherwise.

## Brought up to date on 2026-09-08

The text described the interface of 2026-09-06. Since then the compact save control lost its
chevron on phones (one tap saves, a second opens the sheet; the arrow is a desktop thing), the
"new list" button became the three-step wizard on the home page and under My lists, sharing
gained friends by name, the list card shows a price drop, and the owner may switch on seeing
claims. The seven affected strings were rewritten in four languages and a short "what else you
can do" section was added: your own items, copying from a shared list, friends and birthdays. The
screenshots were re-taken with the committed script the same day.

## Files

- `app/Http/Controllers/ListHelpController.php`
- `resources/js/Pages/Lists/Help.tsx`
- `app/Console/Commands/SeedHelpDemoCommand.php` — the demo account, local only
- `scripts/help-screenshots.mjs`
- `public/help/lists/{nl,fr,en}/` — the images
- `lang/{en,nl,fr,es}/site.php` — `lists_help.*`
- `tests/Feature/ListHelpPageTest.php` — including one that fails if an image referenced is missing

## See also

- [wishlists.md](wishlists.md) — what the page is explaining
- [search-help.md](search-help.md) — the same idea for the search box, and the reason this sits
  beside `/lists` rather than with the legal pages
