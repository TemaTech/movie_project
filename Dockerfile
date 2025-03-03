FROM node:16-slim as node-builder

COPY . ./app
RUN cd /app && npm ci && npm run prod

FROM php:8.1-apache

# 必要なパッケージのインストール
RUN apt-get update && apt-get install -y \
    zip \
    unzip \
    git \
    libpq-dev

# PHP拡張のインストール
RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql opcache

# Apacheの設定変更
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Composerのインストール
COPY --from=composer:2.0 /usr/bin/composer /usr/bin/composer

# アプリケーションのセットアップ
WORKDIR /var/www/html
COPY . ./
COPY --from=node-builder /app/public ./public

RUN composer install --no-dev --optimize-autoloader
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 