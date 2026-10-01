---
name: Swipe gifts
area: Gifting / Lists
status: Active
date_added: 2026-09-28
---

# Swipe gifts

**One product at a time: swipe right and it goes on the list, swipe left and it is gone.** It keeps
going for as long as the visitor does. The owner asked for a "Cadeau tinder" as a way to build a
list (2026-09-28): "don't limit it, but add a way to navigate away to stop". It is at
`/{market}/gift/swipe`, and Find a gift offers it second, full width under the search card ("put
the swipe option under the search card"), because like the search it puts things straight on a
list.

It is called "Swipe door cadeaus" / "Swipe through gifts" on the site, not "Tinder": that is
somebody else's brand name.

## A popup, not a page (2026-09-29)

The first version was an ordinary page, and on a phone the site's header and footer left a small
card in a page that scrolled. The owner asked for "bigger pictures, no scrolling... maybe a popup?".
So it opens as a dialog over the page (`Components/PlayDialog.tsx`, shared with This or that since the
next day): the full screen on a phone (clear of the notch and the home
bar), a tall panel over a dimmed page from `sm` up.

- One slim top bar: the title, who it is for and the count once there is one, and a close button,
  which is Stop (so is Escape).
- The card takes every pixel the bar and the buttons leave; the picture takes most of the card and
  grows a small shop picture to fill it. It is the image proxy's copy at up to 960 wide
  (`imageToken`), the shop's own picture when the proxy may not serve it.
- Two round buttons with their words under them, within a thumb's reach: the swipe's equals, never
  replaced by it. The page behind does not scroll while it is open.

## No end, and a way out

- **No round count.** This or that stops after twelve rounds (`TasteDeck::ROUNDS`); this does not.
  The page asks for another batch of eight (`SwipeDeck::BATCH`) whenever three cards are left, and
  only an empty batch ends it ("that is all we have for now").
- **Stop is always on screen**, above the card. It goes to the list the right swipes went to, or
  back to Find a gift when nothing was chosen. A visitor who is not signed in and chose something
  stays on the page instead, to see what they chose with a Save button each, because that is where
  signing in is asked.
- The request stays bounded while the session does not: the page sends at most the last 200 yeses,
  200 noes and 400 shown ids, and the server caps the same.

## Where a right swipe goes

A signed-in visitor's right swipe saves at once, through the same `POST /list-items` as every Save
button, so the toast, its Undo and the saved state on other cards are the site's own
(`saveToast`, `savedItems`). Which list:

- **A saved person**: their list, made on the first right swipe (`POST /people/{id}/list`), never
  on opening the page. What they were already given is never offered (`GiftHistory`).
- **A relationship** ("Mama"): the list for a person saved under that name, the same
  `POST /people/for-relationship/list` as Find a gift's search card.
- **For yourself or nobody in particular**: the list a Save button would pick (the one being
  filled, else the server's default list).
- **A list chosen up front** (`?list=<id>`, 2026-10-01): "Add a product" on Mijn Coves offers
  swiping as another way to fill that list (owner). Right swipes go into it and Stop returns to it;
  a list about somebody adds `&person=` so the cards start from what is known about them. Only a
  list the visitor may add to (`ListAccess::canEdit`); any other id is ignored.

The swipes themselves are only in the page's state, as in This or that: a half-finished session is
not worth a row. The one exception is swiping for yourself while signed in: then the swipes also go
to "Mijn smaak", which keeps the taste they show ([my-taste.md](my-taste.md)).

## What a swipe teaches (`SwipeDeck`)

- A right swipe scores +1 for each of the card's interests (at the card's own trust per tag,
  `TasteCard::values()`); a left swipe scores -0.5. **A no counts half** because "not this product"
  is weaker than "yes, this": a no to one ugly mug is not a no to coffee.
- **Two noes and no yes (-1.0) leave the interest out** from then on, the same "two bad rounds" as
  This or that's avoid list.
- **Until something is liked every card explores**: each shows the interest seen least so far.
- **Once something is liked, two cards in three follow a favourite** (the top three that reached a
  full like, in turn), near the price of what was liked (half to twice the median). The third card
  keeps exploring, so an early coffee grinder does not mean an hour of coffee.
- **A narrower interest hides its broader one on a card** (`TasteCard::NARROWER`, 2026-09-30):
  a perfume carries `perfume` and `beauty`, and counted twice one right swipe made two favourites,
  so two cards in three were perfume or beauty (owner: "why do I get now all beauty and
  perfumes?"). On a card only `perfume` counts; the product keeps both tags for search and the
  gift engine.
- The draw is This or that's cached random pool (`TasteDeck::draw()`), topped up per favourite with
  24 random products straight from the tag indexes (`gift_tags ??| …`, see SuggestionEngine), since
  a random 160 may hold only two of a given interest.

No AI anywhere (invariant 1).

## This or that lost its single card

Until this feature every fourth round of This or that was one card to like or dislike, which could
be swiped. The owner asked for the swiping out of This or that once it had a way of its own, so every
round is now a pair, and a card left without a partner is not shown. The server still reads a
single-card answer, so a session open during the deploy finishes. The swipeable card moved to
`Components/SwipeCard.tsx`.

## Starting from what is known (2026-09-29)

See [taste-discovery.md](taste-discovery.md#starting-from-what-is-known-2026-09-29): the same
`DeckSeed` starts both games. Here a known interest counts as one like before any swipe.

## Files

- `app/Services/Gift/SwipeDeck.php` — what comes next; `compose()` is the pure half
- `app/Services/Gift/CarriedWho.php` — who it is for, from `?person=`, `?relationship=`, `?for=me`
  (shared with This or that)
- `app/Http/Controllers/SwipeController.php`, routes `gift.swipe`, `gift.swipe.next`
- `resources/js/Pages/Gift/Swipe.tsx`, `resources/js/Components/SwipeCard.tsx`
- the card on `resources/js/Pages/Gift/Wizard.tsx`
- `tests/Unit/SwipeDeckTest.php`, `tests/Feature/SwipeGiftsTest.php`
