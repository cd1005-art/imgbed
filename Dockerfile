# 逍遥图床 Docker 镜像（多阶段构建）
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        libpng-dev libjpeg-turbo-dev freetype-dev zip libzip-dev oniguruma-dev icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd fileinfo mbstring curl zip opcache \
    && apk add --no-cache tzdata \
    && cp /usr/share/zoneinfo/Asia/Shanghai /etc/localtime \
    && echo "Asia/Shanghai" > /etc/timezone

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor

# 运行目录可写（compose 会再挂卷）
RUN mkdir -p runtime public/storage/images \
    && chown -R www-data:www-data runtime public/storage \
    && chmod -R 775 runtime public/storage

EXPOSE 9000
CMD ["php-fpm"]
