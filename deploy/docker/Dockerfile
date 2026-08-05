FROM php:8.2-fpm-alpine

# nginx + 扩展 + 时区 + su-exec（调度循环以 www-data 运行，避免数据库属主冲突）
RUN apk add --no-cache nginx tzdata sqlite-dev oniguruma-dev libcurl curl-dev su-exec \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite mbstring curl \
    && rm -rf /var/cache/apk/*

ENV TZ=Asia/Shanghai
WORKDIR /var/www/ecs

COPY . /var/www/ecs
COPY deploy/docker/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p /var/www/ecs/storage/logs \
    && chown -R www-data:www-data /var/www/ecs/storage

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
