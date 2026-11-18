FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM composer:2 AS composer_binary

FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j2 bcmath exif gd pdo_mysql pcntl \
    && printf "upload_max_filesize = 25M\npost_max_size = 30M\nmemory_limit = 256M\nmax_execution_time = 120\n" > /usr/local/etc/php/conf.d/bshop-uploads.ini \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer_binary /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && php artisan package:discover --ansi
COPY --from=frontend /app/public/build ./public/build
COPY deploy/linux-test/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/linux-test/entrypoint.sh /usr/local/bin/bshop-entrypoint
RUN chmod 755 /usr/local/bin/bshop-entrypoint \
    && ln -s /var/www/html/storage/app/public /var/www/html/public/storage
ENTRYPOINT ["bshop-entrypoint"]
CMD ["apache2-foreground"]
