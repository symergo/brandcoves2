---
name: Intent search
area: Search / Gifting
status: Active — part A of roadmap step 4 (reading the search box); the rest of the step is built too
date_added: 2026-09-26
---

# Intent search: the search box understands who it is for

Roadmap step 4, part A ([../strategy.md](../strategy.md), section 5). Type what you mean:

> cadeau voor mijn zus die van tuinieren houdt, €30–€50

and the search page shows what it understood, then answers it:

> **We lezen dit als** Zus × · Tuinieren × · € 30,00 – € 50,00 ×
> *Zoek toch gewoon op deze woorden*

Each chip drops that piece and searches again; "search the words as typed" skips the reading
(`?as=words`). The results come from the suggestion engine behind Find a gift, in the same
cards as a search. Measured on the dev server: "cadeau voor mijn papa die graag kookt, tussen 20 en
60 euro" answered with a chef's knife, a cast-iron pan and a kitchen-machine attachment, all within
budget.

## How it reads

`App\Services\Search\GiftIntentParser::parse()`: pure, no AI (it runs inside the request,
invariant 1), from word lists per language in `lang/{nl,en,fr,es}/intent.php`. Every value it can
produce is a case of `RecipientType`, `Interest` or `EventType`, so the engine understands all of
it; an interest's own label ("Tuinieren") is always recognised besides the synonyms. The result is
a `ParsedIntent` that turns into a `TasteBrief` (`toBrief()`), with whatever words are left over as
the brief's search words.

Deliberately conservative, because a wrong reading of an ordinary search is worse than none:

- **A gift search needs a sign that it is one**: a trigger ("cadeau voor", "gift for"), a recipient
  ("zus") or an occasion ("kerst"). "Tuinhandschoenen" and "gardening gloves" stay product
  searches.
- **A budget needs a word or a currency sign.** "onder 50", "€30-€50", "tussen 30 en 50 euro" are
  budgets; "iphone 15-16" and "lego 42125" are not.
- **A budget on a product search still counts**: "koptelefoon onder 100" searches for
  "koptelefoon" with the price filter at €100.
- **Ambiguous words are left out.** "Vriend(in)" means friend or partner in Dutch, so it counts as
  friend; "man" or "vrouw" alone means a man or a woman, and only "mijn man" / "mijn vrouw" is a
  partner. "Moe" (mum, and also "tired") is not in the list.

## What a gift search changes on the page

The chips replace the word pills (dropping "voor" or "mijn" one at a time was noise), the Amazon
box is not offered (it would search the sentence word for word), and there is no "keep me posted"
(a sentence is not a search worth watching). If the engine finds nothing, the words are searched
as usual and no chips are shown: the page never comes back empty for having understood. Gift
searches are not written to `search_log`, because they never reach `SearchService::search()`.
Since 2026-09-26 their *reading* is counted instead (who, which interests, per day; never the
words or the visitor) in `gift_search_demand`, which drafts personas for repeated readings nothing
answers yet ([persona-demand.md](persona-demand.md)).

"Die alles al heeft" / "who has everything" is read too, as the brief's has-everything flag, with
a chip of its own ([has-everything.md](has-everything.md)).

## The rest of step 4

All built on 2026-09-26: the two list signals ([list-signals.md](list-signals.md)), gift landing
pages built from a brief ([gift-landing-pages.md](gift-landing-pages.md)), search filters for who,
interest and occasion ([search.md](search.md)), and briefs on Coves
([editorial-api.md](editorial-api.md)). A gift sentence typed in the box is still answered here, by
the engine; the landing pages are what search engines are meant to find for those phrases.

## Tests

`GiftIntentParserTest` (the owner's example in all four languages, budgets, the conservative
cases), `IntentSearchTest` (chips, results within budget, the words-as-typed link, the product
search budget, the fallback).
