# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# base - pinned PHP-FPM runtime + the system packages/extensions every later
# stage needs. Nothing application-specific lives here.
# ---------------------------------------------------------------------------
FROM php:8.5-fpm-alpine@sha256:951fb5b1780219b81d5daffa4cebef8e0077c537f3893d9790a5dfcd66ca334d AS base

# apk upgrade picks up any Alpine 3.24 security patch released after this
# base image tag was last built (e.g. zlib/musl point releases) - scanned
# and verified with `docker scout cves`, see docs/BASELINE.md.
RUN apk upgrade --no-cache \
    && apk add --no-cache nginx supervisor dos2unix \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install pdo_mysql sockets pcntl bcmath \
    && apk del .build-deps

WORKDIR /var/www

# ---------------------------------------------------------------------------
# deps - production-only vendor/, cached across builds via BuildKit.
# Built FROM base (not the bare composer:2 image) because this app's own
# platform requirement (ext-sockets, for php-amqplib/the RabbitMQ queue
# driver) must already be installed for composer's platform check to pass
# - the composer:2 image alone doesn't have it.
# ---------------------------------------------------------------------------
FROM base AS deps
COPY --from=composer:2@sha256:3d1eb9437516b0ba88c46a0bb05826451a1ab178d29adacb5ded26dad0899ae4 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock* ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_ALLOW_SUPERUSER=1 \
    composer install --no-dev --no-scripts --no-autoloader --no-interaction --ansi

# ---------------------------------------------------------------------------
# test-deps - full vendor/ (incl. dev deps), cached separately from `deps`
# so switching between the two targets never busts either cache.
# ---------------------------------------------------------------------------
FROM base AS test-deps
COPY --from=composer:2@sha256:3d1eb9437516b0ba88c46a0bb05826451a1ab178d29adacb5ded26dad0899ae4 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY composer.json composer.lock* ./
RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache COMPOSER_ALLOW_SUPERUSER=1 \
    composer install --no-scripts --no-autoloader --no-interaction --ansi

# ---------------------------------------------------------------------------
# build - the real application tree on top of `base`, production vendor only.
# ---------------------------------------------------------------------------
FROM base AS build
COPY --from=deps /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY --from=deps /var/www/vendor ./vendor
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --no-interaction --ansi \
    && chown -R :www-data /var/www \
    && chmod -R 775 storage bootstrap/cache

# ---------------------------------------------------------------------------
# test - full app tree + dev deps. `make test` builds this target and runs
# it (`docker compose run --rm main_test`) against the live main_db +
# rabbitmq containers - the same real-MySQL contract
# docs/TESTING_STRATEGY.md deliberately tests against (RefreshDatabase has
# no sqlite driver installed here on purpose, see that doc). The same
# built runtime image also serves as `main_worker` (see
# docker-compose.yaml) - the worker overrides only `command:`, so its
# queue:work loop runs against this exact tested vendor/app tree too.
# ---------------------------------------------------------------------------
FROM base AS test
COPY --from=test-deps /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY --from=test-deps /var/www/vendor ./vendor
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-interaction --ansi \
    && chown -R :www-data /var/www \
    && chmod -R 775 storage bootstrap/cache
CMD ["sh", "-c", "php artisan migrate --force && php artisan test && composer audit --no-interaction --abandoned=report"]

# ---------------------------------------------------------------------------
# runtime - nginx + php-fpm under supervisord. Non-root, healthchecked,
# handles SIGTERM via supervisord's own graceful-shutdown handling.
# ---------------------------------------------------------------------------
FROM base AS runtime

ENV FPM_PM_MAX_CHILDREN=20 \
    FPM_PM_START_SERVERS=2 \
    FPM_PM_MIN_SPARE_SERVERS=1 \
    FPM_PM_MAX_SPARE_SERVERS=3 \
    APP_ENV=local \
    APP_DEBUG=false

COPY --from=build --chown=:www-data /var/www /var/www

COPY ./docker/docker-php-* /usr/local/bin/
RUN dos2unix /usr/local/bin/docker-php-entrypoint \
    && chmod +x /usr/local/bin/docker-php-entrypoint

COPY ./docker/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/default.conf /etc/nginx/conf.d/default.conf
COPY ./docker/supervisord.conf /etc/supervisord.conf

# The entrypoint writes a fresh pool override here on every container start
# (so FPM_PM_* env vars stay configurable at runtime) - root-owned 755 by
# default, so the non-root runtime user needs write access to the dir.
# Alpine's nginx package ships /var/lib/nginx owned by its own `nginx`
# system user (not www-data) - chown it so the non-root runtime user can
# use its log/run/tmp subdirs. Pre-create the temp-file dirs nginx.conf
# points at under /tmp: nginx's own mkdir() for temp_path is not
# recursive, so the parent must already exist.
RUN chown www-data:www-data /usr/local/etc/php-fpm.d \
    && chown -R www-data:www-data /var/lib/nginx /var/log/nginx \
    && mkdir -p /tmp/nginx/client_temp /tmp/nginx/proxy_temp /tmp/nginx/fastcgi_temp /tmp/nginx/uwsgi_temp /tmp/nginx/scgi_temp \
    && chown -R www-data:www-data /tmp/nginx

USER www-data

EXPOSE 8080
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=5 \
    CMD wget -q -O- http://127.0.0.1:8080/ >/dev/null 2>&1 || exit 1

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
