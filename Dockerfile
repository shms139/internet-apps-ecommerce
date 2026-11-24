FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libsqlite3-dev \
    libpq-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zlib1g-dev \
    protobuf-compiler \
    protobuf-compiler-grpc \
    $PHPIZE_DEPS \
    && pecl channel-update pecl.php.net \
    && pecl install grpc-1.85.0 \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable grpc redis \
    && docker-php-ext-install pdo_sqlite pdo_pgsql mbstring bcmath pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN COMPOSER_MAX_PARALLEL_HTTP=1 COMPOSER_PROCESS_TIMEOUT=600 \
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
