FROM php:8.5.12-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN mkdir -p storage/app/private/invoice-temp storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod 700 storage/app/private/invoice-temp
COPY production.ini /usr/local/etc/php/conf.d/production.ini
COPY production-fpm.conf /usr/local/etc/php-fpm.d/zz-privacy.conf
USER www-data
ENTRYPOINT ["sh", "/var/www/html/docker-entrypoint.sh"]
CMD ["php-fpm", "-F"]
