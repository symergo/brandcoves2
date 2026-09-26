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

And the pieces of work that serve them:

- **Engine** (section 1): make the product and data system underneath open and useful.
- **Structure** (section 2): make Coves the thing everything is organised around, and the thing
  people share.
- **Product pages** (section 4): a product is a meeting point of shops, people and Coves.
- **Search** (section 5): turn what people type into intent, and let that intent drive the site.
- **Front page** (section 6): get the idea across in five seconds and get people into the system.

### What each part of the business is for

| Layer | What it is |
|---|---|
| **Supply** | Affiliate feeds, API connectors, and whatever people paste or scan. Where products come from |
| **Infrastructure** | The product graph: products, their offers, what they are matched to |
| **Organising and social** | Coves: what people and editors collect, keep and share |
| **Acquisition** | Discovery: search engines, social posts and shared links bring people in |

The affiliate catalogue is the supply layer. It is not the product.

### The growth loop

```
            Google / social / a shared link
                          ↓
                      Discover
                          ↓
                  a product or a Cove
                          ↓
                       Save it
                          ↓
                    Create a Cove
                          ↓
                        Share
                     ↙        ↘
             a gift buyer    a friend
                     ↘        ↙
                  visits GiftCoves
                          ↓
                   creates a Cove
                          ↓
                        Share …
```

What each arrow needs from the site, and where it stands:

- **Discover → save:** every product and Cove has a save control (`SaveToList`), and saving before
  signing in is kept and finished after (`PendingSave`). Works.
- **Save → create a Cove:** a save can create a list in the same step. Works.
- **Create → share:** share links, share-with-friends, handing a list over. Works.
- **Share → the reader arrives:** a shared list opens without an account. Works.
- **The reader creates their own:** the weak arrow. A visitor on somebody's list is asked to claim,
  not to start a Cove of their own. The front page (section 6) and interoperable Coves ("Add to my
  Cove", "Save this Cove") are what close it.

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

**Where we stand (built 2026-09-26, [pasted-links.md](features/pasted-links.md)).** A link pasted
into a list is looked up and the item fills itself in; a photo of your own can be added; a scanned
barcode nobody sells yet is kept and becomes the product once a shop sells it. Still missing: what a
page says does not yet become a catalogue offer, so a pasted shop is not searchable by others (a
price read once cannot sit in "cheapest offer"; this waits for matching, C below).

**This reversed a rule.** Until then the server never requested a link a visitor supplied, on
purpose. Reading pasted pages is a deliberate change of strategy, not a bug fix, and it comes with
conditions, all of them now in the code:

- **Our own data and connectors come first.** A link to a shop we hold in the feed database or
  have an API connector for is resolved through that (an Amazon link by the ASIN in its URL, a bol
  or eBay link by its product id, a feed merchant's link by its stored deep link). Only a link
  nothing recognises is fetched and read.
- It runs in a queued job, never inside the visitor's request.
- The URL is hostile input: `https:` only, no private or internal addresses (checked again after
  every redirect), a size cap and a time limit on every request.
- It reads what the page publishes about itself (Open Graph, JSON-LD product data). No AI in the
  basic path; if AI is used to identify a product, it is queued and capped like every other AI call.
- An Amazon link is never fetched: its ASIN is looked up in what we already know, and if that finds
  nothing the item stays as the person typed it. Amazon data does not enter the catalogue.

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

**Missing.** `TasteBrief` behind search (section 5), guide landing pages and Cove suggestions.

### F. A list's intent teaches the catalogue

The owner's idea (2026-09-26): **when somebody builds a list with an intent, the products on it
carry that intent for everybody else.** A list called "Dad's 60th", for a recipient who is a father
and loves cooking, filled from a search for "gift for dad who cooks, €50–€100", is a person saying
"these things fit that". Summed over many lists, that is a better answer to "what do people give a
dad who cooks" than any tag an editor can write, and it grows with use.

Where a list's intent comes from, all of it already stored or one step away:

- the recipient: relationship, age band, interests (`recipients`, `TasteBrief::fromRecipient`);
- the occasion: `wishlists.event_type` (birthday, Christmas, new home…);
- the search the product was saved from, once search reads intent (section 5): recorded with the
  save, since that is the moment the intent is known.

How it must work, so it helps and cannot hurt:

- **Counted, not copied.** Each product gets counts per intent tag ("saved for a father 41 times,
  for cooking 37, for a 60th 12"), kept apart from the editors' `gift_tags`. The suggestion engine
  and search read both; a person's list never changes what an editor wrote.
- **A tag shows only when several different people agree** (a threshold such as five distinct list
  owners), so no single list, and no single person trying to game it, moves anything.
- **Nothing about a person leaks.** Counts only, with no list or owner behind them. What a list is
  for is never shown to anyone but the people it is shared with. **Private lists count too** (the
  owner's decision, 2026-09-26): they are most of the lists, and as anonymous counts above a
  threshold they reveal nothing. The privacy page says so.
- **No AI.** The intent is what was chosen in a form or read from a search, in the same closed
  vocabulary (`GiftTags`).

This is also how gift tags reach the tens of thousands of products no editor will ever tag.

### G. A person's own wish list is intent too

The owner's second idea (2026-09-26): **a wish list somebody keeps for themselves says what they
like, and the products on it are linked through that person.** Two uses, one signal:

- **A gift for somebody who keeps a list.** Their list is the best brief there is. The interests,
  price band, brands and kinds of thing on it become the intent for a gift for them (a
  `TasteBrief` derived from the list), so suggestions land near what they asked for without
  repeating it. Giving somebody something from their list stays the first answer; this is for the
  second present, or when everything is claimed. The list already knows who it belongs to (a `mine`
  list, or a recipient linked to the giver's list for them).
- **Products linked by the people who want them.** When many people keep product A and product B
  on their own lists, A and B belong together for reasons no category captures: "people who want
  this also want…". That feeds the product page's Related (section 4), the suggestion engine, and
  intent search, and like F it grows with use.

The same rules as F: counted over many lists with a threshold of distinct people, never one person's
list shown to anyone who was not given it, and no AI. Claims play no part (invariant 4): what was
bought is never a signal, only what was wished for.

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

## 4. Product pages: a node, not a click-out

A product page is where a product, its shops, the people who want it and the Coves it is in meet:

> **LEGO Technic Ferrari** · €89.99–€104.95 · available from 6 shops [View shops]
> [Add to my Cove] · ❤️ Saved by 342 people · Found in 18 Coves
> Related: Gifts for car lovers · Gifts under €100 · LEGO Coves

Then it is no longer an affiliate click-out page but a node in a network, and that is much harder
to copy.

**Where we stand.** The page shows the lowest price, the number of shops, every offer (in stock
first, then cheapest), a save button, price and stock alerts, sharing and the shop's description
(`ProductController`, `Pages/Product.tsx`). Missing: the price range (`maxPrice` is sent but not
shown), how many people saved it, which Coves hold it, and anything related. The data is there:
`wishlist_items.group_id` (saves) and `daily_picks.group_id` (editorial Coves).

- **"Saved by N"** counts distinct people, never claims, and shows only from a small threshold, so a
  count of one cannot point at one person's list.
- **"Found in N Coves"** counts published editorial Coves now, and public lists once they exist.
- **Related** comes from the Coves holding it, its brand page, a price band, intent (section 5),
  and the products people keep on their own lists next to this one (engine G).

## 5. Search is a core product

Instead of "Search products…", let people type what they mean:

> Gift for my sister who loves gardening, €30–€50

and answer with the interpretation first, then the results:

> **Sister · gardening · €30–€50**

You don't need generative AI for this. What matters is turning language into structured intent (the
`TasteBrief` of engine E) and showing it back, editable, so a wrong reading costs one tap. The same
intent then powers search, gift pages, Coves and recommendations, and it is what a saved product
passes on to the catalogue (engine F).

**Where we stand.** Search reads the words as they are typed, plus explicit price filters
(`SearchQuery::fromRequest`). Nothing parses "for my sister", "under 50" or "€30–€50". The vocabulary
exists (`GiftTags`, `Interest`, `RecipientType`) but has no synonyms per language, and the Gift
Whisperer takes intent only as a step-by-step form.

**Missing.** A parser (pure, per-language word lists, budget patterns), the interpretation shown as
removable chips, and the suggestion engine answering when intent was found.

## 6. The front page and the navigation

The homepage should not explain every feature. It answers three questions: what is this, why should
I care, what can I do now. The owner's structure:

1. **Hero.** "Find things worth giving, getting and sharing." GiftCoves is an open place to discover
   products from shops, brands and independent sellers, and save them in Coves you can keep, share
   or give. [Create a Cove] [Explore Coves]. Then: *Search anything · Add anything · Share
   anything.*
2. **Three ways in.** 🎁 Looking for a gift? (who, what they like, budget → [Find a gift]) ·
   ❤️ Building a wish list? (save things from anywhere → [Create my Cove]) · ✨ Just browsing?
   (Coves by GiftCoves and the community → [Explore]). One per audience.
3. **The openness.** "From anywhere." GiftCoves isn't limited to the shops we work with: add it from
   your favourite website, scan it in a shop, search it. A picture of many sources flowing into one
   Cove, with generic labels ("Big online shops · Independent makers · The shop round the corner"),
   not other companies' names.
4. **Coves.** "See what other people are collecting": themed cards (gamers, new home, creatives,
   Christmas, travel, new baby) → [Explore all Coves]. Where the catalogue becomes discovery.
5. **Daily.** Only now: "Something interesting every day", today's Cove, and the newsletter.
6. **Trust, short.** "Open. Useful. Transparent." Products come from retailers, brands, independent
   sellers or the community. Some links earn GiftCoves a commission; that doesn't change what you
   pay. [How GiftCoves works →]

**Final call:** "Start your first Cove." [Create a Cove]

**Navigation:** **Discover | Coves | Gifts | How it works**, and on the right the visitor's own
**My Coves**. Daily Cove, Surprise, Shop Smarter, Ask others, Secret Friend, group lists and
occasions stop being equal top-level ideas and live inside those.

**Where we stand.** Today's homepage ([homepage.md](features/homepage.md)) has a hero ("Give
better. Get what you actually want."), a search card, the list wizard, today's Cove, the newsletter,
recently viewed and "More Coves". The header has Make a list, Find a gift (eight items) and Help.
Everything the new page links to exists: create a list, `/coves`, the Gift Whisperer, today's Cove,
the how-it-works page. Community Coves appear once public lists exist; until then that section shows
editorial Coves.

---

## Roadmap

In order, each step small enough to ship and prove on its own. Each has an implementation plan
in `.claude/plans/roadmap-N-*.md`, ending with the questions the owner decides before it is built.
Order agreed with the owner on 2026-09-26.

1. **Anything goes in.** Built 2026-09-26 ([pasted-links.md](features/pasted-links.md)); pasted
   pages becoming catalogue offers waits for step 5.
2. **Front page and navigation.** Section 6: the new homepage and the four-item header.
3. **Product pages.** Section 4: price range, saved by, found in, related.
4. **Intent search.** Section 5 and engine E, F and G: the parser, the interpretation, intent on
   gift pages and Coves, the intent a list passes on to its products, and a person's own list as
   the brief for a gift for them.
5. **Matching.** Beyond exact keys: barcode, then brand plus model number, then similar titles
   confirmed by a person; admin merge and split.
6. **Interoperable Coves.** Save this Cove, Follow Cove in the app, Publish Cove (public lists with
   titles that follow the naming rule above).
7. **People find each other.** Handles and `/u/{handle}` profiles, following switched on,
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

- **Moderation of public Coves.** Who sees them before search engines do, and what can be reported.
- **A visitor's product meeting a feed's product.** Can the two merge into one, and who confirms it?
- **"What is this?" from a photo.** Which model, what daily cap, and what the visitor sees when it
  is not sure.
