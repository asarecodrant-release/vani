FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install

EXPOSE 10000

CMD php -S 0.0.0.0:$PORT