---
name: Product cards in prose
area: Content / Frontend
status: Active
date_added: 2026-08-30
---

# Product cards in prose

**A product's card is rendered under the paragraph that writes about it, and every product gets a
paragraph.**

The placeholder is `[[product:1234]]` — the link token that already existed. There is no second
syntax, and deliberately so: a page needed one way of saying "this is about that product", and the
one it had was already escaped, allowlisted and tested. See [CoveMarkup](../../app/Services/Guides/CoveMarkup.php).

## What this replaces

Prose, and then a grid of everything the prose was about. A paragraph arguing for a kettle pointed
at a card three screens down, and the reader had to hold the name in their head to find it. That is
a catalogue with an introduction.

The Daily Cove and the gift persona were moved off that shape earlier. Two things were left:

- **A guide was still prose-then-list.** Its intro and its "how to choose" section ran as a wall of
  text, and the ranked `<ol>` sat underneath with a two-sentence blurb inside each card.
- **Only a *curated* Cove was told to write about every product.** An engine-picked one was told the
  opposite — *"pick two or three worth a sentence and let the rest speak for themselves"* — which
  was correct while a grid carried the remainder, and became a bug the moment the grid stopped
  being where the writing lived.

## How it works

`App\Services\Editorial\ProseCards` splits a block of prose on blank lines and, for each paragraph,
reads the `[[product:N]]` ids back out of it. Two filters, for two different failures:

- **Allowlisted only.** A token naming a product that is not on this page already renders as plain
  text, so pairing a card to it would put a product on the page that the sentence beside it could
  not link to — the one state where a reader can see the two disagree.
- **First mention only.** Copy repeats a name naturally; a second identical card reads as a
  stutter rather than as emphasis.

The dedupe spans the **whole document**, not one block, which is why `ProseCards` is constructed per
page render and never resolved from the container as a singleton. A guide asks it for its intro and
then its body, and a product introduced up top must not reappear halfway down.

| Page | Prose | Where the remainder goes |
|---|---|---|
| Daily Cove | `editorial` | a grid of up to six unnamed finds |
| Gift persona | `editorial` | the same grid |
| Guide, seasonal | `theme_blurb` + `body` | the ranked `<ol>`, minus anything the article named |
| Advice, shop | `body` | nothing — these have no shortlist at all |

### The guide's list survives as a fallback

This was the decision worth arguing about. A buying guide's `<ol>` carries a rank, a "best for X"
verdict and the `ItemList` structured data; folding it into flowing prose outright would have
rewritten how the pages that earn the site's search traffic are marked up.

So the list stays and renders **only what the article did not reach**. When the writing covers all
seven products it is empty and does not render at all. When the model skips one — or when the guide
was written before any of this and its body carries no tokens — the shortlist appears in full,
exactly as before. Nothing regresses on an old page, and nothing is duplicated on a new one.

Two consequences:

- **The whole shortlist still ships to the client**, and the filtering happens in the React page.
  Shrinking it server-side to "the leftovers" would also shrink the `ItemList` built from it, and
  under-report a page that does rank all of them.
- **The inline card carries no `copy`.** The paragraph above it is the writing about that product;
  printing the item's own blurb underneath would say the same thing twice in two voices. `copy` is
  what the fallback list falls back to, which is the only place it still earns its keep.

**"How to choose" loses its heading when the body names products.** It is a heading about decisions,
and it stops being true once the body is also where the products are discussed. The page decides
that per guide rather than per kind, so a writer who keeps the two sections apart keeps the heading.

## The rule the prompt bank cannot delete

`ProseCards::promptContract()` lives next to the walk that enforces it, and both `EditionBuilder` and
`GuideWriter` append it after the editable system prompt:

> - Write about EVERY product listed below. Each one gets its own paragraph, naming it with its link
>   token where it is discussed.
> - One product per paragraph. Its card is rendered directly underneath the paragraph that names it,
>   so two products in one paragraph stacks both cards under it and reads as a caption for a pair.

This is not house style, which is why an editor cannot edit it away. It is a description of what
`claim()` does, and a prompt that stopped asking for it would empty the article of products and
leave the whole shortlist stacked at the foot of the page — with no error, and no symptom until
somebody read the page. Same reasoning as the link-token contract beside it. See
[prompt-bank.md](prompt-bank.md).

Curation still adds two rules on top — *take them in the order given*, and *the note is why this one
is here* — because those are facts about the plan in front of the builder, which `ProseCards` knows
nothing about. What curation no longer decides is **whether** every product is covered.

## Three numbers moved with it

All three are the same failure: a cut that lands mid-paragraph in the last product takes its link
token with it, so that product loses its card as well as its sentences.

| | Was | Now | Why |
|---|---|---|---|
| `EditionBuilder::EDITORIAL_LIMIT` | 4000 | 8000 | seven finds at 4000 is 570 characters each |
| the Cove editorial call's `maxTokens` | 1200 | 2200 | the model now owes a paragraph per find |
| `GuideWriter`'s `maxTokens` | 2500 | 3500 | and the `items` array is last in the schema, so a short budget costs the fallback copy too |
| `GuideWriter`'s body cap | 3000 | 6000 | the body is the article now, not only the decisions |
| `editorial_api.max_editorial_chars` | 4000 | 8000 | it exists to match `EDITORIAL_LIMIT`, so an author is told at the door rather than silently truncated |

## The retry widened too

An article naming **none** of its products is worth exactly one more call. That check used to apply
only to a curated plan, on the reasoning that an engine-picked one was allowed to write about two or
three; it now applies to every kind, because a product no paragraph names has no card in the article
and no sentence anywhere.

Still not a loop. The daily AI cap is shared with the guides and the trends pass, and a builder that
argues with the model spends the budget every other feature needs that day. If the second attempt is
no better the prose publishes anyway: it is about the right products, it merely did not link them,
and no prose at all is the worse outcome.

## Invariants this does not touch

- **AI is still only called from a queued job.** Nothing here calls a model at render; `ProseCards`
  reads tokens out of stored text.
- **Tokens are stored unresolved.** The anchors follow the market the page is read in, and a product
  that later disappears degrades to plain text rather than leaving a dead link in a row nobody
  revisits.
- **Escape first, resolve second.** The HTML is still `CoveMarkup`'s: the writer's text is escaped
  before any `<a>` is added, so a writer cannot introduce a tag or a URL. `CoveMarkupTest` asserts
  both.

## Tests

- [tests/Unit/ProseCardsTest.php](../../tests/Unit/ProseCardsTest.php) — the pairing itself: first
  mention wins, dedupe spans the document, a token outside the allowlist gets no card.
- [tests/Feature/ProductCardsInProseTest.php](../../tests/Feature/ProductCardsInProseTest.php) — the
  guide page's props, the list taking the remainder, and the prompt rule surviving an edited
  template.

## A product token without a label used to print its id

Fixed 2026-09-01. `[[product:1234|the lockable diary]]` renders the label; `[[product:1234]]`
rendered **`1234`**. Three published editions read like this:

> …rain is a different sport than walking to school in the rain, and the **6609172** is built for the
> version on wheels…

Linked, escaped, and pointed at the right page. The only thing wrong was the words a reader sees,
which is why nothing caught it: every test asserted the destination.

`CoveMarkup` fell back to the token's *value* for any token missing a label, which is right for every
kind but this one. A brand token's value is "Sony" and a search token's is the phrase — there the
value **is** the words. A product is addressed by id, so the value is a database key.

Two changes, and the order matters:

1. **`CoveMarkup::fallbackLabel()`** gives an unlabelled product token the product's own title, from
   the allowlist the renderer already holds. It fixes the three live editions with no rewriting and
   guarantees a number can never reach a sentence again. `plain()` takes an optional allowlist and
   does the same, for meta descriptions and email.
2. **The prompt stopped teaching the bare form.** `EditionBuilder` and `GuideWriter` listed the
   products a writer may use as `- [[product:6609172]] Title (category)` — the strongest example the
   model ever saw of what a token looks like, and it showed one with no label. Those lists now hand
   over the id as a plain fact (`- id 6609172: Title (category)`), leaving
   `CoveMarkup::promptContract()` the only place the token shape is stated, where it now says the
   label is required and asks for the writer's own two or three words rather than the feed title.

The title fallback is deliberately **not** shortened. A feed title runs long and reads like a spec
sheet, so a missing label stays visible to whoever reads the page instead of being quietly
acceptable. It is a floor under the writing, not a substitute for it.

Guards: `CoveMarkupTest::a_product_token_without_a_label_is_never_rendered_as_its_id`,
`::plain_text_gives_an_unlabelled_product_its_title_too`, and `::the_prompt_contract_demands_a_label`
— the last because the renderer's fallback would otherwise let the contract quietly rot.
