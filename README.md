# OpsDeck · CDT 管理保活面板

一个用纯 PHP 部署的阿里云 CDT 管理保活面板。主题、布局、网格与前端实现均为独立设计
（深色操作台风格 + 顶部导航 + 12 栏网格），后端为从零编写的 PHP 实现，不依赖任何 Composer 包。

## 一条命令自动拉取部署

任何装有 Docker 的机器（含群晖 NAS）上执行一次即可完成部署，无需手动创建目录：

```bash
REPO_URL=https://github.com/amw1933/ecs.git bash -c "$(curl -fsSL https://raw.githubusercontent.com/amw1933/ecs/main/deploy/install.sh)"
```

脚本会自动：创建部署目录（默认 `/volume1/docker/ecs`）→ 拉取最新代码 → 修正权限 →
启动服务（默认监听 `0.0.0.0:43211`），并提示初始化 token 的查看方式。

**部署完成即自动守护**：内置调度容器每 `CRON_INTERVAL` 秒（默认 60）自动运行一次调度，
抢占实例被停止后自动拉起并通知——不需要打开网页，也不需要配置任何系统定时任务。

可选环境变量：`DEPLOY_DIR`（部署目录）、`PORT`（监听地址，如 `0.0.0.0:8080`）、
`CRON_INTERVAL`（调度间隔秒，默认 3600）。以后更新重跑同一条命令即可，数据保存在 `storage/`。

完整部署文档见 [DEPLOY.md](DEPLOY.md)。

## 功能

- **实例管理**：查看 ECS 状态、规格、公网/私网 IP、计费方式、到期时间；启动、停止、重启、释放；引导式创建实例（含抢占式、数据盘、密钥/密码）。
- **流量监控**：账号级以 CDT（云数据传输）出网流量为准（覆盖共享带宽包/共享流量包等计费口径），
  实例级基于云监控（CMS）公网出入指标积分，账号用量取两者较大值，30 天趋势曲线；
  多账号完全隔离，各自独立计费周期、独立熔断配置，总览按账号分别展示卡片。
- **自动熔断**：账号级 / 实例级流量阈值，达到后自动关机并通知，带冷却时间与月度预警。
- **定时任务**：cron 表达式驱动的定时开机 / 关机；抢占式实例保活（被回收后按保存的重建参数自动重建）。
- **抢占式保活通知**：实例被抢占释放后自动重建、检测到已停止时自动拉起，开启/关闭均通过通知渠道推送。
- **自动保活开关**：新添加的真实账号默认开启（可在设置 → 账号设置关闭），无需创建保活任务，调度每轮自动检查该账号下所有抢占式实例（有重建参数自动重建，无参数停止拉起/释放提醒）。
- **每月 1 号自动开机**：实例级开关，每月 1 号调度运行自动启动并通知。
- **成本分析（费用中心）**：账号级开关，同步当月费用与账户余额（BSS OpenAPI），总览卡片与账号列表展示。
- **通知渠道**：Telegram、Server酱、Webhook、SMTP 邮件，可测试；支持配置 HTTP(S) 代理（Telegram 等国外接口在受限网络下需要）。
- **账号管理**：多阿里云 RAM 账号，AccessKey Secret 使用 AES-256-GCM 加密落盘。
- **事件中心**：账号、实例、熔断、任务操作全记录。
- **演示模式**：无需密钥即可完整体验面板（内置演示账号与确定性流量数据）。

> 流量口径说明：
> - 账号流量使用 CDT OpenAPI `ListCdtInternetTraffic`（cdt.aliyuncs.com，版本 2021-08-13），
>   返回当前账单周期的账号级公网出网流量累计值，能覆盖共享带宽包（cbwp）、共享流量包等
>   实例监控统计不到的计费口径；该接口暂不支持按天拆分，面板按增量落库以还原日趋势。
> - 实例流量使用云监控（CMS）`acs_ecs_dashboard` / `acs_vpc_eip` 出入指标积分。
> - 账号用量 = max(CDT 总量, 实例汇总)，哪个用的多算哪个；熔断保护同样基于该口径。
> - 阿里云监控数据存在延迟，自动关机不能保证绝对不产生费用，请合理设置阈值。

## 环境要求

- PHP >= 8.1（CLI 与 FPM 均可）
- 扩展：`pdo_sqlite`、`openssl`、`curl`（或 `allow_url_fopen`）、`mbstring`、`json`
- 部署目录：将 Web 根目录指向 `public/`，`storage/` 与 `app/` 置于 Web 根之外

## 快速开始

```bash
# 开发环境（PHP 内置服务器）
php -S 127.0.0.1:43210 -t public

# Windows 也可以直接运行
./start-dev.ps1
# Linux / macOS
./start-dev.sh
```

打开 `http://127.0.0.1:43210`：

1. 页面显示初始化口令（首次运行生成，也可通过环境变量 `SETUP_TOKEN` 预设）。
2. 输入口令并设置管理员密码，完成初始化后登录。
3. 进入「账号」添加阿里云 RAM 账号，或勾选"演示账号"免密钥体验全部功能。

> **零配置部署**：也可以把 Web 根直接指向项目目录（不指向 `public/`），打开站点即可，
> 根入口 `index.php` 会自动处理 `/assets/` 静态资源。详见 [DEPLOY.md](DEPLOY.md)。

> **Docker 部署**：项目自带 `docker-compose.yml`（nginx + PHP-FPM + 内置定时调度容器），
> `docker compose up -d --build` 一条命令启动，详见 [DEPLOY.md](DEPLOY.md)。

## 演示模式

在「账号」页添加账号时勾选"演示账号"即可。演示账号无需密钥，实例状态持久化在本机
数据库，流量按日期确定性生成，可完整测试实例操作、流量曲线、熔断、任务等全部功能。

## 部署（Nginx / Apache）

### Nginx（目录即 Web 根，推荐）

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/ecs-panel;          # 目录本身，不是 public/
    index index.php;

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

`/assets/` 会由根入口自动映射到 `public/assets/`，无需额外配置。

### Apache

```apache
DocumentRoot /var/www/ecs-panel/public
<Directory /var/www/ecs-panel/public>
    AllowOverride All
    Require all granted
</Directory>
```

如果站点部署在子目录（例如 `/panel/`），设置环境变量 `APP_BASE_PATH=/panel/`，
并把静态资源路径相应调整。

### 权限

确保 PHP 进程对 `storage/` 目录可写：

```bash
chown -R www-data:www-data storage
chmod -R 750 storage
```

## 定时任务

每分钟执行一次调度（定时任务 + 流量熔断检查）：

```cron
* * * * * php /var/www/ecs-panel/cron.php run >> /var/log/ecs-panel-cron.log 2>&1
```

可选命令：

```bash
php cron.php sync      # 同步实例缓存与流量
php cron.php traffic   # 仅同步流量
php cron.php guard     # 仅执行熔断检查
```

界面「设置 → 维护」里也可以手动触发调度，方便没有 cron 的环境。

## 阿里云 RAM 权限

建议创建独立 RAM 子账号，按需授予权限：

- 查看实例：`ecs:DescribeInstances`
- 操作实例：`ecs:StartInstance`、`ecs:StopInstance`、`ecs:RebootInstance`、`ecs:DeleteInstance`
- 创建实例：`ecs:CreateInstance`、`ecs:AllocatePublicIpAddress`、`ecs:DescribeInstanceTypes`、
  `ecs:DescribeImages`、`ecs:DescribeSecurityGroups`、`ecs:DescribeVSwitches`、`ecs:DescribeZones`、`ecs:DescribeRegions`
- 流量监控：`cms:DescribeMetricList`、`cms:DescribeMetricLast`
- CDT 账号流量：`cdt:ListCdtInternetTraffic`
- 成本分析：`bss:DescribeInstanceBill`、`bss:QueryAccountBalance`

只查看和操作已有实例时，不需要创建类权限。

## 配置项（环境变量）

| 变量 | 说明 | 默认 |
|---|---|---|
| `APP_NAME` | 面板名称 | OpsDeck · CDT 管理保活面板 |
| `APP_TIMEZONE` | 时区 | Asia/Shanghai |
| `APP_BASE_PATH` | 子目录部署路径（默认自动识别，如 `/ecs/public/` 下运行无需设置） | （自动） |
| `DB_PATH` | SQLite 数据库路径 | storage/panel.db |
| `APP_SECRET` | 密钥（32 字节，base64 或原文；不设置则自动生成到 storage/app.key） | （自动） |
| `SETUP_TOKEN` | 初始化口令 | （自动生成） |
| `COOKIE_SECURE` | HTTPS 下设为 1 | 0 |
| `APP_DEBUG` | 调试模式 | 1 |

## 目录结构

```text
ecs-panel/
├── public/           # Web 根目录（唯一对外暴露的目录）
│   ├── index.php     # 前端控制器
│   └── assets/       # 样式与脚本
├── app/              # 业务代码（控制器、服务、视图）
├── storage/          # 数据库、日志、缓存、密钥（需可写）
├── cron.php          # 调度入口
├── start-dev.ps1     # Windows 开发启动
└── start-dev.sh      # Linux/macOS 开发启动
```

## 安全提醒

- 不要把 AccessKey、初始化口令或自动生成的密码发给任何人。
- 公网部署请务必使用 HTTPS，建议通过反代（Nginx/Caddy）提供 TLS，不要直接暴露端口。
- 项目自带 `.htaccess`（根目录、`app/`、`storage/`），可阻止通过网址直接访问
  应用代码、数据库与密钥文件；请确认服务器 Apache 已启用 `AllowOverride`（`phpStudy` 默认开启）。
- 请使用 RAM 子账号，不要使用阿里云主账号密钥。
- 定期备份 `storage/panel.db`（含账号密钥密文，配合 `storage/app.key` 才能解密）。
- 自动关机是成本保护手段而非绝对保证，监控数据延迟可能导致少量流量溢出。

## License

MIT。本面板代码为独立实现。
