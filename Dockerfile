FROM node:18-slim as node-builder

COPY . ./app
WORKDIR /app
# NODE_OPTIONSを追加してcryptoの問題を解決
RUN export NODE_OPTIONS=--openssl-legacy-provider && \
    npm install && \
    npm run build

# PHP 8.2に更新
FROM php:8.2-apache

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
COPY --from=node-builder /app/public/build ./public/build

RUN composer install --no-dev --optimize-autoloader
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Apacheのmod_rewriteを有効化
RUN a2enmod rewrite 