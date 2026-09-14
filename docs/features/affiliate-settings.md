# Shop and affiliate settings

Admin › Operations › **Shop and affiliate keys** (`/admin/affiliate-settings`). Added 2026-09-14 at
the owner's request.

## What it controls

| Field | What it goes into | Shown |
|---|---|---|
| bol partner site id, Belgium | every bol link from `be-nl` and `be-fr` | in full |
| bol partner site id, Netherlands | every bol link from `nl-nl` | in full |
| bol API client id | the login `BolConnector` uses to ask bol for products | fingerprint only |
| bol API client secret | the same login | fingerprint only |
| Amazon Associates tag, amazon.nl | the "search on Amazon" link on `nl-nl` | in full |
| Amazon Associates tag, amazon.com.be | the same link on `be-nl` and `be-fr` | in full |

All six were environment variables in Coolify, so changing one was a redeploy. **They still are,
and those values stay the defaults.** A value typed here overrides Coolify's; each field says which
one is in effect, and emptying a field hands it back to Coolify. That is also the undo for a mistake.

The mechanism is the one the AI and reminder settings use: `AffiliateSettingsStore` writes the stored
values over the config at boot (`AppServiceProvider`), so every existing reader — `Market::bolPartnerSiteId()`,
`AmazonSearchLink::for()`, `BolConnector` — keeps reading the config it always read, and there is one
way to ask each question. Values are stored in `connector_settings` under source `affiliate`,
encrypted like every row there, behind an allowlist of config paths so a stray row cannot overwrite
anything else.

## Why the ids are shown and the credentials are not

A partner id or an Associates tag is not a secret: it appears in every outbound link. It is also the
quietest failure on the site. A wrong one breaks nothing anybody can see — the visitor reaches the
shop, the sale happens — and the commission goes to nobody. So the ids are shown in full and checked
on save: digits only for a bol partner id, letters, digits and hyphens for an Associates tag.

The bol client id and secret are credentials. The fields are empty on every load and never carry the
stored value, so it cannot end up in an HTML response, a browser's form cache or a screenshot; what
is shown is the last four characters and the length. An empty submit means "keep it", as on the AI
settings page, because otherwise correcting a partner id would blank the secret. Taking a stored
credential away again is a separate switch, since an empty field cannot mean both things.

## A change takes effect at once

- **bol's cached login is cleared** when its client id or secret changes
  (`BolConnector::forgetAccessToken()`). Otherwise the old token stays valid for up to four minutes
  and a wrong new secret only shows itself once it expires.
- **The queue workers are told to restart** (`queue:restart`) whenever anything changed. Horizon's
  workers boot once, so without it a job would keep calling bol with the old values until the next
  deploy. Each worker finishes its current job first.
- **The page reloads itself** after saving, because the request that saved booted before the save
  and would show a value that has just fallen back to Coolify.

A save that changed nothing clears nothing and restarts nothing.

## What is deliberately not here

**No Amazon API keys.** `AMAZON_ACCESS_KEY` and `AMAZON_SECRET_KEY` exist in the config, but no Amazon
API client exists to read them — only status screens check whether they are set. A field for them
would save a value that changes nothing, which is worse than no field. They belong on this page the
day an Amazon client is written. The same goes for `AMAZON_PARTNER_TAGS`, which nothing reads.

**No on/off switches.** The page changes ids and credentials, not whether a shop is used at all.

## Tests

`tests/Feature/AffiliateSettingsTest.php`: a saved partner id is the one the bol link earns on, the
Belgian tag serves both Belgian markets, emptying a field goes back to Coolify, only allowlisted keys
reach the config, a stored secret is never rendered, the ids are, a save that does not touch a secret
keeps it, a new bol secret clears the cached login and restarts the workers, a stored secret can be
handed back to Coolify, an untouched save restarts nothing, a malformed id is refused, and secrets
are encrypted at rest.
