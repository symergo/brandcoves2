---
name: Localisation
area: Core / Frontend
status: Active
date_added: 2026-08-07
---

# Localisation

Five markets, four languages. The market decides the **catalogue**; its language
decides the **words**.

| Market | Language | `<html lang>` | Copy file |
|---|---|---|---|
| `be-nl` | nl | `nl-BE` | `lang/nl/site.php` |
| `nl-nl` | nl | `nl-NL` | `lang/nl/site.php` |
| `be-fr` | fr | `fr-BE` | `lang/fr/site.php` |
| `es` | es | `es-ES` | `lang/es/site.php` |
| `en` | en | `en` | `lang/en/site.php` |

## Why copy is keyed by language, not market

`be-nl` and `nl-nl` are **two markets sharing one language**. They differ in
merchants, availability, tax and number formatting — not in vocabulary. Keying
copy by market would mean maintaining two identical Dutch files and watching
them drift.

The inverse also matters: numbers are formatted from the **market**'s
`hrefLang`, not the language, because `nl-BE` and `nl-NL` write thousands
separators differently while agreeing on every word.

## How a string reaches the page

1. `SetMarket` middleware resolves the market from `/{market}/` and calls
   `app()->setLocale($market->language())`.
2. `HandleInertiaRequests` shares `Lang::get('site')` with every response.
3. React reads it through `useTranslations()`: `t('home.cta_gift')`.

Translations are shipped **whole** in the Inertia payload rather than fetched.
They are a few kilobytes, and a separate request would mean the first paint
shows raw translation keys.

**A missing key renders as the key itself** (`home.cta_gift`), on purpose. A
visible key is an obvious bug; a blank button is one you ship without noticing.
In dev it also logs a console warning.

## The switcher is two controls: a flag, and a language

Changed 2026-08-15. It was one `<select>` listing `BE/NL, BE/FR, EU/EN, NL/NL` — market keys with a
slash in them. Nobody has ever wanted "BE/FR"; they have wanted Belgium, in French. That list also
grows multiplicatively with every country added while saying less each time.

Now: **three flags for the country, and a language dropdown that appears only where there is a choice
to make.**

| Flag | Markets behind it | Dropdown |
|---|---|---|
| 🇧🇪 | `be-nl`, `be-fr` | Nederlands · Français |
| 🇳🇱 | `nl-nl` | — |
| 🇪🇺 | `en` | — |

Decisions worth keeping:

- **The matrix is sparse, so the country carries the languages.** Three countries and three
  languages make nine pairs, of which four exist. Two free-running dropdowns would let anyone ask for
  Dutch in Europe or French in the Netherlands, neither of which is a place. Each country offers its
  own markets and no others, which makes an impossible pair *unaskable* rather than merely rejected.
- **English is a flag, not an option repeated under every country.** There is one English market and
  its country is `EU`, so English is a market choice here and not a language choice. It is always one
  click away because that flag is always on screen. Listing it under the Dutch flag would have
  offered a language by quietly moving the reader to another catalogue.
- **No dropdown where it would hold one option.** A select with a single entry is a control that
  cannot be operated. Counted from the country's own markets rather than hardcoded to `BE`, so a
  second bilingual country gets its dropdown without anyone remembering the rule exists.
- **Clicking a flag keeps your language where the country has it.** Belgium in French → the
  Netherlands lands on Dutch; Belgium in Dutch → the Netherlands stays Dutch.
- **Flags are inline SVG, never emoji.** 🇧🇪 is a regional-indicator pair and Windows has never
  shipped glyphs for those — Chrome and Edge on Windows render it as the letters "BE", which is most
  desktop visitors seeing a broken control. `FlagIcon` is the only drawing on the site that ignores
  the palette and carries its own official colours: a recoloured flag is not a flag.
- **Country names follow the reader; language names never do.** "Belgium / België / Belgique" is a
  fact about our site and belongs in the language being read. "Dutch" is invisible to somebody who
  only reads Dutch, so a language is always named in itself.
- Still a full page load, exactly as the single dropdown was. Switching either control changes the
  catalogue, the currency and the language, and a client-side swap would leave the last market's
  prices on screen while the new copy arrived.

`App\Support\MarketSwitcher` builds the payload and `MarketSwitcherTest` pins the two properties that
fail silently: every published market is reachable, and nothing points at an unpublished one.

## "Cove" is a brand word; everything around it is not

The three named surfaces — the **Gift Cove**, the **Daily Cove**, and **Coves**
(the editorials index) — are translated on one rule: *`Cove` stays, the
qualifier goes into the language*.

| | en | nl | fr | es |
|---|---|---|---|---|
| Gift Cove | Gift Cove | Geschenk Cove | Cove Cadeau | Cove de Regalos |
| Daily Cove | Daily Cove | Dagelijkse Cove | Cove Quotidienne | Cove Diaria |
| Editorials index | Coves | Coves | Coves | Coves |

`Cove` is half the company name and the URL segment (`/coves`, `/daily`), so
translating it strands the reader: `nl` had `De Dagelijkse Cove` as the page
title and `De Daily Cove` in the OG card and the subscription email, and `fr`
and `es` had gone further still — `La Crique du jour` and `La Cala del día`
translated the noun itself, which reads as an ordinary word for an inlet and
connects to nothing else on the site. Leaving the qualifier in English does the
opposite damage: `La Gift Cove` in a French sentence is a foreign object the
reader has to parse before they can use the nav.

The gender is fixed feminine wherever the language has one — `la Cove` in fr and
es — because both files already said `La Cove du jour` / `La Cove de hoy` while
their OG strings said `Le Daily Cove` / `El Daily Cove`.

Prose that says "the Cove" or "today's Cove" is unaffected: those are the noun
in ordinary use, not the product name, and every language already handled them
consistently.

### The nav labels now break that rule, deliberately

Changed 2026-08-15, on instruction. `nav.cove` is **"Gift Cove" in all four languages**, and the
front page's link to the discovery hub uses a new `nav.discover_cove` — **"Discover Cove"**, likewise
untranslated. The argument above still describes the trade honestly; what changed is which side of it
we take for the *names of the hubs*. A hub is a place with a name, like GiftCoves itself, and a
translated name is a second name for the same place.

> **This is currently half-applied, and the inconsistency is visible.** The rule above still governs
> `gift_cove.title` (`De Geschenk Cove`, `La Cove Cadeau`, `La Cove de Regalos`), `nav.coves`
> (`Idee Cove`, `Cove de Ideas`) and the Daily Cove everywhere. So the header and front page say
> "Gift Cove" while the page they link to is headed "De Geschenk Cove". Either finish the change
> across the table above or revert the two nav keys — the half-state is the one option that is wrong
> in every language.

## hreflang

Every page emits an `alternate` link for all five markets plus `x-default`
pointing at the pan-European English market.

Without these the five market versions of a page compete with each other in
search results, and the wrong language can rank in the wrong country.
`CurrentMarket::swapMarketInPath()` swaps only the leading market segment, so
`/be-nl/guides/x` maps to `/be-fr/guides/x` rather than collapsing to a
homepage.

> Guide *slugs* are not translated yet. The alternate resolves and the page
> loads, which is what hreflang requires; translated slugs are a Phase 6 concern
> once guides exist per market.

## Guardrails

`LocalisationTest` pins the behaviour, including two tests that exist to stop
silent drift:

- **Every language defines every key**, with English as the reference. A missing
  key would otherwise reach real users as raw `home.cta_gift` text; a stale key
  means dead copy nobody is maintaining.
- **No raw key leaks into a translated market**, checked by asserting the page
  never contains `nav.`, `home.` or `footer.`.

## Adding copy

1. Add the key to `lang/en/site.php` first — it is the reference.
2. Add it to `nl`, `fr` and `es`. The test fails until you do.
3. Use it via `t('section.key')`.

## Not done yet

- **Filament admin is English only.** It is staff-facing, so this is a
  deliberate deferral rather than an oversight.
- **Emails and notifications** are not localised yet — Phase 3, when magic-link
  login and price alerts start sending them.
- **Slugs** are generated from the source title and are therefore in whatever
  language the feed supplied.
