# OpsDeck 部署说明（目录即 Web 根，零配置）

整个文件夹拷到新服务器后，**把 Web 根直接指向本目录**，打开站点即可进入初始化页，
无需把 Web 根指向 `public/`，无需手工改任何配置。

## 一、拷贝

把整个 `ecs-panel/` 文件夹复制到新服务器，例如 `/var/www/ecs-panel/`。

> 迁移旧数据：务必连同 `storage/panel.db` 与 `storage/app.key` 一起拷贝
> （面板会沿用已有账号、密钥与流量数据；只拷代码不带 storage 等于全新部署）。

## 二、权限

保证 PHP 进程对 `storage/` 可写：

```bash
chown -R www-data:www-data storage
chmod -R 750 storage
```

## 三、Web 服务器配置

### Nginx（推荐）

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/ecs-panel;          # 目录本身，不是 public/
    index index.php;

    # 敏感目录/文件一律拒绝
    location ~ ^/(app|storage)/ { deny all; }
    location ~ ^/(cron\.php|README\.md|DEPLOY\.md|start-dev\.(ps1|sh))$ { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

说明：`/assets/...` 会由根入口 `index.php` 自动映射到 `public/assets/...`，
所以页面样式和脚本不需要额外配置。目录根部也自带一份 `assets/` 副本
（与 `public/assets/` 内容一致），Web 服务器能直接命中真实文件，两种方式都可用。

### Apache

目录自带 `.htaccess`（含重写规则与敏感文件拦截），确认已启用：

```apache
DocumentRoot /var/www/ecs-panel
<Directory /var/www/ecs-panel>
    AllowOverride All
    Require all granted
</Directory>
```

需要启用 `mod_rewrite`。

## 四、打开站点

浏览器访问站点地址（如 `http://your-domain.com/`），首次会进入“首次初始化”页：

1. 页面显示初始化口令，并附带**部署环境自检**（PHP 版本、扩展、storage 可写、数据库连接）。
2. 有红叉项先解决（常见：缺 `pdo_sqlite` / `openssl` / `curl` / `mbstring`，或 storage 不可写）。
3. 输入口令、设置管理员密码，完成初始化后登录。

## 五、定时任务（必配）

```cron
* * * * * php /var/www/ecs-panel/cron.php run >> /var/log/ecs-panel-cron.log 2>&1
```

可选命令：`php cron.php sync`（实例+流量）、`php cron.php traffic`（仅流量）、`php cron.php guard`（仅熔断）。

## 六、兼容旧入口

`public/` 入口仍然可用（例如 `http://host/ecs/public/`），两种入口共用同一套代码与数据库，不影响。

## 七、Docker 部署（可选）

需要 Docker 与 docker compose（群晖在套件中心安装 Docker 即可）。

```bash
# 1. 把整个项目放到服务器任意目录，例如 /volume1/docker/ecs
cd /volume1/docker/ecs

# 2. 只需一个 data 目录（数据库/密钥/日志都在这），容器内以 www-data 运行
mkdir -p data
chmod -R 777 data

# 3. 构建并启动
docker compose up -d --build
```

- 访问：`http://服务器IP:43211`（首次打开进入初始化页，含环境自检）。
- 改端口：`PORT=80 docker compose up -d`
- 定时调度：容器内置常驻循环，按 `CRON_INTERVAL` 秒执行一次 `php cron.php run`，
  默认 **60（每分钟）**，负责定时任务、自动保活、流量熔断、成本同步；
  不需要打开网页，也不需要配置系统定时任务；想改间隔：`CRON_INTERVAL=300 docker compose up -d`
- 数据持久化：全部在 `./data`（映射为容器内 storage，含 `panel.db` 与 `app.key`），备份/迁移拷贝该目录即可。
- 代码打进镜像（`/var/www/ecs`），宿主机不需要代码目录，只有一个 `data/`。

### Docker 卸载

```bash
cd /volume1/docker/ecs
docker compose down --remove-orphans        # 停止并删除容器（保留 ./data 数据）
```

彻底清理（**会删除数据，谨慎**）：

```bash
cd /volume1/docker/ecs
docker compose down --rmi local --remove-orphans
docker rmi opsdeck-ecs:latest 2>/dev/null || true
rm -rf ./data                              # 删除全部数据（面板/账号/密钥），不可恢复
```

## 八、一条命令自动拉取部署

代码已发布到 GitHub 仓库，在任何装有 Docker 的机器（含群晖）上执行一次：

```bash
REPO_URL=https://github.com/amw1933/ecs.git bash -c "$(curl -fsSL https://raw.githubusercontent.com/amw1933/ecs/main/deploy/install.sh)"
```

脚本会自动：创建部署目录（默认 `/volume1/docker/ecs`，无需手动建）→ 拉取最新代码 →
设置权限 → `docker compose up -d --build` 启动，并打印初始化 token 的查看方式。

**部署完成即自动守护**：容器内置常驻循环，每 `CRON_INTERVAL` 秒（默认 60）自动运行一次调度，
负责自动保活、流量熔断、定时任务与成本同步——不依赖打开网页，也不需要配置系统定时任务。

常用覆盖项（环境变量）：`DEPLOY_DIR` 部署目录、`PORT` 宿主机监听（默认 `0.0.0.0:43211`，局域网可访问；
只想本机访问可设 `127.0.0.1:43211`）、
`CRON_INTERVAL` 调度间隔秒（默认 60）。

以后更新：重新执行同一条命令即可（自动 `git pull` 并重新构建重启容器，数据保留在 `data/`）。

## 常见问题

- **打开是白屏**：看 `storage/logs/app.log` 与 PHP 错误日志；通常是扩展缺失或 storage 不可写（初始化页自检会直接标红）。
- **页面有样式没 JS/样式**：确认 `/assets/` 请求到了根入口（Nginx 已按上面配置 `try_files` 即可）。
- **404**：确认 Web 根指向的是本目录（不是 public/），且 rewrite/try_files 生效。
