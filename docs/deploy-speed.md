# Why some production deploys took minutes

Looked into on 2026-11-12, when the owner asked why Le Tendenze deploys in seconds and GiftCoves
does not. (Le Tendenze pulls files onto shared hosting; GiftCoves rebuilds a Docker image and
replaces five containers, so it will never be as quick. But it was slower than it had to be.)

## What the logs said

Four deployments of that week, read from Coolify's API:

| Deployment | Log span | Build steps cached | Longest step |
|---|---|---|---|
| 12 Nov 19:15 | 417 s | 7 of 53 | `install-php-extensions`, 259 s |
| 12 Nov 19:53 | 111 s | 21 of 53 | `npm run build`, 22 s |
| 12 Nov 21:19 | 84 s | 25 of 53 | `composer dump-autoload`, 9 s |

The slow one was not slow because of its commit. Between the evening of the 11th and that deploy,
both `dunglas/frankenphp:php8.4-alpine` and `node:24-alpine` had been republished upstream (the
digests in the logs differ). The Dockerfile named them by tag only, so the build took the new
images, every layer above them was invalid, and the PHP extensions were compiled from source again.
Exporting the four images took 37 s each instead of 5 s for the same reason: all layers were new.

## What changed

The three base images are pinned by digest in the `Dockerfile`. A deploy now rebuilds only what the
commit touched: about a minute and a half when the frontend changed, less when it did not.

## What it costs, and the chore that comes with it

Base-image updates, security fixes included, no longer arrive on their own. About once a month, or
when an advisory calls for it, bump the three digests (`docker buildx imagetools inspect
<image:tag>` prints the current one) and expect that deploy to take four to seven minutes.

## Not the cause

- Coolify's nightly Docker cleanup: it only prunes above 80% disk use, and the fast deploys ran
  after it too.
- The failed deploy of 11 Nov (exit 255 after 36 s) was Coolify losing its connection to the build
  container, unrelated to caching; the retry went through.
