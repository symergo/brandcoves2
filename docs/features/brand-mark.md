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
| Browser tab | `brandcoves.svg` first, `favicon.ico` as the fallback ([app.blade.php](../../resources/views/app.blade.php)) |
| Home screen | `apple-touch-icon`, the 512 PNG |
| Site header | The SVG next to the wordmark ([SiteLayout.tsx](../../resources/js/Layouts/SiteLayout.tsx)) |
| Admin | Filament `brandLogo` and `favicon` |
| Social cards | The 512 PNG as the `og:image` fallback |

Two small decisions inside those:

- **The header mark is `aria-hidden`.** The word "Brandcoves" sits right next to it inside the same
  link, and a screen reader announcing the name twice is worse than not announcing the image at all.
  Width and height are set as attributes so the header does not reflow while it loads.
- **The `og:image` fallback is new behaviour, not just a new file.** A shared link with no image
  renders as a bare grey rectangle in every chat app, which reads as a broken page. The square mark
  pairs with the small `summary` card; a page that sets its own image still wins and gets the wide
  one.

## Not done

No wide (1200×630) social image exists, so pages without their own image get the small card. That is
correct rather than broken, but a proper OG template would earn more clicks.
