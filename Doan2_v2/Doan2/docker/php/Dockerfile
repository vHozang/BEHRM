FROM php:8.2-fpm

ARG UID=1000
ARG GID=1000

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git curl unzip zip libpq-dev libzip-dev libpng-dev libjpeg-dev \
    libfreetype6-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql pdo_mysql zip gd mbstring bcmath pcntl exif \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-hrm-opcache.ini

RUN groupadd -g ${GID} laravel \
    && useradd -u ${UID} -g laravel -m laravel

USER laravel

CMD ["php-fpm"]
