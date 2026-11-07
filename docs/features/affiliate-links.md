# Affiliate links for typed shop links

**Rule (owner, 2026-11-07):** "bol links should be affiliate links - that's a rule for all merchants
registered on giftcoves". Products from the feeds already carry a tracked link. This covers an address
somebody typed: a `[[shop:…]]` link in an article, and the shop link of an offline item on a list.

## Where

`App\Services\Affiliate\MerchantLinks::wrap($url, $market)` is the one place. It returns the tracked
address for a registered shop, and the address unchanged for anything else.

| Shop | How it is tracked |
|---|---|
| bol | `partner.bol.com/click/click`, site id by the country in the link's path (`/be/` or `/nl/`), the form `BolConnector` builds for the feed. By the link, not the page: a Belgian article may link bol.com/nl, and that sale belongs to the NL site. |
| Awin advertisers (`merchants` with `source = awin`: Coolblue BE and NL, Dreamland, Fnac, Krefel, Vanden Borre) | `awin1.com/cread.php?awinmid=…&awinaffid=…&ued=…`. Advertiser id from `merchants.external_id`; publisher id from the Awin account of the feed with the same name (`feeds.label` = `merchants.name`), so Vanden Borre uses its own account. A shop is matched on its domain without a leading `www`/language label, so `nl.fnac.be` covers `fr.fnac.be`. Cached for an hour. |
| Amazon | Its Associates tag in the address, added where the link is written (`CoveMarkup::shop()`), as before. |
| eBay | **Not wrapped.** eBay's tracked form needs a rotation id per marketplace that this repo has never verified; a wrong one silently earns nothing. Add it here once confirmed. |

A registered shop whose ids are missing in an environment comes back unchanged, and an account other
than `default` never borrows the default publisher id: a sale credited to the wrong publisher is worse
than an untracked one.

## Why at the link, not at the save

An article picture's buy address (`[[image:…|name|URL]]`) is what a list saves as the offline item's
link, and the edit form shows it back. That must stay the shop's address: a tracker's address would be
unreadable, would defeat the page reader (`ReadItemLink`) and would show `partner.bol.com` as the shop.
So the address is stored plain and tracked only where a reader follows it:

- articles: `CoveMarkup` renders a shop link through `wrap()`; a tracked link gets
  `rel="sponsored nofollow noopener"` (Google's label for paid links), as Amazon's already did;
- lists: `WishlistItem::trackedExternalUrl()` is sent as `buyUrl` beside `externalUrl`;
  `ListItemCard` links to `buyUrl` and marks it `sponsored` when it differs.

`CoveProse::VERSION` went to 3 with this change, so stored article renders are redone with the tracked
links rather than keeping the plain ones until their next build.
