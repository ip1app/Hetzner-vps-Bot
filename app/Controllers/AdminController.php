<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Panel;
use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Helpers\Url;
use App\Request;
use App\Response;
use App\Services\ActivityLog;
use App\Services\Admin2fa;
use App\Services\AdminAuth;
use App\Services\AdminPath;
use App\Services\AdminLoginRateLimit;
use App\Services\AdminNotify;
use App\Services\Csrf;
use App\Services\Installed;
use App\Views\AdminUi;

final class AdminController
{
    private function locale(Request $req): string
    {
        return Locale::resolveAdminLocale($req);
    }

    private function guard(): void
    {
        Installed::requireInstalledOrExit();
    }

    private function requireAuth(Request $req): void
    {
        $this->guard();
        if (!AdminAuth::validSession($req->cookie('q8admin'))) {
            Response::redirect(AdminPath::to('/login'));
        }
    }

    public function loginForm(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        if (AdminAuth::validSession($req->cookie('q8admin'))) {
            Response::redirect(AdminPath::to());
        }
        $rate = AdminLoginRateLimit::checkIp($req->ip);
        if ($rate['blocked']) {
            $err = AdminUi::flash($loc, null, I18n::t('admin.login.too_many', $loc, [
                'min' => (string) ($rate['minutes'] ?? 30),
            ]));
        } else {
            $err = isset($req->query['err']) ? AdminUi::flash($loc, null, $req->query['err']) : '';
        }
        if (isset($req->query['ok']) && $req->query['ok'] !== '') {
            $err = AdminUi::flash($loc, $req->query['ok'], null);
        }
        $csrf = Csrf::loginToken();
        Response::cookie('q8lcsrf', $csrf, AdminPath::cookiePath(), 3600);
        $body = '<div class="card login-card">'
            . '<div class="login-card-head"><span class="login-icon" aria-hidden="true">🔐</span>'
            . '<h2>' . Html::esc(I18n::t('admin.login.h1', $loc)) . '</h2></div>'
            . '<p class="sub">' . Html::esc(I18n::t('admin.login.sub', $loc)) . '</p>'
            . '<form method="post" action="' . Html::esc(AdminPath::url('/login')) . '">'
            . '<input type="hidden" name="' . Csrf::FIELD . '" value="' . Html::esc($csrf) . '">'
            . '<label>' . Html::esc(I18n::t('admin.login.user', $loc)) . '</label>'
            . '<input name="user" required autocomplete="username" placeholder="' . Html::esc(I18n::t('admin.login.user', $loc)) . '">'
            . '<label>' . Html::esc(I18n::t('admin.login.pass', $loc)) . '</label>'
            . '<input name="pass" type="password" required autocomplete="current-password" placeholder="••••••••">'
            . '<button type="submit" class="btn">' . Html::esc(I18n::t('admin.login.btn', $loc)) . '</button></form></div>';
        Response::html(AdminUi::loginPage([
            'locale' => $loc,
            'title' => I18n::t('admin.login.title', $loc),
            'body' => $body,
            'flash' => $err,
        ]));
    }

    public function login(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $csrf = (string) ($req->body[Csrf::FIELD] ?? '');
        if (!Csrf::verifyLoginRequest($req->cookie('q8lcsrf'), $csrf)) {
            Response::redirect(AdminPath::to('/login') . '?err=' . rawurlencode(I18n::t('admin.err.csrf', $loc)));
        }
        $ip = $req->ip;
        $user = (string) ($req->body['user'] ?? '');
        $pass = (string) ($req->body['pass'] ?? '');
        $rate = AdminLoginRateLimit::checkIp($ip);
        if ($rate['blocked']) {
            ActivityLog::log('security', 'admin.act.login_blocked', [
                'ip' => $ip,
                'user' => $user !== '' ? $user : '—',
            ]);
            Response::redirect(AdminPath::to('/login') . '?err=' . rawurlencode(I18n::t('admin.login.too_many', $loc, [
                'min' => (string) ($rate['minutes'] ?? 30),
            ])));
        }
        if (!AdminAuth::verifyLogin($user, $pass)) {
            AdminLoginRateLimit::recordFailure($ip);
            ActivityLog::log('security', 'admin.act.login_fail', [
                'ip' => $ip,
                'user' => $user !== '' ? $user : '—',
            ]);
            Response::redirect(AdminPath::to('/login') . '?err=' . rawurlencode(I18n::t('admin.login.err', $loc)));
        }
        AdminLoginRateLimit::clearIp($ip);
        AdminAuth::rehashIfLegacy($pass);
        if (Admin2fa::isEnabled()) {
            $pending = bin2hex(random_bytes(16));
            Admin2fa::storePending($pending);
            Response::cookie('q8a2fa', $pending, AdminPath::cookiePath(), 300);
            Response::cookie('q8lcsrf', '', AdminPath::cookiePath(), -3600);
            Response::redirect(AdminPath::to('/login/2fa'));
        }
        $tok = AdminAuth::rotateSession($req->cookie('q8admin'));
        AdminNotify::adminLogin($user, $ip);
        Response::cookie('q8admin', $tok, AdminPath::cookiePath(), 12 * 3600);
        Response::cookie('q8lcsrf', '', AdminPath::cookiePath(), -3600);
        Response::redirect(AdminPath::to());
    }

    public function login2faForm(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        if (AdminAuth::validSession($req->cookie('q8admin'))) {
            Response::redirect(AdminPath::to());
        }
        $pending = $req->cookie('q8a2fa');
        if ($pending === '' || !Admin2fa::hasPending($pending)) {
            Response::redirect(AdminPath::to('/login'));
        }
        $err = isset($req->query['err']) ? AdminUi::flash($loc, null, $req->query['err']) : '';
        $csrf = Csrf::loginToken();
        Response::cookie('q8lcsrf', $csrf, AdminPath::cookiePath(), 3600);
        $body = '<div class="card login-card">'
            . '<div class="login-card-head"><span class="login-icon" aria-hidden="true">🔑</span>'
            . '<h2>' . Html::esc(I18n::t('admin.login.2fa_h1', $loc)) . '</h2></div>'
            . '<p class="sub">' . Html::esc(I18n::t('admin.login.2fa_sub', $loc)) . '</p>'
            . '<form method="post" action="' . Html::esc(AdminPath::url('/login/2fa')) . '">'
            . '<input type="hidden" name="' . Csrf::FIELD . '" value="' . Html::esc($csrf) . '">'
            . '<label>' . Html::esc(I18n::t('admin.login.2fa_code', $loc)) . '</label>'
            . '<input name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456" class="mono">'
            . '<button type="submit" class="btn">' . Html::esc(I18n::t('admin.login.2fa_btn', $loc)) . '</button></form></div>';
        Response::html(AdminUi::loginPage([
            'locale' => $loc,
            'title' => I18n::t('admin.login.2fa_title', $loc),
            'body' => $body,
            'flash' => $err,
        ]));
    }

    public function login2fa(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $csrf = (string) ($req->body[Csrf::FIELD] ?? '');
        if (!Csrf::verifyLoginRequest($req->cookie('q8lcsrf'), $csrf)) {
            Response::redirect(AdminPath::to('/login/2fa') . '?err=' . rawurlencode(I18n::t('admin.err.csrf', $loc)));
        }
        $pending = $req->cookie('q8a2fa');
        if ($pending === '' || !Admin2fa::hasPending($pending)) {
            Response::cookie('q8a2fa', '', AdminPath::cookiePath(), -3600);
            Response::redirect(AdminPath::to('/login') . '?err=' . rawurlencode(I18n::t('admin.login.2fa_expired', $loc)));
        }
        $ip = $req->ip;
        $rate = AdminLoginRateLimit::checkIp($ip);
        if ($rate['blocked']) {
            Response::redirect(AdminPath::to('/login/2fa') . '?err=' . rawurlencode(I18n::t('admin.login.too_many', $loc, [
                'min' => (string) ($rate['minutes'] ?? 30),
            ])));
        }
        $code = trim((string) ($req->body['code'] ?? ''));
        if (!Admin2fa::verifyLoginCode($code)) {
            AdminLoginRateLimit::recordFailure($ip);
            ActivityLog::log('security', 'admin.act.2fa_fail', ['ip' => $ip]);
            Response::redirect(AdminPath::to('/login/2fa') . '?err=' . rawurlencode(I18n::t('admin.login.2fa_err', $loc)));
        }
        AdminLoginRateLimit::clearIp($ip);
        Admin2fa::consumePending($pending);
        $tok = AdminAuth::rotateSession('');
        AdminNotify::adminLogin(AdminAuth::adminUser(), $ip);
        Response::cookie('q8admin', $tok, AdminPath::cookiePath(), 12 * 3600);
        Response::cookie('q8a2fa', '', AdminPath::cookiePath(), -3600);
        Response::cookie('q8lcsrf', '', AdminPath::cookiePath(), -3600);
        Response::redirect(AdminPath::to());
    }

    public function logout(Request $req, array $params = []): void
    {
        AdminAuth::destroySession($req->cookie('q8admin'));
        Response::cookie('q8admin', '', AdminPath::cookiePath(), -3600);
        Response::redirect(AdminPath::to('/login'));
    }

    public function dashboard(Request $req, array $params = []): void
    {
        $this->requireAuth($req);
        Panel::dashboard($req, $this->locale($req));
    }

    public function dispatch(Request $req, array $params): void
    {
        $this->requireAuth($req);
        $path = trim($params['path'] ?? '', '/');
        Panel::dispatchGet($req, $this->locale($req), $path === '' ? [] : explode('/', $path));
    }

    public function dispatchPost(Request $req, array $params): void
    {
        $this->requireAuth($req);
        Panel::dispatchPost($req, $this->locale($req), trim($params['path'] ?? '', '/'));
    }
}
