# syntax=docker/dockerfile:1
#
# Single container: FrankenPHP serves the app, an entrypoint runs the scheduler
# and the queue worker beside it. SQLite lives inside the image, so there is no
# managed database to pay for — the demo resets itself every 15 minutes anyway.

FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions \
    pdo_sqlite \
    bcmath \
    gd \
    intl \
    zip \
    opcache

WORKDIR /app


FROM base AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Vendor first so a source-only change does not re-resolve dependencies.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# The demo re-seeds itself every 15 minutes and the seeders use model factories,
# which need Faker. It stays a dev dependency in composer.json — the application
# itself has no runtime use for it — and is promoted only inside this image,
# where seeding genuinely is a runtime concern. This has to run after `COPY . .`,
# which would otherwise restore the unmodified composer.json over it.
RUN composer require --no-interaction --no-scripts --no-audit --update-no-dev fakerphp/faker \
    && composer dump-autoload --optimize --no-dev --classmap-authoritative

# Wayfinder generates its TypeScript route helpers by shelling out to artisan
# during the Vite build, so PHP and vendor/ must already be in place here.
RUN npm run build

RUN rm -rf node_modules tests .git


FROM base AS runtime

# APP_ENV is `demo`, not `production`, and deliberately: AppServiceProvider calls
# DB::prohibitDestructiveCommands(app()->isProduction()), which is exactly the
# guard that should stop a `migrate:fresh` on the real system. The playground
# opts out by not claiming to be production, rather than by weakening the guard.
ENV APP_ENV=demo \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/database/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    MAIL_MAILER=log \
    DEMO_RESET=true \
    DEMO_MODE=true \
    SERVER_NAME=:80

COPY --from=build /app /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/database

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":80"]
