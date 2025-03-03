FROM node:18-slim as node-builder

COPY . ./app
WORKDIR /app
# NODE_OPTIONSを追加してcryptoの問題を解決
RUN export NODE_OPTIONS=--openssl-legacy-provider && \
    npm install && \
    npm run build

# PHP 8.2に更新
FROM php:8.2-fpm

# プロダクション用の最適化
ENV PHP_OPCACHE_ENABLE=1
ENV PHP_OPCACHE_ENABLE_CLI=1
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
ENV PHP_OPCACHE_REVALIDATE_FREQ=0
ENV COMPOSER_ALLOW_SUPERUSER=1

# 必要なパッケージのインストール
RUN apt-get update && apt-get install -y \
    libpq-dev \
    nginx \
    git \
    zip \
    unzip \
    libzip-dev \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip opcache

# Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# プロジェクトファイルのコピー
COPY . .

# 依存関係のインストール
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# キャッシュクリアとルート最適化
RUN php artisan config:cache \
    && php artisan view:cache

# Nginxの設定
RUN rm -f /etc/nginx/sites-enabled/default \
    && rm -f /etc/nginx/sites-available/default

# Nginxの設定ファイルをコピー
COPY docker/nginx/nginx.conf /etc/nginx/sites-available/default
RUN ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/

# 権限の設定
RUN chown -R www-data:www-data /var/www/html/storage \
    && chown -R www-data:www-data /var/www/html/bootstrap/cache

# ポート設定
EXPOSE 8080

# 起動スクリプトのコピーと実行
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]