---
name: Posting to Instagram
area: Content / Social / Operations
status: Active (off until configured)
date_added: 2026-11-10
---

# Posting to Instagram

**`bc:post-instagram` posts the day's Daily Cove and each newly published guide, once, to GiftCoves'
Instagram account.** Owner, 2026-11-10: "a routine to post to instagram", choosing the GiftCoves
account, "new guides + daily Cove", run inside the app, with an existing token.

## What runs when

| Schedule (UTC) | Command | Posts |
|---|---|---|
| 09:20 | `bc:post-instagram --only=daily` | today's Daily Cove, twenty minutes after its 09:00 drop |
| 17:20 | `bc:post-instagram --only=guides` | the oldest guide published in the last 7 days that is not posted yet |

So the feed gets at most two posts a day, and a busy publishing day (three guides at once) becomes
three days of posts rather than a burst. "Guide" means every article kind (`guide`, `seasonal`,
`advice`); `giftcoves.instagram.guide_window_days` keeps switching the feature on from emptying the
archive into the feed.

## What a post is

- **The picture** is the Cove's own social card as a JPEG: `/{market}/og/daily/{date}.jpg` or
  `/{market}/og/guide/{slug}.jpg`. Instagram's publishing API fetches the picture itself and takes
  JPEG only, so these two routes convert the cached PNG card (flattened on white, quality 90) instead
  of drawing a second design. 1200x630 is inside Instagram's allowed ratio (4:5 to 1.91:1). The JPEG
  has its own ETag.
- **The caption** is the Cove's title and blurb, link tokens flattened by `CoveMarkup::plain()`, then
  `site.instagram.more_guide` / `more_daily` (the page address written out, since captions do not
  link) and `site.instagram.hashtags`, in the market's language. No model call: the words were
  written and approved for the page already.

## Once each

`instagram_posts` holds one row per Cove (unique on `daily_pick_set_id`). `posted` is final. A refusal
is a `failed` row with Instagram's own message in `error`, retried on later runs until
`max_attempts` (3), then left alone for a person to read. A failed post makes the command exit
non-zero, so the scheduler reports it.

## Configuration

| Variable | |
|---|---|
| `INSTAGRAM_ENABLED` | `true` to post. Off is a no-op that exits successfully. |
| `INSTAGRAM_ACCESS_TOKEN` | Graph API token with `instagram_content_publish` (a long-lived user token or, better, a system user token that does not expire). Sent as a bearer header, never in a URL. |
| `INSTAGRAM_USER_ID` | The Instagram business account id (a number like `1784...`), not the @name. |
| `INSTAGRAM_MARKET` | The edition posted, default `be-nl`. One account, one language. |
| `INSTAGRAM_GRAPH_HOST` | `graph.facebook.com` (account linked to a Facebook Page) or `graph.instagram.com` (Instagram Login tokens). |

All five are in `.env.example` and the Coolify compose file; `bc:check-config` reports them under
"Instagram". Try it with `php artisan bc:post-instagram --dry-run`, which prints the picture address and
caption of what would be posted and sends nothing.

## Not done

- One account and one market. A second account (another language) would need the config to become a
  list and `instagram_posts` to key on account as well as Cove.
- Feed pictures only: no carousel, no Reels, no Stories.
- Long-lived user tokens expire after 60 days; a system user token does not. An expired token shows up
  as `failed` rows with Instagram's message.
