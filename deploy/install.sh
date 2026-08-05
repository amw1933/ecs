#!/bin/sh
# OpsDeck 一键安装/更新脚本
#
# 部署目录只显示 docker-compose.yml 和 data/；代码克隆到 -build 目录构建进镜像，
# 不需要在部署目录看到任何源码文件。
#
# 用法（任意装有 Docker 的 Linux / 群晖上，一条命令自动拉取部署）：
#   REPO_URL=https://github.com/amw1933/ecs.git bash -c "$(curl -fsSL https://raw.githubusercontent.com/amw1933/ecs/main/deploy/install.sh)"
#
# 可选环境变量：
#   DEPLOY_DIR     运行时目录（默认 /volume1/docker/ecs，只放 compose + data）
#   BUILD_DIR      构建目录（默认 ${DEPLOY_DIR}-build，代码在这里，可随时删除）
#   PORT           宿主机监听（默认 0.0.0.0:43211）
#   CRON_INTERVAL  自动调度间隔秒（默认 60）
#   CONTAINER_NAME 容器名（默认 ecs）
#   DATA_SOURCE    旧部署的 data 目录；新部署时自动继承数据，留空则不复制
set -e

REPO_URL="${REPO_URL:-}"
DEPLOY_DIR="${DEPLOY_DIR:-/volume1/docker/ecs}"
BUILD_DIR="${BUILD_DIR:-${DEPLOY_DIR}-build}"
PORT="${PORT:-0.0.0.0:43211}"
CRON_INTERVAL="${CRON_INTERVAL:-60}"
CONTAINER_NAME="${CONTAINER_NAME:-ecs}"

command -v git >/dev/null 2>&1 || { echo "[错误] 缺少 git"; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "[错误] 缺少 docker（群晖请先安装 Docker 套件）"; exit 1; }

project_ok() {
    [ -f "$1/app/bootstrap.php" ] \
        && [ -f "$1/deploy/docker/Dockerfile" ] \
        && { [ -f "$1/docker-compose.yml" ] || [ -f "$1/compose.yaml" ]; }
}

echo "==> 部署目录：$DEPLOY_DIR（只包含 docker-compose.yml 和 data/）"
echo "==> 构建目录：$BUILD_DIR（代码在这里，构建进镜像，可删除）"

# 1) 准备构建目录（拉取/更新代码，代码不进运行时目录）
if [ -n "$REPO_URL" ]; then
    if [ -d "$BUILD_DIR/.git" ]; then
        echo "==> 更新构建目录代码：$BUILD_DIR"
        git -C "$BUILD_DIR" pull --ff-only
    elif project_ok "$BUILD_DIR"; then
        echo "==> 构建目录已有代码：$BUILD_DIR"
    elif [ -d "$BUILD_DIR" ] && { [ -e "$BUILD_DIR/data/panel.db" ] || [ -e "$BUILD_DIR/storage/panel.db" ]; }; then
        echo "[错误] 构建目录里存在数据；请检查 BUILD_DIR 配置。"
        exit 1
    else
        if [ -d "$BUILD_DIR" ]; then
            mv "$BUILD_DIR" "${BUILD_DIR}.incomplete-$(date +%s)"
            echo "==> 残留不完整目录已移走"
        fi
        echo "==> 拉取代码到构建目录：$BUILD_DIR"
        mkdir -p "$BUILD_DIR"
        git clone --depth 1 "$REPO_URL" "$BUILD_DIR"
    fi
    CODE_DIR="$BUILD_DIR"
elif project_ok .; then
    CODE_DIR="$(pwd)"
    echo "==> 使用当前目录作为代码源：$CODE_DIR"
else
    echo "[错误] 未设置 REPO_URL，且当前目录不是 OpsDeck 项目。"
    exit 1
fi

# 2) 清理运行时目录里残留的源码文件（只保留 data/，compose 将重建）
mkdir -p "$DEPLOY_DIR"
OLD_DIR="$DEPLOY_DIR/.old-$(date +%s)"
for item in app assets deploy public index.php cron.php .dockerignore .htaccess .gitignore README.md DEPLOY.md start-dev.ps1 start-dev.sh compose.yaml .git; do
    if [ -e "$DEPLOY_DIR/$item" ]; then
        mkdir -p "$OLD_DIR"
        mv "$DEPLOY_DIR/$item" "$OLD_DIR/" 2>/dev/null || true
    fi
done
[ -d "$OLD_DIR" ] && echo "==> 旧源码文件已移入 $OLD_DIR"

# 3) 构建镜像（在构建目录执行）
echo "==> 构建镜像 opsdeck-ecs:latest（首次约几分钟）"
( cd "$CODE_DIR" && PORT="$PORT" CRON_INTERVAL="$CRON_INTERVAL" CONTAINER_NAME="$CONTAINER_NAME" docker compose build )

# 4) 生成运行时 compose（只引用镜像，不含源码）并准备 data
cp "$CODE_DIR/deploy/docker/compose.runtime.yml" "$DEPLOY_DIR/docker-compose.yml"
mkdir -p "$DEPLOY_DIR/data"
if [ -n "${DATA_SOURCE:-}" ] && [ -f "$DATA_SOURCE/panel.db" ] && [ -f "$DATA_SOURCE/app.key" ] && [ ! -e "$DEPLOY_DIR/data/panel.db" ]; then
    echo "==> 从 $DATA_SOURCE 自动继承数据（账号/密钥）"
    cp -a "$DATA_SOURCE/." "$DEPLOY_DIR/data/"
fi
chmod -R 777 "$DEPLOY_DIR/data" 2>/dev/null || true

# 5) 启动
echo "==> 启动服务"
( cd "$DEPLOY_DIR" && PORT="$PORT" CRON_INTERVAL="$CRON_INTERVAL" CONTAINER_NAME="$CONTAINER_NAME" docker compose up -d )
docker exec -u root "$CONTAINER_NAME" chown -R www-data:www-data /var/www/ecs/storage 2>/dev/null || true

echo ""
echo "部署完成："
echo "  访问地址：http://服务器IP:${PORT##*:}"
echo "  自动守护：已启用（每 ${CRON_INTERVAL} 秒自动检查并拉起抢占实例）"
echo "  初始化 token：cat $DEPLOY_DIR/data/logs/app.log 2>/dev/null | grep '初始化 token'"
echo "  部署目录内容：$DEPLOY_DIR（docker-compose.yml + data/）"
echo "  查看日志：cd $DEPLOY_DIR && docker compose logs -f"
