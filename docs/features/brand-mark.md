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
`public/icons/`, with the production `.ico` at `public/favicon.ico` because that is the path every
browser requests by name whether or not a page declares it.

## Two marks, and why

Staging wears a different one: the same cove, inverted onto an amber tile with a folded top-right
corner. It reads as "this build is a proof, not final".

That is not decoration. Staging and production are the same site at two addresses, and the tab strip
is where they are told apart — an editor with both admins open has otherwise nothing to distinguish
them, and a screenshot of staging gets reported as a bug in production. The favicon is the cheapest
possible fix and it is always in view.

**Keyed on `ROBOTS_ALLOW`, not `APP_ENV`.** Staging runs with `APP_ENV=production`, deliberately: it
is a production build, which is the whole point of it. `ROBOTS_ALLOW` is the one flag that is true on
exactly one environment, and "must not be indexed" and "is not the real site" are the same fact
wearing two hats. Set `ROBOTS_ALLOW=true` and the production mark follows automatically.

## Where it appears

| Surface | What it uses |
|---|---|
| Browser tab | SVG first, `.ico` fallback, both environment-aware ([app.blade.php](../../resources/views/app.blade.php)) |
| Home screen | `apple-touch-icon`, 180px on staging and the 512 on production |
| Site header | The SVG next to the wordmark ([SiteLayout.tsx](../../resources/js/Layouts/SiteLayout.tsx)) |
| Admin | Filament `brandLogo` and `favicon`, plus " staging" appended to the brand name |
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
