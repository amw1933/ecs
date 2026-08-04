<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\PageController;

$page = (string)query('page', 'dashboard');
$action = post('action') !== null ? (string)post('action') : (string)query('action', '');

/* ---------- 未初始化 ---------- */
if (Auth::needsSetup()) {
    if ($page === 'setup' && $action === 'setup') {
        AuthController::setup();
    }
    if ($page === 'setup') {
        AuthController::setupPage();
        exit;
    }
    redirect(url('?page=setup'));
}

/* ---------- 未登录 ---------- */
if (!Auth::check()) {
    if ($page === 'login' && $action === 'login') {
        AuthController::login();
    }
    if ($page === 'login') {
        AuthController::loginPage();
        exit;
    }
    redirect(url('?page=login'));
}

/* ---------- 已登录 ---------- */
if ($action !== '') {
    ApiController::handle($action);
}

switch ($page) {
    case 'instances':
        PageController::instances();
        break;
    case 'instance':
        PageController::instanceDetail();
        break;
    case 'create':
        PageController::createInstance();
        break;
    case 'traffic':
        PageController::traffic();
        break;
    case 'tasks':
        PageController::tasks();
        break;
    case 'accounts':
        PageController::accounts();
        break;
    case 'events':
        PageController::events();
        break;
    case 'settings':
        PageController::settings();
        break;
    case 'logout':
        AuthController::logout();
        break;
    default:
        PageController::dashboard();
}

/* 无系统 cron 时的兜底：登录用户访问页面时，若调度超过 60 秒未运行，则在后台补跑一次。
   PHP-FPM 下先结束响应再执行，不阻塞页面；有系统定时任务时此机制几乎不会触发。 */
if (!is_cli() && function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
    try {
        $last = (int)strtotime((string)\App\Db::setting('scheduler_last_run', ''));
        if (time() - $last >= 60) {
            \App\Services\SchedulerService::run();
        }
    } catch (\Throwable $e) {
        app_log('页面触发调度失败：' . $e->getMessage());
    }
}
