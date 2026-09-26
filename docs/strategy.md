# Strategy

**GiftCoves is a place to keep anything you might want to give or get, organised into Coves, and
shared with the people around you.** A Cove is a list of products: "Things I want", "Emma's
birthday", "Our new house", "Christmas 2026", "Best gifts for cyclists", "My favourite independent
shops". Products can come from anywhere. Affiliate feeds are one supplier of products, not the
edge of what the site can hold.

Written 2026-09-26, when the site was repositioned from *an affiliate catalogue with wish lists
attached* to *an open product graph with Coves attached*. ("Product graph" here means the products
we know about, how they relate to each other, and the offers under each one.) This document says
where the site is going and where it stands today. It is not a backlog; each roadmap step below
gets its own feature doc in [features/](features/INDEX.md) when it is built.

---

## Three layers

| Layer | What it is | One line |
|---|---|---|
| **Open Catalogue** | Everything that can be added to GiftCoves | Search, paste a link, scan a barcode, add a photo, or describe it yourself |
| **Coves** | How people and editors organise products | A list of products; an editorial Cove adds writing on top |
| **People** | The social layer | Create, share, contribute, claim, save, follow, discover |

And three pieces of work that serve them:

- **Engine**: make the product and data system underneath open and useful.
- **Structure**: make Coves the thing everything is organised around, and the thing people share.
- **Front page**: get the idea across in five seconds and get people into the system.

---

## 1. The engine

Stop thinking "affiliate catalogue with wish lists attached". Think "open product graph with Coves
attached".

### A. Source is metadata, not identity

A product is a product whether it came from a feed, a shop page a visitor pasted, a barcode or a
description someone typed. Where it came from is a fact *about* it, never what it *is*.

**Where we stand.** Half there. A physical product (`product_groups`) is already identified without
reference to its source: by `(market, identity_key)`, where the key is a validated GTIN-13 or the
brand plus a normalised title ([product-identity.md](features/product-identity.md),
`app/Services/Identity/`). But an offer (`products`) is unique on `(source, external_id, market)`
and `source` is required, so nothing enters the catalogue without a feed. What a visitor types in
lives only on their own list item (`wishlist_items`, `Source::Manual`) and never reaches the
catalogue, so nobody else can find it, compare it or add it.

**Missing.** A product that exists in the catalogue without a feed behind it.

### B. "Add anything" is an engine capability

This may matter more than the next affiliate feed. A visitor pastes
`https://some-small-shop.com/product/xyz` and GiftCoves tries to build the product itself:

> found → identified → enriched → matched to what we already know → added to a Cove

Ways in: a link, a barcode, a photo, a typed description, and search. Later perhaps "I saw this in a
shop, what is it?" from a photo.

**Where we stand.** Search and barcode scanning exist, but the scanner only finds products already
in the catalogue ([barcode-scanner.md](features/barcode-scanner.md)). A typed item can carry a
title, link, image URL, price and note ([wishlists.md](features/wishlists.md)). There is no photo
upload. Page import exists but only for editors, and the reading happens in their browser extension
([page-import.md](features/page-import.md)).

**This reverses a rule.** Today the server never requests a link a visitor supplied, on purpose
(`app/Services/Wishlist/ItemSaver.php`, `app/Services/Search/AmazonLink.php`,
[gifting-lenses.md](features/gifting-lenses.md)). Reading pasted pages is a deliberate change of
strategy, not a bug fix, and it comes with conditions:

- It runs in a queued job, never inside the visitor's request.
- The URL is hostile input: `https:` only, no private or internal addresses (checked again after
  every redirect), a size cap and a time limit on every request.
- It reads what the page publishes about itself (Open Graph, JSON-LD product data). No AI in the
  basic path; if AI is used to identify a product, it is queued and capped like every other AI call.
- An Amazon link is a separate question (see Open questions): Amazon data may not enter the
  catalogue.

### C. Matching products that arrived separately

Opening the system makes this one of the most valuable pieces of technology we can build. These
must not become four unrelated products because they arrived from different places:

- LEGO Technic Ferrari 488
- LEGO Ferrari 488 Technic
- LEGO Technic Ferrari 488 GTE
- LEGO Ferrari 488 #42125

The shape is **product → its offers**, never **feed → product**.

**Where we stand.** Matching is exact only: the same GTIN, or the same brand plus the same
normalised title (`app/Services/Ingestion/ProductGrouper.php`). That favours never merging two
different things over always merging two copies of the same thing, and it leaves many offers
ungrouped on purpose. The four LEGO titles above would stay apart unless a barcode joined them.
There is no fuzzy matching and no admin tool to merge or split products.

**Missing.** Matching in steps: barcode first, then brand plus model number, then similar titles
put in front of a person to confirm; and an admin merge and split.

### D. Relevance and commercial value are separate

The ranking has to be able to say "this product is relevant" without saying "this product earns us
money". If it cannot, the open positioning gets compromised sooner or later.

Signals worth ranking on: relevance, popularity, quality of the product data, engagement, saves,
shares, freshness, price, availability. A commercial relationship is a separate signal at most,
with strict limits.

The public promise this makes possible:

> **Commercial relationships don't determine whether something can appear on GiftCoves.**

**Where we stand.** This is already true, and the promise can be made honestly today. The
commission rate is stored on each offer (`app/Services/Ingestion/OfferUpserter.php`) and read by
nothing. Search ranks by text match and number of shops; the best offer is in stock first, then
cheapest; gift suggestions rank by fit, surprise and demand. No ranking or filter looks at whether
a link earns commission. The one exception has nothing to do with money: Amazon's terms forbid us
storing its products ([amazon-compliance.md](features/amazon-compliance.md)).

**Missing.** Saves, shares, data quality and engagement as ranking inputs. **Rule from here on:** no
change may make commission decide whether a product can appear.

### E. Recommendations start from intent

Don't start from "what products do we have?". Start from:

- Who is it for?
- Why are they getting it?
- What do they like?
- What's the budget?
- What kind of thing are you looking for?

"Gift → Dad → 60 → cooking → €50–€100" becomes a structured object, and that object can drive
search, gift guides, Coves, recommendations, SEO landing pages and AI help. That is a better
foundation than hundreds of hand-kept gift pages.

**Where we stand.** The object exists: `TasteBrief` (`app/Services/Gift/TasteBrief.php`) holds
interests, style, budget, relationship, occasion, age band, things to avoid and values. It is saved
on a recipient and consumed by the suggestion engine behind the Gift Whisperer
([gift-whisperer.md](features/gift-whisperer.md)). Nothing else uses it: not search, not guides,
not Coves. Gift personas are hand-written Coves, not briefs.

**Missing.** `TasteBrief` behind search filters, guide landing pages and Cove suggestions.

---

## 2. Structure: Coves

**A Cove is a list of products. An editorial Cove is a Cove with writing added.**

That is a structural suggestion, not a rule about presentation. Visitors do not have to see personal
lists and editorial Coves as the same thing, and whether the code for the two ever merges is
decided case by case by whatever is more efficient and useful.

**Where we stand.** Both halves exist, under two names and two models:

- **Editorial Coves**: daily, persona, guide, seasonal, advice, shop, brand (`app/Enums/CoveKind.php`,
  [cove-planner.md](features/cove-planner.md)).
- **Lists**: mine, for someone, group (`app/Enums/ListKind.php`), with occasions and recipients
  ([wishlists.md](features/wishlists.md), [list-taxonomy.md](features/list-taxonomy.md)). "Emma's
  birthday" and "Christmas 2026" already work as lists.

### Interoperable Coves

This is where the system gets genuinely interesting: the same few actions work on every Cove,
whoever made it.

| When I… | I can… | Today |
|---|---|---|
| find a product | **Add to Cove** | Works: save to a list from search, product pages and editorial Coves (`SaveToList`) |
| like one item in somebody else's Cove | **Add to my Cove** | Works on shared lists ([copying-items.md](features/copying-items.md)) and on editorial Coves |
| see somebody else's Cove | **Save this Cove** | Missing |
| see a brand's Cove, or anyone's | **Follow Cove** | Missing in the app. The only subscription is the Daily Cove by email ([cove-subscriptions.md](features/cove-subscriptions.md)) |
| make a gift guide or a list worth sharing | **Publish Cove** | Half built: lists have a `public` setting (`ListVisibility`), but nothing shows or indexes public lists |

Together these are a social layer around product discovery, and they are where a network effect
would come from.

---

## 3. People

People can create Coves, share and follow them, contribute to group Coves, reserve and claim gifts,
recommend products, and discover other people's Coves.

**Where we stand.** Private sharing is strong: share links, collaborators who can view or edit,
claims (hidden from the list owner by default, invariant 4), votes, pledges towards one gift,
suggestions, messages, friends, Secret Santa and Ask others ([sharing.md](features/sharing.md),
[friends.md](features/friends.md)). Following is built and switched off: the `user_follows` table
and `app/Services/Social/FollowGraph.php` exist, and no route or page calls them.

**Missing.** Public profiles with a handle, discovering other people's Coves, following, and
recommendations as their own thing.

---

## Naming and SEO

Nobody searches for "cove". People search for *wish list*, *verlanglijstje*, *liste d'envies*,
*lista de deseos*, *gift ideas for cyclists*. So:

- **"Cove" is the brand word** for any collection: in the pitch, the navigation and how the site
  talks about itself.
- **Pages meant to be found lead with the words people search for**, in the title, the heading and
  the meta description: "Emma's birthday wish list · GiftCoves", "Gift ideas for cyclists".
- **URLs stay as they are** (`/lists`, `/l/{token}`, `/coves`, `/guides`, the cove slugs). Renaming
  them earns no ranking and costs a round of redirects, the same reasoning that kept the
  slash-less URLs.
- **No table is renamed for the sake of the word.** Private and link-only lists are not indexed
  anyway; the question only matters for public Coves.

---

## Front page brief

The front page has one job: get the idea across in five seconds, then get the visitor into the
system.

- **The idea:** keep anything, from any shop, in Coves you share with the people who matter.
- **The action:** start a Cove, by searching, pasting a link or scanning.
- **Starting point:** the current homepage ([homepage.md](features/homepage.md), "Give better. Get
  what you actually want."), which already sells lists made "from anything you find online or
  offline". Rewriting the copy is a later change, made against this brief.

---

## Roadmap

In order, each step small enough to ship and prove on its own.

1. **Anything goes in.** Products in the catalogue that need no feed; reading a pasted link in a
   queued job; an unknown barcode becomes an item; photo upload.
2. **Matching.** Beyond exact keys: barcode, then brand plus model number, then similar titles
   confirmed by a person; admin merge and split.
3. **Interoperable Coves.** Save this Cove, Follow Cove in the app, Publish Cove (public lists with
   titles that follow the naming rule above).
4. **Intent everywhere.** `TasteBrief` behind search filters, guide landing pages and Cove
   suggestions.
5. **People find each other.** Handles and `/u/{handle}` profiles, following switched on,
   discovery of public Coves, recommendations; saves and shares feed the ranking.

---

## What does not change

Opening the catalogue does not loosen the invariants in [CLAUDE.md](../.claude/CLAUDE.md). Each one
draws an edge around it:

- **Amazon never enters the catalogue**, however a product arrives.
- **Links from outside are hostile input**, whether a feed sent them or a visitor pasted them.
- **Product identity is scoped to the market**: a pasted product is matched within its market only.
- **Claims stay hidden from the list owner by default**, including on public and followed Coves.
- **No AI inside a web request**: identifying a product from a photo is queued and capped.
- **Prices are integer cents**, including prices read from a pasted page.

## Open questions

- **A visitor pastes an Amazon link.** It cannot enter the catalogue. Does it stay a plain link on
  that one list item, as today, or is it refused?
- **Moderation of public Coves.** Who sees them before search engines do, and what can be reported.
- **A visitor's product meeting a feed's product.** Can the two merge into one, and who confirms it?
- **"What is this?" from a photo.** Which model, what daily cap, and what the visitor sees when it
  is not sure.
