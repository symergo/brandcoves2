---
name: Recently viewed
area: Discovery / Frontend
status: Active — new 2026-09-06
date_added: 2026-09-06
---

# Recently viewed

A band of the products this visitor opened, most recent first, on the home page and under a
product page. "What others searched" was already on the front page; "what *you* looked at" is the
version that brings a returning visitor back to the thing they were deciding about.

## Where it lives

In the browser, in `localStorage`, and nowhere else. `resources/js/recentlyViewed.ts` records a
product when its page mounts (`Product.tsx`) and `Components/RecentlyViewed.tsx` reads the list
after mount and renders the six most recent. Nothing reaches the server: it is a convenience for
the person holding the phone, not a fact about them the site needs, and it works signed out.

**Per market.** A product id is market-scoped (invariant 2), so the key is `bc_recent:{market}` and
a Belgian card never appears on the Dutch home page, where its link would 404.

**Read after mount, never during render.** The SSR container has no storage, and a band that
rendered on the server and not in the browser is a hydration mismatch. The first paint has no
band; it appears a frame later, below everything the server sent, so nothing above it moves.

**Empty means invisible.** No heading over an empty row: that would announce a feature to somebody
who has not used it yet. The product page excludes the product being read.

Twelve are kept, six are shown. Every read and write is wrapped, and an empty store is the
ordinary answer — a private window, cleared site data, a browser set to block storage.

## Not done

- A "clear" control. The list is small, per device and expires with the browser's storage.
- Syncing across devices through the account. That would make it a server-side fact about a
  person, with retention to think about; the list store (`savedItems.ts`) is the shape it would
  take if it is ever wanted.
