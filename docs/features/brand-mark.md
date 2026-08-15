---
name: The brand mark
area: Brand / Frontend
status: Active
date_added: 2026-08-09
---

# The brand mark

A cove: a headland wrapping a sheltered bay, with a buoy in its mouth. Deep teal `#12232B`, sand
`#EFE6D6`, amber `#F2A93B`.

Source artwork lives in [bc_logo/files](../../bc_logo/files). What the site serves is copied into
`public/icons/`, with the `.ico` at `public/favicon.ico` because that is the path every browser
requests by name whether or not a page declares it. Before this, that file was zero bytes: every tab
showed a blank page icon and every shared link rendered as a grey rectangle.

**One mark, every environment.** The artwork set includes a staging variant (amber tile, folded
corner) and it is deliberately not wired up — staging should look like the site, because that is what
it is there to show.

## Where it appears

| Surface | What it uses |
|---|---|
| Browser tab | `giftcoves.svg` first, `favicon.ico` as the fallback ([app.blade.php](../../resources/views/app.blade.php)) |
| Home screen | `apple-touch-icon`, the 512 PNG |
| Site header | The SVG next to the wordmark ([SiteLayout.tsx](../../resources/js/Layouts/SiteLayout.tsx)) |
| Admin | Filament `brandLogo` and `favicon` |
| Social cards | A drawn 1200×630 card, see [social-cards.md](social-cards.md) |

Two small decisions inside those:

- **The header mark is `aria-hidden`.** The word "GiftCoves" sits right next to it inside the same
  link, and a screen reader announcing the name twice is worse than not announcing the image at all.
  Width and height are set as attributes so the header does not reflow while it loads.
- **The mark is the seed of the social card**, not the card itself: [social-cards.md](social-cards.md)
  renders the cove and the palette into a 1200×630 image per page.

## Social cards

Superseded by [social-cards.md](social-cards.md): pages no longer fall back to the square mark, they
render a real 1200×630 card with their own title on it.
