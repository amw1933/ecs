<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

/**
 * OpsDeck ECS 面板 配置文件
 * 支持通过环境变量覆盖，方便不同部署环境。
 */

if (!function_exists('opsdeck_env')) {
    function opsdeck_env(string $key, $default = null)
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

return [
    // 面板名称（顶部导航与标题栏显示）
    'app_name'        => opsdeck_env('APP_NAME', 'OpsDeck · CDT 管理保活面板'),

    // 时区
    'timezone'        => opsdeck_env('APP_TIMEZONE', 'Asia/Shanghai'),

    // 站点根路径。若部署在 https://host/panel/ 子目录下，请设为 /panel/
    'base_path'       => opsdeck_env('APP_BASE_PATH', ''),

    // SQLite 数据库文件位置
    'db_path'         => opsdeck_env('DB_PATH', __DIR__ . '/../storage/panel.db'),

    // 密钥：用于加密 AccessKey Secret。
    // 未设置时自动生成并保存在 storage/app.key（更推荐）。
    'app_secret'      => opsdeck_env('APP_SECRET', ''),

    // 首次初始化口令。未设置时自动生成并在部署日志/页面提示。
    'setup_token'     => opsdeck_env('SETUP_TOKEN', ''),

    // Cookie 安全标志（公网 HTTPS 下建议设为 1）
    'cookie_secure'   => opsdeck_env('COOKIE_SECURE', '0') === '1',

    // 调试模式：开启时错误信息会显示在页面上
    'debug'           => opsdeck_env('APP_DEBUG', '1') === '1',

    // 会话名
    'session_name'    => 'opsdeck_sess',

    // 版本号（用于静态资源缓存刷新）
    'version'         => '1.0.8',
];
