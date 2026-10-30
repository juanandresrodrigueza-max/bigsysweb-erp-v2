# El frontend (Vue + Vite) se compila aparte y sólo se copia public/build a la imagen de PHP.
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY public ./public
RUN npm run build

FROM php:8.2-fpm-alpine

RUN apk add --no-cache bash curl git libpng-dev libjpeg-turbo-dev libwebp-dev libzip-dev oniguruma-dev postgresql-dev unzip zip

RUN docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install bcmath exif gd mbstring opcache pdo pdo_pgsql pcntl zip

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
