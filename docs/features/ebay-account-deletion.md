# eBay Marketplace Account Deletion — the webhook that turns eBay on

`App\Http\Controllers\Ebay\AccountDeletionController`, at
`/webhooks/ebay/account-deletion`. Unprefixed, unauthenticated, CSRF-exempt.

## Why this exists, and why it is not optional

eBay requires every production application to expose an endpoint it can notify when one of **its**
users asks for their personal data to be erased. An application that has not configured one is marked
**non compliant** in the developer portal.

That word is doing more work than it looks like. A non-compliant keyset **does not mint production
tokens** — which is exactly the failure the connector was stuck on:

```
php artisan bc:check-ebay --market=nl-nl
→ HTTP 401  {"error":"invalid_client","error_description":"client authentication failed"}
```

Structurally perfect production credentials, refused. So this endpoint is not a compliance chore
bolted onto a working integration — it is the thing that makes the integration work at all. See
[ebay-connector.md](ebay-connector.md) for what it unblocks.

## Two jobs, one URL

**GET — the challenge.** eBay calls with `?challenge_code=…` and expects:

```json
{"challengeResponse": "<sha256 of challengeCode + verificationToken + endpoint>"}
```

Re-run every time the endpoint is saved in the portal, so it has to keep working, not just work once.

**POST — the notification.** eBay wants a 2xx, promptly. It does not want a considered reply: the
acknowledgement *is* the contract, and anything slow or clever turns into a retry storm and a
compliance failure.

## The three inputs to the hash, in that order

`sha256(challengeCode . verificationToken . endpoint)`. Order is part of the specification. Any other
order produces a perfectly valid-looking hex string that eBay rejects, and the portal's failure
message names none of the three inputs — which is why `EbayAccountDeletionTest` recomputes the hash
from the recipe rather than from the controller, so the test disagrees with the code if the code
drifts.

**The endpoint URL is an input to the hash, not merely where the request arrived.** This is the part
that goes wrong in practice: `https://giftcoves.com/…` and `https://www.giftcoves.com/…` hash
differently for an identical request. Production serves `giftcoves.com`, `www.giftcoves.com` and
`brandcoves.com` and does not yet redirect between them (see CLAUDE.md on `canonical_host`), so a URL
the app generates for itself is a guess — hence `EBAY_DELETION_ENDPOINT` is explicit config, with a
request-derived fallback so local and staging work without ceremony. A trailing slash is trimmed:
nobody should be able to break the hash with a keystroke that means nothing.

## The verification token

A secret **we** invent, paste into eBay's portal, and echo back hashed. eBay imposes the shape:
**32–80 characters, `[A-Za-z0-9_-]` only.**

That rule is enforced on our side too (`TOKEN_PATTERN`), because the failure is otherwise silent and
remote: a 31-character token hashes perfectly well locally, so the challenge passes every test here
and fails in the portal with a message that says nothing about length.

An unconfigured or malformed token returns **503, not a hash**. Answering *incorrectly* is worse than
failing — a wrong hash records a validation failure against an endpoint that is otherwise fine, and
the fix then looks like a code problem rather than a missing environment variable.

Generate one with:

```bash
php -r "echo substr(str_replace(['+','/','='],['-','_',''], base64_encode(random_bytes(36))), 0, 48).PHP_EOL;"
```

## What we actually erase: nothing — and that is a finding, not a shrug

This site stores eBay **listings**, and holds no eBay user identifier anywhere:

- `products.external_id` is an eBay *item* id.
- `merchants` has one row for eBay itself.
- `wishlist_items` reference our own groups or a source item id.
- Nobody signs in here with an eBay account.

So a deletion notification names somebody this database has never seen, and there is nothing to
delete. The endpoint says so by acknowledging without acting.

`AccountDeletionController::erase()` is kept as a real method with an empty body rather than omitted,
so the claim is written down where it would have to change. If an eBay sign-in, an order import or a
seller-side integration ever lands, that is the callback that has to start doing something, and
`bc:prune-personal-data` is the shape to follow.

## The personal data in the payload is not logged

The notification carries `username`, `userId` and `eiasToken` — the personal data of somebody who has
just asked to be forgotten. `laravel.log` is retained, shipped and searchable, so writing those into
it would create a durable record of a person **in direct response to their erasure request**, which
is the one outcome the notification exists to prevent.

Only `notificationId` and `topic` are logged. eBay's own reference identifies nobody and is enough to
prove receipt if they ever ask. There is a test asserting the username never reaches a log line.

## Deliberately undefended

Three protections are absent on purpose, and each would be a mistake here:

| Not done | Why |
|---|---|
| **Rate limiting** | eBay retries an endpoint that does not answer 2xx and counts the failures against compliance. Throttling its retries is a way to fail the requirement while looking defensive. |
| **Payload validation** | There is nothing to validate *for* — the handler takes no action on the contents. A 4xx would be recorded as a delivery failure, so a schema version bump that moved a field must not be able to mark us non compliant. |
| **CSRF** | A server-to-server POST has no session and no token. Without the exemption this answers 419 and the application goes non compliant. Exempt safely: no state changes and no personal data is written. |

The exemption lives in `bootstrap/app.php`, far from the route, so there is a test asserting it —
nothing else would notice its removal.

## Signature verification is deferred, and the reason is circular

eBay signs each notification with an `x-ebay-signature` header, verifiable against a public key
fetched from its Notification API. That fetch **needs an application access token** — the very thing
a non-compliant keyset will not issue. So the verification cannot be built or tested until this
endpoint has already done its job.

It is also worth little here: the handler takes no action, so a forged notification achieves nothing
beyond a log line carrying no personal data. Recorded in [TODO.md](../TODO.md) as a follow-up once
tokens mint, at which point it can be written against a real signed payload rather than a guess —
the same rule the connectors follow.

## Setting it up

**Order matters: deploy before registering.** eBay validates the endpoint the moment you save it in
the portal, so the route has to be live first, or the challenge hits a 404 and the application stays
non compliant.

1. **Deploy** this code to the host you intend to register.
2. Set on that app, in Coolify:
   ```
   EBAY_DELETION_VERIFICATION_TOKEN=<32-80 chars of [A-Za-z0-9_-]>
   EBAY_DELETION_ENDPOINT=https://giftcoves.com/webhooks/ebay/account-deletion
   ```
   Runtime variables, not build variables — only `VITE_*` needs that tick.
3. **Self-test before touching the portal**, so a failure there means eBay and not us:
   ```bash
   curl "https://giftcoves.com/webhooks/ebay/account-deletion?challenge_code=selftest"
   # → {"challengeResponse":"…"}

   php -r "echo hash('sha256','selftest'.'<token>'.'https://giftcoves.com/webhooks/ebay/account-deletion');"
   # → the same string
   ```
   If those differ, `EBAY_DELETION_ENDPOINT` does not match the URL you called.
4. In eBay's developer portal → Alerts and Notifications → Marketplace Account Deletion, enter the
   **same** endpoint URL and token, and save. eBay calls the GET immediately.
5. The application should stop reading "non compliant". Then:
   ```bash
   php artisan bc:check-ebay --market=nl-nl
   ```

## Tests

`tests/Feature/EbayAccountDeletionTest.php` — 11 of them. The challenge hash and its exact inputs are
pinned rather than merely exercised, because a bug here does not degrade a feature: it switches the
entire eBay integration off.
