# Article pictures on our own server

`POST /api/editorial/images` (owner, 2026-11-23). Code: `App\Services\Images\EditorialImages`,
`App\Http\Controllers\Api\EditorialImageController`. Tests: `tests/Feature/EditorialImagesTest.php`.

## What it does

An authored article places a picture with `[[image:URL|description]]` on a line of its own. Until
this endpoint, the URL was somebody else's: a shop's campaign banner, Wikimedia's copy of a logo.
Those disappear after the campaign, get renamed, or throttle us (Wikimedia answered 429 the day the
Amazon Prime articles went out), and the article then shows nothing where the picture was.

Now the writer sends the picture first and uses the address that comes back:

```bash
# a file
curl -H "Authorization: Bearer $KEY" -F file=@logo.png -F alt="Het logo van Amazon Prime" \
  https://giftcoves.com/api/editorial/images
# or an address
curl -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"url":"https://example.com/banner.png","alt":"…"}' https://giftcoves.com/api/editorial/images
```

The answer (201) carries `url` (`https://giftcoves.com/media/editorial/<sha1>.webp`), `width`,
`height`, and `token`: the `[[image:…|alt]]` line to paste into the body. Needs the `write`
ability, not `publish`: a stored picture reaches nobody until an approved article names it.

## Decisions

- **Re-encoded, never stored as sent.** ImageStore's encoder: JPEG, PNG, WebP or GIF only, at most
  40 megapixels and 10 MB, shrunk to 1600 px on the long side, flattened onto white, written as
  WebP. Whatever came in, what is served is a bitmap we drew, and `MediaController` always answers
  `image/webp` with `nosniff`. **SVG is refused** because an SVG is code that runs in the reader's
  browser. The site's own drawings (`CoveScene`) are code in the repository for the same reason.
- **Named after the content** (sha1 of the WebP), so a retry after a timeout or the same logo sent
  for four markets is one file at one address.
- **Its own folder, `media/editorial/`.** `bc:prune-personal-data` deletes every picture in
  `media/items/` that no list item refers to, which every article picture is. Nothing prunes
  `editorial/`; a picture lives as long as the article that names it. The media route allows
  exactly `items` and `editorial`.
- **Never Amazon** (invariant 6): an Amazon address, or an address that redirects to one, is
  refused rather than copied. Fetches go through `SafeFetch` (public addresses only, size and time
  caps), like the image proxy and the page reader.
- **Transparency is lost** (flattened onto white). Article pictures sit in a white frame, so a logo
  looks the same; a picture meant for a dark background would not.
