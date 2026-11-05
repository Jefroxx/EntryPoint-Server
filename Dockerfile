# EntryPoint API for a container host (Render, Google Cloud Run, a plain server, ...).
# Build:  docker build -t entrypoint-api .      Run:  docker run -p 10000:10000 --env-file .env entrypoint-api

# 1. PHP packages, installed in their own stage so a code-only change does not reinstall them.
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts --ignore-platform-reqs

# 2. The server that runs it: Apache with PHP 8.2.
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libicu-dev unzip \
    && docker-php-ext-install pdo_mysql bcmath zip intl exif opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Serve the "public" folder, and let .htaccess rewrite every address to index.php.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN chmod +x docker/entrypoint.sh

# Render tells the container which port to listen on with $PORT (10000 by default).
EXPOSE 10000
CMD ["docker/entrypoint.sh"]
