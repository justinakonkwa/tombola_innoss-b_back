# =============================================================================
# API Laravel — Tombola Innoss'B
# =============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative


FROM php:8.1-cli-alpine AS runtime

# Extensions nécessaires : PostgreSQL, intl, bcmath, zip, opcache.
RUN apk add --no-cache \
        libpq \
        icu-libs \
        libzip \
        oniguruma \
        fcgi \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl bcmath zip opcache \
    && apk del .build-deps

# Configuration PHP de production.
RUN printf '%s\n' \
    'memory_limit = 512M' \
    'upload_max_filesize = 32M' \
    'post_max_size = 32M' \
    'expose_php = Off' \
    'display_errors = Off' \
    'log_errors = On' \
    'opcache.enable = 1' \
    'opcache.memory_consumption = 192' \
    'opcache.max_accelerated_files = 20000' \
    'opcache.validate_timestamps = 0' \
    'date.timezone = Africa/Kinshasa' \
    > /usr/local/etc/php/conf.d/zz-tombola.ini

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

# Exécution sans privilèges.
RUN addgroup -g 1000 -S app && adduser -u 1000 -S app -G app \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rw storage bootstrap/cache

USER app

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/api/v1/health') ? 0 : 1);"

# En production réelle, préférez php-fpm + nginx (ou Laravel Octane) derrière
# un reverse proxy. `artisan serve` convient à un déploiement de taille modérée.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=8000"]
