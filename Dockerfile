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

# Extensions nécessaires : PostgreSQL, Redis, intl, bcmath, zip, opcache.
#
# L'extension `redis` est indispensable : la pile configure CACHE_STORE,
# QUEUE_CONNECTION et SESSION_DRIVER sur redis. Sans elle, toute route passant
# par le groupe de middlewares `web` (donc avec session) échoue en
# « Class "Redis" not found », et les files d'attente ne démarrent pas.
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
    && pecl install redis \
    && docker-php-ext-enable redis \
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

# L'utilisateur applicatif est créé AVANT la copie du code, pour pouvoir en
# devenir propriétaire directement (COPY --chown).
RUN addgroup -g 1000 -S app && adduser -u 1000 -S app -G app

# `--chown` est indispensable : selon l'umask de la machine de build, les
# fichiers sources peuvent être en 600 et appartiennent à root. Sans cela,
# l'utilisateur non privilégié `app` ne peut pas les lire — `php artisan migrate`
# échoue sur « Failed to open stream: Permission denied ». Le chmod garantit en
# plus que le propriétaire peut lire et traverser l'arborescence.
COPY --from=vendor --chown=app:app /app /var/www/html

RUN chmod -R u+rwX /var/www/html \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER app

# Port d'écoute du serveur HTTP. Surchargeable à l'exécution (`-e PORT=8080`) :
# le healthcheck et la commande de démarrage s'y adaptent automatiquement.
ENV PORT=8000

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:'.(getenv('PORT') ?: '8000').'/api/v1/health') ? 0 : 1);"

# En production réelle, préférez php-fpm + nginx (ou Laravel Octane) derrière
# un reverse proxy. `artisan serve` convient à un déploiement de taille modérée.
#
# Forme shell volontaire : elle permet d'utiliser $PORT tout en conservant une
# valeur par défaut si la variable n'est pas définie.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan migrate --force && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
