#!/usr/bin/env sh
set -e
command -v php >/dev/null 2>&1 || { echo "未找到 PHP，请先安装 PHP 8.1+"; exit 1; }
echo "OpsDeck 开发服务器：http://127.0.0.1:43210"
exec php -S 127.0.0.1:43210 -t public
