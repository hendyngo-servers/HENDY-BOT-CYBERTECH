FROM composer:2 AS composer

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libcurl4-openssl-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring curl \
    && a2enmod rewrite headers proxy proxy_http proxy_wstunnel \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/app
COPY . /var/www/app
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative --no-progress
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
RUN chown -R www-data:www-data /var/www/app \
    && chmod -R 755 /var/www/app/public \
    && chmod +x /var/www/app/docker/start.sh

# Render's default web-service port is 10000.
EXPOSE 10000
CMD ["/var/www/app/docker/start.sh"]
