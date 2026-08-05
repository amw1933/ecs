#!/bin/sh
set -e

CRON_INTERVAL="${CRON_INTERVAL:-60}"

# 确保 storage 可写（宿主机 data 目录映射到这里）
mkdir -p /var/www/ecs/storage/logs /var/www/ecs/storage/cache
chown -R www-data:www-data /var/www/ecs/storage 2>/dev/null || true

# 后台启动 php-fpm
php-fpm -D

# 后台自动调度循环（保活/熔断/成本同步等，与参考项目常驻守护行为一致）
(
  while true; do
    su-exec www-data php /var/www/ecs/cron.php run >> /var/www/ecs/storage/logs/cron.log 2>&1 || true
    sleep "${CRON_INTERVAL}"
  done
) &

# 前台运行 nginx
exec nginx -g 'daemon off;'
