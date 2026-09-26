---
name: Community Coves
area: Coves / Wishlist / Community
status: Active
date_added: 2026-09-26
---

# Community Coves

The owner's request of 2026-09-26: *use the gifts created by others as suggestions for others.* This
is the part of it where **a list its owner chooses to publish becomes a Cove other people can find,
save and copy**, and where the Gift Finder suggests "Coves others made for someone like this".

A *Community Cove* is a list (a `wishlists` row) with `published_at` set. It is the "Publish Cove"
verb of roadmap step 6 ([strategy.md](../strategy.md), "Interoperable Coves";
`.claude/plans/roadmap-6-interoperable-coves.md`). Follow Cove, the rest of that step, is not built.

## What the owner does

On their own list, under **Share**, a section *Publish as a Community Cove*. It is off until pressed.
The owner writes a **public title**, chooses whether their **first name** is shown (default: not),
and presses Publish. They can change the title, and take it down again, at any time.

Before the press the section says, in the owner's language, what the public page will say about who
the list is for ("For a dad, Birthday") and, behind the info icon, everything a stranger will and
will not see.

Only the **owner**, and only an owner **with an account**, may publish: somebody has to be able to
come back and take it down. A collaborator, even an editor, cannot publish somebody else's list.

## What a stranger sees, and what never reaches them

`App\Services\Cove\CommunityCoves` is the only place that decides this. The page, the listing, the
band on `/coves`, the Gift Finder, the saved view and "Make it my list" all go through it, so there is
one answer to "does this leak?" rather than six.

| Shown | Never shown |
|---|---|
| The public title the owner wrote | The list's own title and description |
| Catalogue products, with live price and a link to our product page | Notes on items |
| Hand-written items, by title and the price the owner typed | A hand-written item's link and photo |
| Who it is for as a kind of person ("for a dad") | The recipient's name, and any free-text relationship |
| The occasion ("Birthday") | The occasion's date, the delivery address |
| The owner's first name, only if they ticked it | Their full name, their email, anything before the `@` |
| How many people saved it | Claims, claim names, pledges, votes, messages, the share token, the list id |

Why each of the less obvious rows:

- **A separate public title** (`wishlists.public_title`). A list's own title is very often a name
  ("Emma's 40th"). The public title is prefilled with the list's title only when it passes every
  check; otherwise with words we write from the relationship ("Gift ideas for a dad"). Publishing
  refuses a title that contains any word of three letters or more from the recipient's name, and
  anything the Ask-others flat screen (`PostScreen`) holds: links, email addresses, phone numbers,
  shouting.
- **The relationship only as a closed value.** `recipients.relationship` is free text, and free text
  there is often a name. Only a value that is exactly one of `RecipientType` is shown, as our own
  words (`site.community.for.*`), and only on a list about somebody else. On an owner's own wish list
  the page says "A wish list".
- **Hand-written items without link or photo.** A typed link on a public page is where a list turns
  into an advert, and a photo taken by the owner may show their home. The title and price are what
  make the item useful to a stranger. A hand-written title the flat screen holds is left out.
- **First name, never the email fallback.** `User::displayName()` falls back to the part of the
  email before the `@`, which is fine among friends and a leak on a public page. `firstName()` uses
  the first word of `users.name` and nothing else; with no name on the account the page says "Made
  by someone on GiftCoves" whatever the owner ticked.
- **Invariant 4 does not come into it**, because no claim state is read at all: the public page is
  built from the items and their products, never from `ClaimView`. The claimed item's row carries a
  hash and a name; neither is ever selected into a payload here. `CommunityCoveTest` asserts that
  claims, notes, names, the share token and the list id are all absent from the page's props.

Amazon items (which must be fetched live, invariant 6) and items whose product has gone are left out.
A list needs **three** things a stranger may see before it can be published: fewer is a thought, not
a Cove, and the thinnest page the site could publish.

## Which lists may be published

Any kind: a wish list of one's own (`mine`), a list about somebody (`for_someone`), a group list.
The roadmap plan proposed `mine` only, because a list about somebody else is about a person who
never agreed to be public. The owner's request is precisely the other case ("Coves others made for
someone like this" are lists about somebody), so this was decided the other way, and the privacy
table above is what makes it safe: nothing on the page identifies the person.

## Publishing is its own act

Default off, and **never inferred from sharing**. `wishlists.visibility` (private / link) is left
exactly as it was: a list can be shared by link and not published, or published and not shared. The
public page is a read-only view; it grants nothing the share link grants (claiming, suggesting,
the board). `ListVisibility::Public` still exists in the enum from an earlier idea and is not used
by this feature; reusing it would have tied publishing to the share link, which is the one
coupling the owner's privacy depends on not having.

**Unpublishing is immediate.** `published_at` goes back to null. The address answers **410 Gone**
(so search engines drop it), the Cove leaves the listing, the Gift Finder and everyone's saved view,
and bookmarks are kept so they come back if the owner publishes again. The slug is kept too, so the
address comes back with it.

**Handing a list over unpublishes it** (`HandoverController`). The giver published it; the recipient
who now owns it never agreed to a public page, and "show my first name" was the giver's choice about
the giver, which would otherwise have put the new owner's name on it.

## The address: `/{market}/coves/community/{slug}`

- **Under `/coves`**, because a Community Cove is a Cove and `/coves` is where Coves are browsed
  ([all-coves.md](all-coves.md)). The index is `/{market}/coves/community`.
- **Two literal segments before the slug.** all-coves.md records why `/coves/{slug}` must never be
  a catch-all: `/coves/subscribe`, `/coves/confirm/...` and `/coves/unsubscribe/...` live beside it,
  and a person naming their Cove "subscribe" would shadow them. `community` is a literal segment;
  nothing a visitor types can reach the level above the slug.
- **Not `/l/{token}`.** That is the share link: robots.txt disallows it, it carries the claiming
  rights, and it must stay unguessable. A published list gets a second, separate address.
- **Not the per-market search word** the roadmap plan suggested (`/wish-list/`, `/verlanglijstje/`).
  strategy.md, "Naming and SEO", says URLs stay as they are and the searched-for words go in the
  title, the heading and the description; renaming earns no ranking.
- **The slug** is the public title slugged, cut at 60 characters, plus six random characters
  (`board-games-for-a-dad-k3x9q2`). Set at the first publication and **never changed**, so a title
  edited later does not move an address somebody saved. The suffix means two lists called "Birthday
  dad" never collide, and the address says nothing about how many Community Coves exist.
- **Market-scoped.** A Community Cove lives in the market its list was made in (`wishlists.market`).
  The same slug under another market is a 404, and each market's listing and Gift Finder show only
  its own. A list may hold products from several markets (see wishlists.md); each product links to
  its own market's page, and no offer is ever merged across markets (invariant 2).

## Indexing: not at first

A Community Cove is `noindex, follow` until **all three** hold (`CommunityCoves::isIndexable()`):

1. **A week on the site** (`INDEX_AFTER_DAYS = 7`), so a report and an admin's hide can land before
   a crawler does.
2. **Eight things on it** (`INDEX_MIN_ITEMS = 8`), the house minimum for an editorial Cove. Below
   that the page is a thin list with a title and no writing.
3. **Saved by three people** (`INDEX_MIN_SAVES = 3`), other than the owner (who cannot save their
   own). The only available sign that somebody found it worth keeping, standing in for an editor.

Why not index everything: these pages have no writing, many will hold the same popular products, and
titles are written by strangers and published without review. Indexing all of them invites thin and
near-duplicate pages, and makes the domain an open publishing endpoint for anybody who wants a link
from it. Why not index nothing: the strategy is that pages people search for ("gift ideas for a dad
who likes board games") should be findable, and a list that three people kept is exactly such a
page.

This is an exception to the owner's rule that every public page is indexable
([seo.md](seo.md), 2026-09-12), of the same kind as the one already made for an unanswered question
on the Ask others board: text strangers wrote is held back until something shows it is worth a
search engine's attention. It is `noindex, follow`, never `nofollow`, so crawlers still reach the
products on it.

The sitemap lists the same set, decided by the same call, so it never names a page that then says
noindex. The index page `/coves/community` follows the owner's rule and is indexable: it is a
listing with our own heading and intro, like `/coves`. It is not in the sitemap; `/coves` links to it.

## Moderation: published at once, with a safety valve

Ask others ([ask-others.md](ask-others.md)) holds every post until a triage job or a human clears
it. Community Coves do not, and the difference is deliberate:

- What a stranger can write onto the page is **small and screened at the write**: the public title
  (refused if the flat screen holds it or it names the recipient) and hand-written item titles
  (dropped from the page if held). Everything else is catalogue products, which are ours.
- The page is **not indexed** for its first week, whatever happens.
- **A report link** on every Community Cove goes to the help page's form; the browser's referrer
  gives the report the Cove's address, and it lands in the Feedback queue, which is mailed to the
  owner. No second report system.
- **An admin can hide** a Cove in Filament (Community > Community Coves). Hiding sets
  `wishlists.public_hidden_at`, a column separate from `published_at` so the owner cannot undo a
  moderation decision by republishing: publishing refuses a hidden list, and the owner's section
  says it was taken down. **Show again** undoes a mistake.

If abuse appears, the first step is to put a `pending` state in front (the plan's
`published_status`); the columns were chosen so that can be added without moving anything.

## Browsing

- A **Community Coves** band on `/coves`, last, after everything the site wrote: newest twelve.
- `/coves/community`: all of a market's Community Coves, **newest** first or **most saved** first
  (`?sort=saved`), 24 a page.
- Each card: the public title, "For a dad · Birthday · 9 ideas", one product image, "Saved by 3
  people". Plurals are worded on the server (`trans_choice`), because the client's `t()` has none.

## The Gift Finder: "Coves others made for someone like this"

Under the Gift Finder's results, up to three Community Coves nearest the brief
(`CommunityCoves::forBrief()`), in the same market, never the viewer's own:

- A Cove **qualifies** when it is for the same kind of person (the brief's relationship equals the
  recipient's closed relationship) **or** holds a product tagged with one of the brief's interests,
  by editors (`gift_tags`) or by people's lists (`crowd_tags`).
- They are **ordered** by fit: relationship 2, interest 2, same occasion 1; then by saves, then
  newest. The occasion only breaks ties: "a birthday" alone says too little about a person to
  suggest a stranger's list.
- A Cove with fewer than three visible things is skipped.

It is one SQL query with two `EXISTS` subqueries, so it costs one round trip whatever the number of
Community Coves. The UI is one self-contained section at the end of the results
(`CommunityCoveCards`), so other additions to that page merge cleanly.

## Saving and "Make it my list"

The same two buttons as on an editorial Cove ([saved-coves.md](saved-coves.md)), from the same
component (`SaveCove.tsx`, now taking the addresses it posts to).

- **Save** is a row in **`saved_coves`, extended** rather than a sibling table: `set_id` became
  nullable, `wishlist_id` was added, and a CHECK (`num_nonnulls(set_id, wishlist_id) = 1`) keeps one
  target per row. One table because the Saved view is one list of "things I kept", newest first;
  two tables would be two queries and a merge sort in PHP for no gain, and the roadmap plan drew
  the same shape. A Cove that is unpublished or hidden drops out of the saved view and comes back if
  republished, exactly like an unpublished editorial Cove.
- **The owner cannot save their own.** It is already under My Coves, and an owner's save would count
  towards "most saved" and the indexing rule as though a stranger had found it worth keeping.
- **Make it my list** copies what a stranger sees and nothing else: products by their group (each
  in its own market), hand-written items by title and price only. No notes, no links, no photos, no
  claims. A snapshot, in the same order, in the Cove's market.
- A guest's press is stashed with `/save-intent` (`community_cove`: the slug) and finished after
  sign-in by `PendingSave`, as for an editorial Cove.

## Migration

`2026_09_27_000300_a_list_can_be_published_as_a_cove`. Forward-only in practice (the `down()` exists
for local use). Safe on production: every new column is nullable with no default, so Postgres
records it without rewriting `wishlists`; the indexes are built `CONCURRENTLY` outside a transaction;
the CHECKs are added `NOT VALID` then validated; every statement is idempotent. Every existing row
passes both CHECKs (no list is published yet, every saved row has a `set_id`), so there is no guard
that could fail a deploy.

## Files

- `app/Services/Cove/CommunityCoves.php`: what is public, publishing, the listing, `forBrief`,
  `isIndexable`
- `app/Services/Cove/SavedCoves.php`: `saveList`, `unsaveList`, `listButton`, `copyListToList`
- `app/Http/Controllers/CommunityCoveController.php`: index, page, save, copy
- `app/Http/Controllers/ListPublishController.php`: `POST|DELETE /lists/{list}/publish`
- `app/Http/Controllers/WishlistController.php`: `publication()`, the saved view
- `app/Http/Controllers/CovesController.php` (the band), `GiftController.php` (`communityCoves`),
  `SitemapController.php`, `SaveIntentController.php`, `app/Services/Wishlist/PendingSave.php`
- `app/Filament/Resources/CommunityCoves/`: the admin's hide and show again
- `resources/js/Pages/Coves/CommunityCove.tsx`, `Coves/Community.tsx`,
  `resources/js/Components/PublishCove.tsx`, `CommunityCoveCards.tsx`, `SaveCove.tsx`
- `lang/*/site.php`: `community.*`, `coves.community_*`, `help.share_publish`,
  `home.cove_kind_community`
- `tests/Feature/CommunityCoveTest.php`

## Not built

- Follow Cove (notifications when a followed Cove changes).
- Handles and public profiles (roadmap step 7).
- A review queue before publication (see Moderation for when it would be added).
- Hreflang alternates: a Community Cove lives in one market and has no twin.
