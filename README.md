# GiftCoves 2

Multi-market product search with **offer comparison**, a **Gift Whisperer**, **Daily Picks** and
**buying guides** generated from real search demand.

Markets: `be-nl`, `be-fr`, `en`, `es`, `nl-nl`.

## Getting started

Requires PHP 8.4, Composer, Node 24 and Docker Desktop on the host.

```bash
docker compose up -d                 # postgres :5432, redis :6379, mailpit :8025
composer install && npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer dev                         # http://localhost:8000
```

`composer dev` runs the web server, a queue worker, log tailing and Vite together.

| URL | What |
|---|---|
| http://localhost:8000 | site (redirects to your best-guess market) |
| http://localhost:8000/health | commit, last migration, DB + Redis checks |
| http://localhost:8000/admin | Filament admin (needs `users.is_admin`) |
| http://localhost:8025 | Mailpit — every outbound email in dev |

Grant yourself admin:

```bash
php artisan bc:make-admin you@example.com
```

`is_admin` is deliberately not mass-assignable, so `update(['is_admin' => true])`
silently does nothing — no request payload can ever grant admin access, and there
is no self-service path to the panel.

## Architecture in one paragraph

`products` rows are **offers** — one merchant, one product, one market. `product_groups` rows are
**physical products**, identified by validated GTIN-13 or by brand + normalised title, and scoped to
a market. Everything a visitor sees operates on groups; comparing the offers beneath one is the
product. Ingestion, grouping, scoring, daily picks and guide building all run as chunked, resumable
queued jobs — never in a web request, and neither does anything that costs AI tokens.

See [CLAUDE.md](CLAUDE.md) for the invariants and [docs/features/](docs/features/) for per-feature
documentation. [docs/TODO.md](docs/TODO.md) lists what is merged but not yet proven — currently the
eBay and Tradedoubler connectors, both waiting on credentials.

## Deployment

Coolify, from GitHub. `staging` branch → `staging.giftcoves.com`, `main` → production.
See [docs/deployment.md](docs/deployment.md).
