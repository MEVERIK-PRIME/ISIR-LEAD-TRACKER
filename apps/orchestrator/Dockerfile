FROM composer:2 AS vendor
WORKDIR /app
# Copy entire app first (we need artisan and app/ for post-autoload-dump hooks)
COPY apps/orchestrator/. .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM php:8.3-cli-alpine
WORKDIR /app

RUN apk add --no-cache bash libpq-dev \
    && docker-php-ext-install pdo_pgsql bcmath pcntl

COPY --from=vendor /app/vendor ./vendor
COPY apps/orchestrator/. .

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

CMD ["sh", "-lc", "php artisan serve --host=0.0.0.0 --port=${PORT:-8080}"]
