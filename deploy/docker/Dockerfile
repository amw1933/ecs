FROM php:8.2-fpm-alpine

# pdo_sqlite / mbstring / curl / 时区数据库
RUN apk add --no-cache tzdata sqlite-dev oniguruma-dev libcurl curl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite mbstring curl \
    && rm -rf /var/cache/apk/*

ENV TZ=Asia/Shanghai
WORKDIR /var/www/ecs
