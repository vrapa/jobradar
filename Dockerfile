FROM php:8.4-apache AS php-runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl default-mysql-client git unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

FROM php-runtime AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --optimize-autoloader \
    --prefer-dist

FROM node:20.19-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY assets ./assets
RUN mkdir -p public/assets && npm run build

FROM php-runtime

WORKDIR /var/www/html
COPY . ./
COPY --from=dependencies /app/vendor ./vendor
COPY --from=frontend /app/public/assets ./public/assets
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p var/backups var/log var/temp \
    && chown -R www-data:www-data var

HEALTHCHECK --interval=15s --timeout=5s --retries=5 \
    CMD curl --fail --silent http://127.0.0.1/health.php > /dev/null || exit 1
