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

# Hand over to the base image's own entrypoint, which is what ran before this
# script was put in front of it (it prefixes `frankenphp run` when the command
# starts with a flag, and otherwise just execs the command). `exec` so the
# command becomes PID 1's child directly and receives SIGTERM from `docker
# stop` — Horizon relies on that to finish the job in flight.
exec docker-php-entrypoint "$@"
