<?php
declare(strict_types=1);
namespace App\Controllers;
if (!defined('ROOT_PATH')) { http_response_code(403); exit('Forbidden'); }

use App\Auth;

final class AuthController
{
    public static function setupPage(): void
    {
        if (!Auth::needsSetup()) {
            redirect(url('?page=dashboard'));
        }
        render_page('setup', [
            'page' => 'setup',
            'pageTitle' => '初始化',
            'setupToken' => Auth::setupToken(),
        ], 'standalone');
    }

    public static function setup(): void
    {
        csrf_check();
        if (!Auth::needsSetup()) {
            redirect(url('?page=dashboard'));
        }
        [$ok, $msg] = Auth::doSetup(
            (string)post('token', ''),
            (string)post('username', 'admin'),
            (string)post('password', '')
        );
        if ($ok) {
            log_event('auth', 'success', '面板初始化完成', '管理员账号已创建');
            flash('初始化完成，请登录');
            redirect(url('?page=login'));
        }
        flash($msg, 'err');
        redirect(url('?page=setup'));
    }

    public static function loginPage(): void
    {
        if (Auth::needsSetup()) {
            redirect(url('?page=setup'));
        }
        if (Auth::check()) {
            redirect(url('?page=dashboard'));
        }
        render_page('login', [
            'page' => 'login',
            'pageTitle' => '登录',
        ], 'standalone');
    }

    public static function login(): void
    {
        csrf_check();
        [$ok, $msg] = Auth::attemptLogin(
            (string)post('username', ''),
            (string)post('password', '')
        );
        if ($ok) {
            log_event('auth', 'info', '登录成功', 'IP：' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            redirect(url('?page=dashboard'));
        }
        log_event('auth', 'warn', '登录失败', 'IP：' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        flash($msg, 'err');
        redirect(url('?page=login'));
    }

    public static function logout(): void
    {
        Auth::logout();
        redirect(url('?page=login'));
    }
}
