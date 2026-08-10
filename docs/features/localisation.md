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
