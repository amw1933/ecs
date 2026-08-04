#!/bin/sh
# OpsDeck ECS 面板 一键安装/更新脚本
#
# 用法（任意装有 Docker 的 Linux / 群晖上，一条命令自动拉取部署）：
#   REPO_URL=https://gitee.com/你的用户名/ecs.git bash -c "$(curl -fsSL https://gitee.com/你的用户名/ecs/raw/main/deploy/install.sh)"
#
# 可选环境变量：
#   DEPLOY_DIR     部署目录（默认 /volume1/docker/ecs，自动创建，无需手动建）
#   PORT           宿主机监听（默认 0.0.0.0:43211，局域网可访问）
#   CRON_INTERVAL  调度执行间隔秒（默认 3600，即 1 小时一次）
set -e

REPO_URL="${REPO_URL:-}"
DEPLOY_DIR="${DEPLOY_DIR:-/volume1/docker/ecs}"
PORT="${PORT:-0.0.0.0:43211}"
CRON_INTERVAL="${CRON_INTERVAL:-3600}"

command -v git >/dev/null 2>&1 || { echo "[错误] 缺少 git"; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "[错误] 缺少 docker（群晖请先安装 Docker 套件）"; exit 1; }

if [ -n "$REPO_URL" ]; then
    if [ -d "$DEPLOY_DIR/.git" ]; then
        echo "==> 更新已有代码：$DEPLOY_DIR"
        git -C "$DEPLOY_DIR" pull --ff-only
    else
        echo "==> 自动创建部署目录并克隆代码：$DEPLOY_DIR"
        mkdir -p "$DEPLOY_DIR"
        git clone --depth 1 "$REPO_URL" "$DEPLOY_DIR"
    fi
    cd "$DEPLOY_DIR"
elif [ -f ./docker-compose.yml ]; then
    echo "==> 使用当前目录部署（无需另建 web 目录）"
    cd "$(pwd)"
else
    echo "[错误] 未设置 REPO_URL，且当前目录不是 OpsDeck 项目。"
    exit 1
fi

echo "==> 设置 storage 权限"
chmod -R 777 storage 2>/dev/null || true

echo "==> 启动服务（首次会自动拉取基础镜像并构建）"
PORT="$PORT" CRON_INTERVAL="$CRON_INTERVAL" docker compose up -d --build

echo "==> 修正 storage 属主（统一为 www-data，避免数据库只读）"
docker exec -u root ecs-php chown -R www-data:www-data storage 2>/dev/null || true

echo ""
echo "部署完成："
echo "  访问地址：http://服务器IP:${PORT##*:}"
echo "  初始化 token：cat $DEPLOY_DIR/storage/logs/app.log 2>/dev/null | grep '初始化 token'"
echo "  查看日志：cd $DEPLOY_DIR && docker compose logs -f"
