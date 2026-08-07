# One image, three roles: `app`, `queue` and `scheduler` all run from this.
#
# FrankenPHP rather than nginx + php-fpm: a single process serving HTTP, so
# there is one container to reason about instead of two that have to agree on a
# socket. It also gives us Octane later without changing the deployment shape.

# ------------------------------------------------------------ frontend ------
# Vite runs in its own stage so Node never reaches the runtime image.
FROM node:24-alpine AS frontend
WORKDIR /build

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
# VITE_* is inlined into the client bundle HERE. If it is missing at this point
# it is missing in the browser forever — which is why these are Build Variables
# in Coolify, not runtime environment variables.
ARG VITE_APP_NAME=Brandcoves
ENV VITE_APP_NAME=$VITE_APP_NAME
RUN npm run build

# ------------------------------------------------------------- vendor -------
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# --no-scripts: artisan is not present yet and package discovery needs the full
# application. It runs in the final stage instead.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

# ------------------------------------------------------------ runtime -------
FROM dunglas/frankenphp:php8.4-alpine AS runtime

# pcntl and posix are what Horizon needs to supervise its workers — they have no
# Windows build, which is why dev uses `queue:work` and production uses Horizon.
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        gd \
        opcache \
        pcntl \
        posix \
        redis

WORKDIR /app

# The composer binary itself, not just its output: dump-autoload has to run
# after the application source is present, which is here rather than in the
# vendor stage. frankenphp ships no composer.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY --from=vendor /build/vendor ./vendor
COPY . .
COPY --from=frontend /build/public/build ./public/build

# Now that the full app is present, finish the autoloader and run discovery.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -f /usr/local/bin/composer

# Compiled once at build time rather than on first request. Deliberately NOT
# config:cache — that would bake build-time env values into the image, and the
# same image is deployed to staging and production with different config.
RUN php artisan event:cache \
    && php artisan view:cache

# Coolify passes the deployed commit as a build arg. Baking it into the image
# is the only reliable way to have it at runtime — the same variable is
# "unknown" in the runtime environment.
ARG SOURCE_COMMIT=unknown
ENV GIT_COMMIT_SHA=$SOURCE_COMMIT

ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp

# JIT off, opcache on: this is request-response PHP, where the JIT warm-up cost
# is rarely repaid but opcache always is.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=disable'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Writable state. The image is otherwise read-only.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

# Coolify's Traefik terminates TLS, so FrankenPHP serves plain HTTP internally.
#
# Port 80, not 8080: the frankenphp base image already exposes 80, 443 and 2019.
# Adding a fourth left Traefik with several candidates and no
# `loadbalancer.server.port` label to disambiguate, so it picked 80 — where
# nothing was listening — and every request 502'd. Serving on the port Traefik
# already assumes removes the ambiguity rather than papering over it.
CMD ["frankenphp", "php-server", "--listen", ":80", "--root", "/app/public", "-v"]
