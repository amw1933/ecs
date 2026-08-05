#!/bin/sh
# OpsDeck ECS 面板 一键安装/更新脚本
#
# 用法（任意装有 Docker 的 Linux / 群晖上，一条命令自动拉取部署）：
#   REPO_URL=https://github.com/amw1933/ecs.git bash -c "$(curl -fsSL https://raw.githubusercontent.com/amw1933/ecs/main/deploy/install.sh)"
#
# 可选环境变量：
#   DEPLOY_DIR     部署目录（默认 /volume1/docker/ecs，自动创建，无需手动建）
#   PORT           宿主机监听（默认 0.0.0.0:43211，局域网可访问）
#   CRON_INTERVAL  自动调度间隔秒（默认 60，每分钟一次）
#   DATA_SOURCE    旧部署的 data 目录；新部署时自动继承数据（账号/密钥/日志），留空则不复制
#
# 部署完成后即自动守护：内置 cron 容器每 CRON_INTERVAL 秒自动运行调度，
# 抢占实例停止后自动拉起并通知——不需要打开网页，也不需要配置系统定时任务。
set -e

REPO_URL="${REPO_URL:-}"
DEPLOY_DIR="${DEPLOY_DIR:-/volume1/docker/ecs}"
PORT="${PORT:-0.0.0.0:43211}"
CRON_INTERVAL="${CRON_INTERVAL:-60}"

command -v git >/dev/null 2>&1 || { echo "[错误] 缺少 git"; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "[错误] 缺少 docker（群晖请先安装 Docker 套件）"; exit 1; }

if [ -n "$REPO_URL" ]; then
    if [ -d "$DEPLOY_DIR/.git" ]; then
        echo "==> 更新已有代码：$DEPLOY_DIR"
        git -C "$DEPLOY_DIR" pull --ff-only
    elif [ -f "$DEPLOY_DIR/docker-compose.yml" ] || [ -f "$DEPLOY_DIR/compose.yaml" ]; then
        echo "==> 目标目录已有项目代码，跳过克隆：$DEPLOY_DIR"
    else
        echo "==> 自动创建部署目录并克隆代码：$DEPLOY_DIR"
        mkdir -p "$DEPLOY_DIR"
        git clone --depth 1 "$REPO_URL" "$DEPLOY_DIR"
    fi
    cd "$DEPLOY_DIR"
elif [ -f ./docker-compose.yml ] || [ -f ./compose.yaml ]; then
    echo "==> 使用当前目录部署（无需另建 web 目录）"
    cd "$(pwd)"
else
    echo "[错误] 未设置 REPO_URL，且当前目录不是 OpsDeck 项目。"
    exit 1
fi

echo "==> 设置 storage 权限"
mkdir -p data
if [ -n "${DATA_SOURCE:-}" ] && [ -f "$DATA_SOURCE/panel.db" ] && [ -f "$DATA_SOURCE/app.key" ] && [ ! -e data/panel.db ]; then
    echo "==> 从 $DATA_SOURCE 自动继承数据（账号/密钥）"
    cp -a "$DATA_SOURCE/." data/
fi
if [ -d storage ] && [ ! -e data/panel.db ] && [ ! -e data/.htaccess ]; then
    echo "==> 迁移旧 storage 数据到 data/（新结构只需一个 data 目录）"
    cp -a storage/. data/ 2>/dev/null || true
fi
chmod -R 777 data 2>/dev/null || true

echo "==> 启动服务（首次会自动拉取基础镜像并构建）"
PORT="$PORT" CRON_INTERVAL="$CRON_INTERVAL" docker compose up -d --build

echo "==> 修正 storage 属主（统一为 www-data，避免数据库只读）"
docker exec -u root ecs chown -R www-data:www-data /var/www/ecs/storage 2>/dev/null || true

echo "==> 验证自动调度容器"
docker ps --filter "name=ecs" --format "ecs 运行中（内置调度每 ${CRON_INTERVAL} 秒执行一次）" 2>/dev/null || true

echo ""
echo "部署完成："
echo "  访问地址：http://服务器IP:${PORT##*:}"
echo "  自动守护：已启用（每 ${CRON_INTERVAL} 秒自动检查并拉起抢占实例，无需打开网页/系统定时任务）"
echo "  初始化 token：cat $DEPLOY_DIR/data/logs/app.log 2>/dev/null | grep '初始化 token'"
echo "  查看日志：cd $DEPLOY_DIR && docker compose logs -f"
