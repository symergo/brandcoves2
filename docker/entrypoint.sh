#!/bin/sh
# Runs before every container's own command: app (frankenphp), queue
# (horizon), scheduler (schedule:work) and the one-shot migrate.
#
# Why the caches are built HERE and not in the Dockerfile: one image serves
# staging and production, and `config:cache` freezes the environment it runs
# in. At build time that is the builder's environment, with none of Coolify's
# runtime variables (APP_KEY, the database, SOURCE_COMMIT, which Coolify only
# injects into the running container). At container start it is exactly the
# environment this container will serve with. `route:cache` sits here beside
# it because it is equally cheap and equally per-boot; it would be safe at build,
# but one place for "the caches" is easier to reason about.
#
# What caching buys: without it, every request (and every artisan process the
# scheduler spawns each minute) loads every config file, its own and the
# packages', and registers about 300 routes before doing any work.
#
# A failed cache must not take the site down. Coolify stops the old containers
# before the new ones are healthy, so a container that refuses to start is an
# outage. If either command fails, the stale file is removed and the container
# serves uncached, exactly as it did before this script existed, and says so
# on stderr where `docker logs` shows it.
set -u

cd /app

if ! php artisan config:cache --no-interaction >/dev/null; then
    echo "entrypoint: config:cache failed, serving without a config cache" >&2
    php artisan config:clear --no-interaction >/dev/null 2>&1 || rm -f bootstrap/cache/config.php
fi

if ! php artisan route:cache --no-interaction >/dev/null; then
    echo "entrypoint: route:cache failed, serving without a route cache" >&2
    php artisan route:clear --no-interaction >/dev/null 2>&1 || rm -f bootstrap/cache/routes-v7.php
fi

# Classic or worker mode for FrankenPHP (docker/Caddyfile imports the matching
# pair of snippets from docker/caddy/). Worker mode is Octane: the app boots
# once per worker and stays in memory. Off unless OCTANE_WORKERS says
# true/1/yes/on, and prepared rather than switched on (2026-09-27); see
# docs/features/speed.md, "Worker mode". Only the app container reads it: the
# queue and the scheduler never start Caddy.
#
# Falls back to classic, and says so, when the pieces worker mode needs are not
# in the image. A container that refuses to start is an outage (see above); a
# container that serves the old way is not.
case "$(printf '%s' "${OCTANE_WORKERS:-}" | tr '[:upper:]' '[:lower:]')" in
    1|true|yes|on) GIFTCOVES_PHP_MODE=worker ;;
    *) GIFTCOVES_PHP_MODE=classic ;;
esac

if [ "$GIFTCOVES_PHP_MODE" = worker ] \
    && { [ ! -f public/frankenphp-worker.php ] || [ ! -f vendor/laravel/octane/bin/frankenphp-worker.php ]; }; then
    echo "entrypoint: OCTANE_WORKERS is on but the Octane worker files are missing, serving in classic mode" >&2
    GIFTCOVES_PHP_MODE=classic
fi

export GIFTCOVES_PHP_MODE

# Hand over to the base image's own entrypoint, which is what ran before this
# script was put in front of it (it prefixes `frankenphp run` when the command
# starts with a flag, and otherwise just execs the command). `exec` so the
# command becomes PID 1's child directly and receives SIGTERM from `docker
# stop` — Horizon relies on that to finish the job in flight.
exec docker-php-entrypoint "$@"
