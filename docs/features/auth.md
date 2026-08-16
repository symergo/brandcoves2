---
name: Authentication
area: Core / Accounts
status: Active — Google needs credentials per environment
date_added: 2026-08-16
---

# Authentication

Passwordless. Two ways in, one account at the end of both.

## Why no passwords

This site holds gift lists and email addresses, not payment details. A password would be a liability
someone reuses from another site, a reset flow to build, and a hash to protect — for no security this
product needs. `2026_08_07_001000_make_users_passwordless` dropped the column.

Two paths, offered together rather than one instead of the other:

| Path | Route | Why it exists |
|---|---|---|
| Magic link | `/{market}/login` → `/{market}/auth/magic/{token}` | Works for everyone, needs no third party |
| Google | `/{market}/auth/google` → `/auth/google/callback` | An email round-trip is slow; for someone already signed into Google this is one tap |

Both land on the same account, **matched on the email address case-insensitively**
(`whereRaw('lower(email) = ?')`). Without that fold, signing in with Google and later with a magic
link would produce two accounts with half a gift list each — and the person would have no way to tell
which one held the list they were looking for.

## Registration is not a separate flow

There is no sign-up form. First sign-in creates the account, on either path. Google's `email` is
taken as already verified — `email_verified_at` is stamped on creation — because Google verified it,
and asking someone to confirm an address we were just handed by their identity provider is a
round-trip that proves nothing.

`avatar_url` and `name` are refreshed on every Google sign-in, so a changed profile picture follows.

## The anonymous merge

The site is useful before you sign up: `TrackAnonymousIdentity` issues a `bc_visitor` cookie and
lists attach to that. On sign-in, `IdentityMerger` moves that work onto the real account.

**The merge runs before `Auth::login()`, deliberately** — see
[GoogleController.php:76](../../app/Http/Controllers/Auth/GoogleController.php#L76). Once the session
regenerates, the anonymous cookie is no longer the thing identifying this browser, and the window to
resolve it has closed.

## Google: the redirect URI is unprefixed, and has to be

Every other public route lives under `/{market}/`. The OAuth callback does not, and this is the one
place that rule is broken on purpose.

**Google matches a registered redirect URI by exact string.** A market-scoped callback would mean
registering `/be-nl/auth/google/callback`, `/be-fr/...`, `/en/...`, `/es/...` and `/nl-nl/...` — five
per environment, times local, staging and production, kept in sync by hand every time a market is
added. Nothing would fail loudly when someone forgot; one market would just stop being able to sign
in.

So one URI is registered, `${APP_URL}/auth/google/callback`, and the market crosses the round-trip in
the **session** instead: `redirect()` stashes `auth.market` before the visitor leaves, and
`callback()` reads it to decide where they land. The outbound leg stays market-scoped at
`/{market}/auth/google` — that is a link we generate, not a URI Google has to recognise, and it needs
the market in hand to stash it.

> **This was broken until 2026-08-16 and could not have been noticed.** The only callback route was
> inside the `{market}` prefix group, while `.env.example` and `docker-compose.coolify.yml` both
> pointed `GOOGLE_REDIRECT_URI` at the unprefixed path — which matched no route at all. Sign-in was
> not subtly wrong; every visitor would have come back from Google to a 404. It stayed invisible
> because no environment had ever had credentials set, so the button was hidden everywhere and the
> path was never walked.

### The session can expire mid-round-trip

With no `{market}` segment on the callback, the session is the *only* source of the market. A slow
visitor — one who leaves the consent screen open — comes back to a session that has rolled over.

An unguarded null there built `"//login"`, which a browser reads as **protocol-relative** and
resolves against a host called `login`, sending the visitor off-site. The fallback is
`MarketPreference::resolve($request)`, the same one `bootstrap/app.php` uses for the guest and auth
redirects, so they land on a real market — their remembered one, if the `bc_market` cookie is still
there.

## Unconfigured means invisible, not broken

With no `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`:

- `googleEnabled` is false, so `Login.tsx` renders no button.
- Both routes `abort(404)`.

A "Continue with Google" button that leads to a Socialite exception is worse than no button, and
staging may legitimately run without OAuth credentials. The 404 matters as much as the hidden
button — otherwise the route stays reachable by anyone who guesses the URL and returns a stack trace
instead of a page.

`bc:check-config` reports both under **Sign-in**, marked optional, printing lengths and never values.

## Activating Google in an environment

1. Google Cloud Console → OAuth consent screen. Scopes `email` and `profile` only — nothing
   sensitive, so no verification review. While the screen is in *Testing*, only listed test accounts
   can sign in.
2. Create an OAuth 2.0 **Web application** client. Authorised redirect URIs:
   - `http://localhost:8000/auth/google/callback`
   - `https://staging.giftcoves.com/auth/google/callback`
   - `https://brandcoves.com/auth/google/callback`
   - `https://giftcoves.com/auth/google/callback` — **added before the rename, not during it.**
     `GOOGLE_REDIRECT_URI` derives from `APP_URL`, so a deploy that moves the domain without this
     registered returns `redirect_uri_mismatch` for every visitor. See [rebrand.md](rebrand.md).
3. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` and `GOOGLE_REDIRECT_URI` — `.env` locally, Coolify
   for the deployed apps. **Runtime variables, not Build Variables**: these are read server-side, and
   the Build Variable tick is only for `VITE_*`.
4. `php artisan bc:check-config` to confirm they arrived.

## Rate limiting

The magic link is throttled twice — `throttle:10,1` on the route as a blunt outer guard, and per
address *and* per IP inside `MagicLinkController`. The inner limit protects the mailbox of whoever's
address is being typed, which the IP limit alone does not: the attack is entering someone else's
address repeatedly, and the victim is the person whose inbox fills.

Token consumption is throttled separately at `throttle:20,1` — that one is guessing, not flooding.

## Related

- [market-routing.md](market-routing.md) — why the market is in the path, and `MarketPreference`
- [wishlists.md](wishlists.md) — what the anonymous merge is carrying
- [rebrand.md](rebrand.md) — the callback URI's part in the domain move
- [config-contract.md](config-contract.md) — `bc:check-config`
