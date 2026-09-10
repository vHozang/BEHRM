FROM php:8.2-fpm

ARG UID=1000
ARG GID=1000

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y \
    git curl unzip zip libpq-dev libzip-dev libpng-dev libjpeg-dev \
    libfreetype6-dev libicu-dev libonig-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pdo_mysql zip gd bcmath pcntl exif intl \
    && php -r 'foreach (["dom", "simplexml", "xml", "xmlreader", "xmlwriter"] as $extension) { if (! extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } }' \
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
