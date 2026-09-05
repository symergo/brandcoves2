---
name: Page titles and descriptions
area: SEO / Frontend
status: Active
date_added: 2026-09-05
---

# Page titles and descriptions

What a page calls itself in a search result. Reviewed end to end on 2026-09-05, when the
review found that **no page on the live site had a `<title>` element at all** — see
[SSR was never dispatched to](#ssr-was-never-dispatched-to) first, because everything else
here was downstream of it.

Companion to [seo.md](seo.md), which covers indexing, structured data, crawl budget and
hreflang. This file covers the strings.

---

## The budget, and why it is 48

A search result shows about 60 characters of a title. [`app.tsx`](../../resources/js/app.tsx)
appends `· GiftCoves` — twelve of them — to every title that does not already contain the
name, and `ssr.tsx` repeats that rule verbatim so the server and the client agree. So the
copy in `lang/*/site.php` has **48**.

Descriptions get **155**, which is where `PageMeta` truncates on a word boundary.

Both numbers were written down in [seo.md](seo.md) from the first day and neither was
enforced. On 2026-09-05, fifteen of the forty-four static titles were over — `coves.seo_title`
by 39 characters in French — and three descriptions were long enough to be clipped. The
failure is invisible from a browser: the page renders, the tab looks right, and the only
place it shows is a search listing, where the half that gets dropped is the brand name.

`LocalisationTest` now gates both, next to the parity check that already walks the same four
files:

- `every_seo_title_fits_the_sixty_character_listing`
- `every_meta_description_survives_pagemeta_untruncated`

Interpolations are counted at placeholder width, which understates a long brand or a long
search term. Brand, persona and product carry their own guard against the *rendered* string;
search deliberately does not, and the next two sections say why.

## Measure the rendered string, never assume a length

Every title built from a template is measured after interpolation and falls back to the bare
subject when it does not fit. The rule is the same in four places and it exists because the
modifier is a different length in each language:

| Where | Guards | Falls back to |
|---|---|---|
| Search | the term only — see below | the term alone |
| Brand | `BrandController::listingTitle()` | the brand name alone |
| Persona | `GiftIdeasController::listingTitle()` | `theme_title` alone |
| Product | `ProductTitle::listing()` | the title, cut to what is left |

A hard-coded character limit would be correct in one language and wrong in three. `" — at 5
shops"` and `" — chez 12 boutiques"` differ by eight characters on their own.

**Search is the exception, on purpose.** Its phrase is 39–48 characters before the term is
added, so measuring the rendered string would mean the phrase never appeared at all — in Dutch
it left exactly zero characters for the query. The phrase was kept and the cap given up; only
the term is guarded now, at 30 characters. See below.

---

## Search

One indexable URL per bare query term, and the highest-volume template on the site.

**`search.results_for` is not the title, and never was a good one.** "Results for
&quot;koptelefoon&quot;" spent its first twelve characters — the ones weighted hardest — on
the word "Results", and wrapped the visitor's own word in quotation marks that read as an
exact-match citation. That key still exists and still earns its place: it is the live region
above the grid that announces a new result set to a screen reader, which is a different job
for a different reader.

The listing title is `search.seo_title_term`, and it leads with the term:

| | |
|---|---|
| en | `:term at the best price - offers and discounts` |
| nl | `:term beste prijs - aanbiedingen en kortingen` |
| fr | `:term au meilleur prix - offres et promotions` |
| es | `:term al mejor precio - ofertas y descuentos` |

The term is capitalised because it arrives as raw user input and a title opening in lower case
reads as broken. A spaced hyphen rather than an em dash, because that is house style — see
`HouseStyle`, which rewrites `—` to ` - ` in prose for the same reason.

**This title runs past sixty characters, and that is the trade.** With a one-word query it
renders at 58–63 including ` · GiftCoves`, so the phrase itself always survives and what a
search engine drops is the brand name; past a term of about nineteen characters the tail of the
phrase begins to clip too. It replaced `:term — compare prices` on 2026-09-05, which fitted the
cap comfortably and said less. Both facts were on the table when it was chosen.

Two things worth knowing if it is revisited. A fixed phrase repeated across thousands of search
URLs is the pattern a search engine is most willing to rewrite a title for. And the
48-character assertion in `LocalisationTest` still passes only because it counts `:term` at
placeholder width — it pins the fixed half now, not the whole.

**The search page had no `<h1>`.** It was the only top-level page without one, opening at
`<h2>`, on the template that most needs to say what it is about. It carries the term now.

## Brand

`brand.title` was `':brand'` — the name and nothing else, sharing its string with the `<h1>`
and leaving 45 of the 60 characters unspent. A bare brand name is also the one query where the
brand's own site, its retailers and its encyclopedia entry all outrank us.

It is now `:brand offers and discounts` (nl `aanbiedingen en kortingen`, fr `offres et
promotions`, es `ofertas y descuentos`). `brand.heading` still supplies the `<h1>`, which is
the split [seo.md](seo.md) argues for under *"a listing title is not a heading"*.

14 of the 2,480 pageworthy brands have names long enough to need the fallback. That was
measured rather than assumed away.

> `brand_stats.top_category` is the obvious next upgrade — `JBL speakers en koptelefoons —
> prijzen vergelijken` answers "what does this brand make", which is the real gap between a
> brand name and a reason to click. It is 61 characters with the suffix, so it needs the same
> guard, and it was left out of this pass rather than half-done.

## Product

302,133 URLs, and the largest problem of the three by volume. See
[product-titles.md](product-titles.md) for the whole of it: the title is not written by us,
and what arrives shouts, omits its own brand, or runs to a median of 121 characters.

Descriptions changed shape here too. There were two variants and one of them was doing two
jobs:

- `seo_compare` — priced, more than one seller
- `seo_single` — priced, one seller
- `seo_unpriced` — **new.** `seo_single` used to cover this case as well and interpolated an
  empty string into the price slot, so every unpriced group shipped
  "Foo from , with the price history".

All three now **lead with the price**. `PageMeta` cuts at 155 and `:title` can be 250
characters on its own, so a description that opened with the title lost the only number worth
reading before the cut.

---

## `<title>` and `og:title` came from two different places

`<title>` is written by Inertia's `<Head>` in the React page; `og:title` by `PageMeta` through
Blade. Where both were built from the same translation key they agreed by luck. Where the
server computes something the client cannot — a product title cut to fit and carrying a shop
count, a search title whose modifier is dropped for a long term — they silently disagreed, and
the tag a person sees in a result was not the tag a scraper read.

`HandleInertiaRequests` now shares `seoTitle`, exactly as it already shared `canonical` and for
the same class of reason. Search, Brand and Product read it. Pages that set no metadata are
unaffected and keep their own `<Head title>`.

---

## SSR was never dispatched to

**Every page on production and staging shipped as `<div id="app"></div>`** — no `<title>`, no
`<h1>`, no body copy, for every crawler, for as long as the split-container deployment has
existed. Only the Blade-rendered tags survived: description, canonical, hreflang, Open Graph,
JSON-LD.

Inertia v3 guards the SSR dispatch with a check for a local bundle file:

```php
// vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php
if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists()) {
    return null;   // silent fallback to client rendering
}
```

`ensure_bundle_exists` defaults to **true**, and that default assumes one container runs both
PHP and Node. This deployment splits them deliberately — the Dockerfile copies the bundle into
the `ssr` stage only, to keep the PHP image lean — so the `app` container has no
`bootstrap/ssr/` directory and the guard fired on every request while the `ssr` service sat up,
healthy, holding the bundle and answering `http://ssr:13714/health` with a 200.

No log line. No exception. Nothing looks wrong in a browser, because the client hydrates.

Verified before changing anything: taking the exact Inertia payload production served for
`/nl-nl/brand/jbl` and POSTing it to that service returned
`<title data-inertia="">JBL · GiftCoves</title>` and 148 KB of body HTML containing the `<h1>`.
The rendering worked; the app was throwing it away.

**Fixed in [`config/inertia.php`](../../config/inertia.php), not in an environment variable.**
The split is a property of this repository rather than of one deployment, so an environment
variable would leave the next environment inheriting the same silent failure. `.env.example`
and `docker-compose.coolify.yml` both carry a note saying not to turn it back on.

`SeoTest::inertia_does_not_look_for_an_ssr_bundle_the_app_container_never_has` pins the config
value. It cannot be asserted through a rendered page: the suite runs with
`INERTIA_SSR_ENABLED=false` (see [testing.md](../testing.md)), so no test renders through SSR
at all.

---

## Pages that were indexable with nothing on them

`/lists`, `/santa` and `/login` served `index, follow, max-image-preview:large` with **no
`PageMeta` call at all** — no og:title, no meta description.

- `/lists` and `/santa` are public and explain themselves to a visitor with no account, which
  is the version a crawler sees. They keep `index` and now carry copy written for that
  signed-out page.
- `/login` is `noindex, follow`. A sign-in form has nothing to rank for and spends crawl budget
  that belongs to products and guides. `follow`, never `nofollow` — the site chrome is still
  the way out.

### The test that was supposed to catch this

`SeoTest::every_indexable_static_page_carries_a_title_and_a_description` claimed in its own
docblock to walk "every static indexable page". It walked a hard-coded list of six paths, and
all three of these were added to the route table long after it was written.

A hand-kept list cannot catch the page nobody remembered to add to it, which is the only kind
that ever has this bug. **The routes are the list now**: every GET route under `{market}/`
taking no other parameter. Non-200 responses, non-HTML responses and pages that ask for
`noindex` fall out on their own, so `/login` is expected to be skipped rather than excluded by
name. Generated images under `{market}/og/` are skipped by prefix — they render a card apiece,
which cost 60 seconds, and they have their own tests.

The sweep was checked by removing the `/lists` metadata again and confirming it fails naming
that exact path.

---

## Personas

`theme_title` — "De wandelaar", "The one who reads" — is a good heading and an unsearchable
listing: it contains no word anybody types. The query is *gift for someone who reads*, and the
page is exactly that answer.

`gift_ideas.persona_seo_title` wraps it (`Cadeau voor :persona`, `Gift ideas for :persona`) with
the article lower-cased so the template reads as one sentence, and falls back to the bare title
when the two together run past 48. That is not a rare edge — "The one who is always going
somewhere" is a published persona and needs it.

**Daily Coves are deliberately untouched.** "Het koffiekonijnenhol" is not trying to rank, and
dressing it up as a keyword would cost the thing that makes it worth reading.

> A per-Cove override column (`meta_title`, beside the `meta_description` that already exists)
> is the next step if an editor ever wants to write one by hand. It was left out because the
> template handles all thirty published personas correctly and a column nobody has asked for is
> a column nobody will fill.

---

## Not done

- **`brand_stats.top_category` in the brand title** — see the note under Brand.
- **Cross-language product titles.** ~4.5% of `be-fr` titles are Dutch, and neither
  `ProductGrouper` nor `ProductTitle` addresses it; see
  [product-titles.md](product-titles.md#language-is-not-one-of-the-tests).
- **`en` has no multi-merchant groups at all** — 0 of 16,531 — so `seo_compare` never fires in
  English and every product page there falls to `seo_single`. That is a supply question rather
  than a copy one, but it decides what the English titles can honestly say.
