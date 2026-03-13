FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libzip-dev zip unzip git libpng-dev libonig-dev \
    librabbitmq-dev libssh-dev \
    && docker-php-ext-install pdo pdo_mysql pcntl sockets bcmath zip \
    && pecl install amqp && docker-php-ext-enable amqp \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8088"]
