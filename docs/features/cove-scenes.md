---
name: Cove scenes
area: Content / Frontend
status: Active — 28 scenes; personas and articles
date_added: 2026-09-05
---

# Cove scenes

**A Cove names a drawing, and the drawing is drawn rather than photographed.**
`App\Enums\CoveScene` + `resources/js/Components/SceneIllustration.tsx`.

Was `PersonaScene` + `PersonaIllustration`, nine values, personas only. It now
covers the two shelves that are shelves of *writing*, because both had the same
problem from opposite ends.

## The problem, from two directions

`/gift-ideas` used the first buyable product's photograph as each persona's
cover. Wrong picture twice over: a page about a *person* wearing a picture of a
*thing*, and a cover that moved whenever stock did — the same persona wearing a
different face from one week to the next, for a reason no reader could see and
no editor chose. That is what the original nine fixed, on 2026-08-31.

`/guides` used nothing at all. In `be-nl`, `nl-nl` and `en` that page is
**entirely Advice Coves** — measured on production, 8 of 8 in each — so it
rendered as eight identical rectangles of text about consumer rights, customs,
reviews and scam messages. A reader who half-remembered one had to read eight
titles to find it.

A shelf of writing has no photograph, so it gets a drawing. Same reasoning,
same visual language, and now the same enum and the same component.

## One enum, two vocabularies

`cove_plans.scene` and `daily_pick_sets.scene` are one column each, so what they
hold has to be one type — a second enum on the same column means a cast that
throws the first time an advice Cove meets the persona cast.

But the vocabularies genuinely do not overlap. A persona names a **kind of
person** (coffee, dogs, sim racing); an article names a **subject** (customs,
reviews, phishing). Offering "coffee" to somebody writing about customs duty is
offering a wrong answer, and a wrong drawing is silent — nothing downstream
reports it and no test can know it is wrong.

So the cases are grouped and `CoveScene::forKind()` decides which half a kind
may name. Three places ask it and none of them keeps its own list:

| Asks | What it does with the answer |
|---|---|
| `CovePlanResource` | Which options the Drawing select offers, and whether it appears at all |
| `CovePlanController::store()` | **422** on a scene the kind cannot mean |
| `AdviceCoveSeeder` | Reports a `scene` key in the content file that is not an article scene |

A Daily and a Shop Cove get an **empty** list, which is how the planner knows
not to offer the field. Neither draws one: a Daily is addressed by its date and
carries the day's products, a Shop Cove is about a named shop with a name to
print.

**The API refuses rather than storing.** It used to store any scene on any kind
on the argument that a scene on a Daily is harmless. That was true while there
was one vocabulary. With two, the same permissiveness puts a parcel at a border
on top of a page about somebody who likes coffee, and the author gets a 200.

## Two defaults, not one

`CoveScene::defaultFor()` — `Someone` for a persona, `Article` for everything
else. Null in the column means "not chosen", and the *controller* resolves it,
so the React component receives a real scene and never a hole.

Two rather than one because the shelves draw different things: an unlabelled
persona is still a person, an unlabelled article is still an article. Sharing
one default would put a portrait at the top of a piece about customs duty.

This is also what lets `/guides` mix kinds safely. A buying guide names no
scene — its substance is a shortlist of photographed products — and it still
gets a card that looks like its neighbours, because half a grid of drawings and
half a grid of blanks reads as images that failed to load.

## The drawings

One `160x116` viewBox, one stroke weight, `currentColor` for every line, the
accent only as a translucent wash. That is what lets a card change colour on
hover and take the drawing with it, and why these survive a palette change
without being redrawn. Decorative: `aria-hidden`, because the title and the
sentence sit beside every one.

`SceneKey` is a union of string literals, not `string`, so a scene the server
can send and the component does not draw is a **compile error** rather than a
blank card.

**An article scene draws its subject, not a document.** A page about customs
illustrated with a generic sheet of paper is the same picture as a page about
reviews, which is no picture at all — the whole point of a mark is that a
returning reader recognises it before reading the title. So the parcel carries a
declaration and a stamp, the stars sit under a lens, and the doorstep is empty.

### Eight were redrawn before they shipped, and that is the process

Rendered at card size and looked at, which is the only way this class of mistake
is ever found — an SVG that is geometrically fine and semantically wrong throws
no error and passes every test. The same thing happened to three of the original
nine.

| Scene | Read as | Fix |
|---|---|---|
| `baking` | A plant in a bowl — i.e. `plants` | The whisk was one closed teardrop, which is a leaf. Three wires meeting at a point |
| `gardening` | Something being thrown out of a bucket | The spout was a single line, which is an arrow. Closed tapering shape with a flared rose |
| `gardening` | A wine glass, for the trowel | Rounded scoop on a stem. Pointed blade and a wider grip |
| `fitness` | Two dumbbells, or a rolling pin | A rolled mat at this size is a pill with a circle in it. Replaced with a kettlebell |
| `reading` | A stack of boxes under a light | Three rectangles are only books because the caption says so. Reading glasses on top |
| `rights` | Two tents, or mountains | The scale pans were `V` shapes. Shallow bowls hanging from the beam |
| `price_history` | Scribble | The dashed history line crossed the tag's own strike-through. Moved above it |
| `customs` | Goods on a shelf | A banded bar spanning the full width at a constant height is a shelf, not a barrier. Replaced with a declaration and a stamp |
| `missing_parcel` | Motion lines | Three accent strokes meant as an absence. A dashed outline in the shape of the missing parcel |

`reading` also had to avoid `CoveIllustration`'s `idea` scene, which is an open
book against a shelf and means "the archive of writing" on the homepage. Two
drawings meaning different things must not be the same drawing.

## Where they render

| Surface | Scene of |
|---|---|
| `/gift-ideas` | Each persona card |
| `/gift-ideas/{slug}` | The persona itself |
| `/` and `/discover` | The persona band |
| `/guides` | Each article card |
| `/guides/{slug}` | The article — **advice only** |

The article page shows it only on an advice Cove. Not a rule about page shape
but about what else is on the page: a buying guide opens onto a shortlist of
photographed products a screen further down, and a generic mark above its title
is decoration competing with them. An advice piece has no product and no picture
at all, and it is the one that arrives cold from search.

## The migrations, and a correction

`2026_09_05_000300_the_articles_get_a_picture_too` widens the CHECK on both
tables to the union. Widening only — every value that was legal is still legal,
so no row is rewritten and it is safe under expand and contract.

It also **froze its predecessor**. `2026_08_31_000200` generated its CHECK from
`PersonaScene::values()`, which meant its meaning changed every time somebody
added a case: a fresh `migrate` would build a constraint the deployed databases
had never had, so the schema a test ran against and the schema production held
would silently disagree — invisible until a row that passed locally was refused
on deploy. A migration is a record of what was done on a day. Its nine values
are now written out.

The widening migration generates its list, because it *is* the current
vocabulary. **Adding a scene means freezing that list and writing another
migration**, exactly as this one froze the last.

## The deploy gate this creates

The API validates against the deployed enum, and the database against the
deployed CHECK. So **a new scene cannot be written to production until the code
is on production** — measured on 2026-09-05, when 13 of 30 persona writes came
back `422 The selected scene is invalid` against a host still running the nine.

That is the correct failure and worth keeping in mind rather than working
around: content that names a value the server does not have is content the
server is right to refuse. Deploy, then write.

## Files

- `app/Enums/CoveScene.php`
- `resources/js/Components/SceneIllustration.tsx`
- `database/migrations/2026_09_05_000300_the_articles_get_a_picture_too.php`
- `database/migrations/2026_08_31_000200_a_persona_names_its_own_drawing.php` — frozen
- `app/Http/Controllers/GuideController.php`, `GiftIdeasController.php`
- `app/Filament/Resources/CovePlans/CovePlanResource.php`
- `app/Http/Controllers/Api/CovePlanController.php`
- `app/Services/Content/AdviceCoveSeeder.php`
- `resources/js/Pages/Guides/Index.tsx`, `Guides/Show.tsx`, `GiftIdeas/Index.tsx`,
  `GiftIdeas/Persona.tsx`, `Home.tsx`, `DiscoverCove.tsx`

## See also

- [gift-personas.md](gift-personas.md) — the shelf the first nine were drawn for
- [advice-coves.md](advice-coves.md) — the ten subjects the article scenes name
- [editorial-api.md](editorial-api.md) — `scene` on `POST /coves`
