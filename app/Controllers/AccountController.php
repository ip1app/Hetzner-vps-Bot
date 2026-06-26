<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Bot\BotTexts;
use App\Database\Database;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Helpers\Url;
use App\Helpers\WebLayout;
use App\Request;
use App\Response;
use App\Services\ActivityLog;
use App\Services\AdminNotify;
use App\Services\ClientAccounts;
use App\Services\Config;
use App\Services\Csrf;
use App\Services\Installed;
use App\Services\OtpRateLimit;
use App\Services\ProviderManager;
use App\Services\Renewal;
use App\Services\Telegram;
use App\Views\AccountUi;

final class AccountController
{
    private const COOKIE = 'q8c';
    private const COOKIE_PREFIX = 'p:';

    private function locale(Request $req): string
    {
        return Locale::resolveStoreLocale($req);
    }

    private function guard(): void
    {
        Installed::requireInstalledOrExit();
    }

    private function secret(): string
    {
        $material = Config::get('JWT_SECRET');
        if ($material === '') {
            $material = Config::get('SECRET_KEY');
        }
        return hash('sha256', $material . Config::get('TELEGRAM_BOT_TOKEN') . '|q8client');
    }

    private function signSession(string $phone): string
    {
        $key = self::COOKIE_PREFIX . Database::normPhone($phone);
        $exp = time() + 7 * 86400;
        $payload = "$key.$exp";
        $sig = substr(hash_hmac('sha256', $payload, $this->secret()), 0, 32);
        return "$payload.$sig";
    }

    private function sessionPhone(Request $req): ?string
    {
        $tok = $req->cookie(self::COOKIE);
        if ($tok === '') {
            return null;
        }
        $parts = explode('.', $tok);
        if (count($parts) !== 3) {
            return null;
        }
        [$id, $exp, $sig] = $parts;
        $good = substr(hash_hmac('sha256', "$id.$exp", $this->secret()), 0, 32);
        if (!hash_equals($good, $sig) || time() > (int) $exp) {
            return null;
        }
        if (str_starts_with($id, self::COOKIE_PREFIX)) {
            return substr($id, strlen(self::COOKIE_PREFIX));
        }
        $c = Database::getClient($id);
        return $c ? Database::normPhone((string) $c['phone']) : null;
    }

    /** @return list<array<string, mixed>> */
    private function sessionClients(Request $req): array
    {
        $phone = $this->sessionPhone($req);
        if ($phone === null || $phone === '') {
            return [];
        }
        return Database::listClientsByPhone($phone);
    }

    private function isLoggedIn(Request $req): bool
    {
        return $this->sessionPhone($req) !== null && $this->sessionClients($req) !== [];
    }

    /** @return array<string, mixed>|null */
    private function requireClientForSession(Request $req, string $clientId): ?array
    {
        foreach ($this->sessionClients($req) as $c) {
            if (($c['id'] ?? '') === $clientId) {
                return $c;
            }
        }
        return null;
    }

    private function flashFromQuery(Request $req, string $loc): array
    {
        $ok = isset($req->query['ok']) ? I18n::t('account.action.ok', $loc) : null;
        $err = trim((string) ($req->query['err'] ?? ''));
        return [$ok, $err !== '' ? $err : null];
    }

    /** @param array{locale?: string, active?: string, csrf?: string} $opts */
    private function renderPage(Request $req, string $title, string $body, array $opts = []): void
    {
        $loc = $opts['locale'] ?? $this->locale($req);
        $opts['locale'] = $loc;
        $opts['active'] = $opts['active'] ?? 'account';
        if (!isset($opts['csrf'])) {
            $clientTok = $req->cookie(self::COOKIE);
            if ($clientTok !== '' && $this->isLoggedIn($req)) {
                $opts['csrf'] = Csrf::token($clientTok);
            }
        }
        Response::html(WebLayout::layout($title, $body, $opts));
    }

    private function issuePublicCsrf(Request $req): string
    {
        $csrf = Csrf::publicTokenFromCookie($req->cookie(Csrf::PUBLIC_COOKIE));
        Response::cookie(Csrf::PUBLIC_COOKIE, $csrf, Url::cookiePath('/'), 3600);
        return $csrf;
    }

    private function requirePublicCsrf(Request $req, string $loc, string $redirect): void
    {
        $submitted = (string) ($req->body[Csrf::FIELD] ?? '');
        if (!Csrf::verifyPublicRequest($req->cookie(Csrf::PUBLIC_COOKIE), $submitted)) {
            Response::redirect($redirect . '?err=' . rawurlencode(I18n::t('err.csrf', $loc)));
        }
    }

    private function requireClientCsrf(Request $req, string $loc): void
    {
        $tok = $req->cookie(self::COOKIE);
        $submitted = (string) ($req->body[Csrf::FIELD] ?? '');
        if (!Csrf::verify($tok, $submitted)) {
            Response::redirect('/account?err=' . rawurlencode(I18n::t('err.csrf', $loc)));
        }
    }

    /** @param array<string, mixed> $client */
    private function loadServer(array $client): array
    {
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid <= 0) {
            return [null, ''];
        }
        $sp = ProviderManager::forClient($client);
        try {
            return [$sp->getServer($sid, ProviderManager::accountIdForClient($client)), ''];
        } catch (\Throwable $e) {
            return [null, $sp->explain($e)];
        }
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function requireActiveClient(Request $req, ?string $clientId = null): array
    {
        $client = $clientId !== null
            ? $this->requireClientForSession($req, $clientId)
            : ($this->sessionClients($req)[0] ?? null);
        if (!$client) {
            return [null, '/account/login'];
        }
        $loc = $this->locale($req);
        if (Database::isExpired($client) || ($client['active'] ?? true) === false) {
            return [null, '/account/server/' . $client['id'] . '?err=' . rawurlencode(I18n::t('account.server.expired', $loc))];
        }
        if ((int) ($client['server_id'] ?? 0) <= 0) {
            return [null, '/account?err=' . rawurlencode(I18n::t('account.server.no_server', $loc))];
        }
        return [$client, ''];
    }

    /**
     * @param array<string, mixed> $client
     * @return list<array<string, mixed>>
     */
    private function allowedImagesForClient(array $client): array
    {
        $images = ProviderManager::forClient($client)->listImages(ProviderManager::accountIdForClient($client));
        $allowed = BotTexts::allowedOsImages();
        $out = [];
        foreach ($images as $img) {
            $iid = (string) ($img['id'] ?? '');
            $name = (string) ($img['name'] ?? '');
            if ($allowed !== [] && !in_array($name, $allowed, true) && !in_array($iid, $allowed, true)) {
                continue;
            }
            $out[] = $img;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null
     */
    private function findAllowedImage(array $client, int $imageId): ?array
    {
        if ($imageId <= 0) {
            return null;
        }
        foreach ($this->allowedImagesForClient($client) as $img) {
            if ((int) ($img['id'] ?? 0) === $imageId) {
                return $img;
            }
        }
        return null;
    }

    public function loginForm(Request $req, array $params = []): void
    {
        $this->guard();
        if ($this->isLoggedIn($req)) {
            Response::redirect('/account');
        }
        $loc = $this->locale($req);
        $err = trim((string) ($req->query['err'] ?? ''));
        $this->renderPage($req, I18n::t('account.login.title', $loc), AccountUi::loginForm($loc, $err !== '' ? $err : null), [
            'locale' => $loc,
            'active' => 'account',
            'csrf' => $this->issuePublicCsrf($req),
        ]);
    }

    public function login(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $this->requirePublicCsrf($req, $loc, '/account/login');
        $phone = trim((string) ($req->body['phone'] ?? ''));
        $ip = $req->ip;
        $rateIp = OtpRateLimit::checkIp($ip);
        if ($rateIp['blocked']) {
            $this->redirectOtpRateLimit($loc, $phone, (int) ($rateIp['minutes'] ?? 30));
        }
        $ratePhone = OtpRateLimit::checkPhone($phone);
        if ($ratePhone['blocked']) {
            $this->redirectOtpRateLimit($loc, $phone, (int) ($ratePhone['minutes'] ?? 30));
        }
        if (ClientAccounts::byPhone($phone) === []) {
            Response::redirect('/account/login?err=' . rawurlencode(I18n::t('account.err.no_sub', $loc)));
        }
        $tg = ClientAccounts::telegramForPhone($phone);
        if ($tg === '') {
            Response::redirect('/account/login?err=' . rawurlencode(I18n::t('account.err.no_telegram', $loc)));
        }
        $code = (string) random_int(100000, 999999);
        ClientAccounts::setOtpForPhone($phone, $code, time() + 300);
        try {
            Telegram::sendMessage($tg, '🔐 Login code: <code>' . $code . '</code>');
        } catch (\Throwable) {
            Response::redirect('/account/login?err=' . rawurlencode(I18n::t('account.err.bot_offline', $loc)));
        }
        $after = OtpRateLimit::recordPhone($phone);
        OtpRateLimit::recordIp($ip);
        $q = 'phone=' . rawurlencode($phone);
        if ($after['warn']) {
            $q .= '&warn=' . rawurlencode(I18n::t('account.warn.otp_left', $loc, ['n' => (string) $after['left']]));
        }
        Response::redirect('/account/verify?' . $q);
    }

    private function redirectOtpRateLimit(string $loc, string $phone, int $minutes): void
    {
        $err = I18n::t('account.err.otp_rate', $loc, ['min' => (string) max(1, $minutes)]);
        if ($phone !== '') {
            Response::redirect('/account/verify?phone=' . rawurlencode($phone) . '&err=' . rawurlencode($err));
        }
        Response::redirect('/account/login?err=' . rawurlencode($err));
    }

    public function verifyForm(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $phone = trim((string) ($req->query['phone'] ?? ''));
        $errQ = trim((string) ($req->query['err'] ?? ''));
        $err = ($req->query['err'] ?? '') === 'invalid'
            ? I18n::t('account.err.invalid_code', $loc)
            : ($errQ !== '' ? $errQ : null);
        $warn = trim((string) ($req->query['warn'] ?? ''));
        $this->renderPage($req, I18n::t('account.verify.title', $loc), AccountUi::verifyForm($loc, $phone, $err, $warn !== '' ? $warn : null), [
            'locale' => $loc,
            'active' => 'account',
            'csrf' => $this->issuePublicCsrf($req),
        ]);
    }

    public function verify(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $phone = trim((string) ($req->body['phone'] ?? ''));
        $this->requirePublicCsrf($req, $loc, '/account/verify?phone=' . rawurlencode($phone));
        $code = trim((string) ($req->body['code'] ?? ''));
        $ip = $req->ip;
        $ratePhone = OtpRateLimit::checkVerifyPhone($phone);
        if ($ratePhone['blocked']) {
            $this->redirectVerifyRateLimit($loc, $phone, (int) ($ratePhone['minutes'] ?? 30));
        }
        $rateIp = OtpRateLimit::checkVerifyIp($ip);
        if ($rateIp['blocked']) {
            $this->redirectVerifyRateLimit($loc, $phone, (int) ($rateIp['minutes'] ?? 30));
        }
        if (!ClientAccounts::verifyOtpForPhone($phone, $code)) {
            OtpRateLimit::recordVerifyFail($phone, $ip);
            $after = OtpRateLimit::checkVerifyPhone($phone);
            if ($after['blocked']) {
                $this->redirectVerifyRateLimit($loc, $phone, (int) ($after['minutes'] ?? 30));
            }
            Response::redirect('/account/verify?phone=' . rawurlencode($phone) . '&err=invalid');
        }
        OtpRateLimit::clearVerifySuccess($phone);
        Response::cookie(self::COOKIE, $this->signSession($phone), Url::cookiePath('/'), 7 * 86400);
        Response::redirect('/account');
    }

    private function redirectVerifyRateLimit(string $loc, string $phone, int $minutes): void
    {
        $err = I18n::t('account.err.verify_rate', $loc, ['min' => (string) max(1, $minutes)]);
        Response::redirect('/account/verify?phone=' . rawurlencode($phone) . '&err=' . rawurlencode($err));
    }

    public function logout(Request $req, array $params = []): void
    {
        Response::cookie(self::COOKIE, '', Url::cookiePath('/'), -3600);
        Response::redirect('/account/login');
    }

    public function home(Request $req, array $params = []): void
    {
        $this->guard();
        if (!$this->isLoggedIn($req)) {
            Response::redirect('/account/login');
        }
        $loc = $this->locale($req);
        $clients = $this->sessionClients($req);
        [$flashOk, $flashErr] = $this->flashFromQuery($req, $loc);
        $this->renderPage($req, I18n::t('account.products.title', $loc), AccountUi::productsPage($clients, $loc, $flashOk, $flashErr), [
            'locale' => $loc,
            'active' => 'account',
        ]);
    }

    public function dispatch(Request $req, array $params): void
    {
        $path = $params['path'] ?? '';
        if (preg_match('#^server/([a-z0-9]+)$#', $path, $m)) {
            $this->serverHome($req, $m[1]);
        } elseif (preg_match('#^server/([a-z0-9]+)/rebuild$#', $path, $m)) {
            $this->rebuildSelect($req, $m[1]);
        } elseif (preg_match('#^server/([a-z0-9]+)/renew$#', $path, $m)) {
            $this->renewCheckout($req, $m[1]);
        } elseif ($path === 'server') {
            Response::redirect('/account');
        } elseif ($path === 'invoices') {
            $this->invoices($req);
        } else {
            \App\Services\NotFound::store($req);
        }
    }

    public function dispatchPost(Request $req, array $params): void
    {
        $this->guard();
        $loc = $this->locale($req);
        if (!$this->isLoggedIn($req)) {
            Response::redirect('/account/login');
        }
        $this->requireClientCsrf($req, $loc);
        $path = $params['path'] ?? '';
        if (preg_match('#^server/([a-z0-9]+)/(on|off|reboot|pass)$#', $path, $m)) {
            $this->serverAction($req, $m[1], $m[2]);
        } elseif (preg_match('#^server/([a-z0-9]+)/rebuild/confirm$#', $path, $m)) {
            $this->rebuildConfirm($req, $m[1]);
        } elseif (preg_match('#^server/([a-z0-9]+)/rebuild/run$#', $path, $m)) {
            $this->rebuildRun($req, $m[1]);
        }
    }

    private function serverHome(Request $req, string $clientId): void
    {
        $this->guard();
        if (!$this->isLoggedIn($req)) {
            Response::redirect('/account/login');
        }
        $client = $this->requireClientForSession($req, $clientId);
        if (!$client) {
            Response::redirect('/account');
        }
        $loc = $this->locale($req);
        [$server, $serverErr] = $this->loadServer($client);
        [$flashOk, $flashErr] = $this->flashFromQuery($req, $loc);
        $errQ = trim((string) ($req->query['err'] ?? ''));
        if ($errQ !== '') {
            $flashErr = $errQ;
        }
        $this->renderPage(
            $req,
            I18n::t('account.server.title', $loc),
            AccountUi::serverDashboard($this->sessionClients($req), $client, $loc, $server, $serverErr, $flashOk, $flashErr),
            ['locale' => $loc, 'active' => 'account']
        );
    }

    public function renewCheckout(Request $req, ?string $clientId = null): void
    {
        $this->guard();
        if (!$this->isLoggedIn($req)) {
            Response::redirect('/account/login');
        }
        $client = $clientId !== null
            ? $this->requireClientForSession($req, $clientId)
            : ($this->sessionClients($req)[0] ?? null);
        if (!$client) {
            Response::redirect('/account');
        }
        $loc = $this->locale($req);
        $url = Renewal::checkoutUrl($client);
        if ($url === null) {
            Response::redirect('/account/server/' . $client['id'] . '?err=' . rawurlencode(I18n::t('account.renew.no_plan', $loc)));
        }
        Response::redirect($url);
    }

    private function rebuildSelect(Request $req, string $clientId): void
    {
        $this->guard();
        [$client, $redirect] = $this->requireActiveClient($req, $clientId);
        if ($client === null) {
            Response::redirect($redirect);
        }
        $loc = $this->locale($req);
        $err = trim((string) ($req->query['err'] ?? ''));
        try {
            $images = $this->allowedImagesForClient($client);
            if ($images === []) {
                $msg = $err !== '' ? $err : I18n::t('account.rebuild.no_images', $loc);
                $this->renderPage($req, I18n::t('account.rebuild.title', $loc), AccountUi::flash(null, $msg)
                    . '<div class="card"><a class="btn gray" href="' . Url::to('/account/server/' . $client['id']) . '">'
                    . I18n::t('account.rebuild.back', $loc) . '</a></div>', ['locale' => $loc]);
                return;
            }
            $this->renderPage(
                $req,
                I18n::t('account.rebuild.title', $loc),
                AccountUi::rebuildSelectPage($loc, $images, $clientId, $err !== '' ? $err : null),
                ['locale' => $loc]
            );
        } catch (\Throwable $e) {
            $this->renderPage(
                $req,
                I18n::t('account.rebuild.title', $loc),
                AccountUi::rebuildSelectPage($loc, [], $clientId, $err !== '' ? $err : ProviderManager::forClient($client)->explain($e)),
                ['locale' => $loc]
            );
        }
    }

    private function rebuildConfirm(Request $req, string $clientId): void
    {
        $this->guard();
        [$client, $redirect] = $this->requireActiveClient($req, $clientId);
        if ($client === null) {
            Response::redirect($redirect);
        }
        $loc = $this->locale($req);
        $imageId = (int) ($req->body['image_id'] ?? 0);
        $image = $this->findAllowedImage($client, $imageId);
        if ($image === null) {
            Response::redirect('/account/server/' . $clientId . '/rebuild?err=' . rawurlencode(I18n::t('account.rebuild.invalid_image', $loc)));
        }
        $this->renderPage($req, I18n::t('account.rebuild.confirm_title', $loc), AccountUi::rebuildConfirmPage($loc, $image, $clientId), ['locale' => $loc]);
    }

    private function rebuildRun(Request $req, string $clientId): void
    {
        $this->guard();
        [$client, $redirect] = $this->requireActiveClient($req, $clientId);
        if ($client === null) {
            Response::redirect($redirect);
        }
        $loc = $this->locale($req);
        if (!isset($req->body['confirm'])) {
            Response::redirect('/account/server/' . $clientId . '/rebuild?err=' . rawurlencode(I18n::t('account.err.confirm_required', $loc)));
        }
        $imageId = (int) ($req->body['image_id'] ?? 0);
        $image = $this->findAllowedImage($client, $imageId);
        if ($image === null) {
            Response::redirect('/account/server/' . $clientId . '/rebuild?err=' . rawurlencode(I18n::t('account.rebuild.invalid_image', $loc)));
        }
        $sid = (int) ($client['server_id'] ?? 0);
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);
        try {
            $pw = $sp->rebuild($sid, $imageId, $acc);
            AdminNotify::rebuildRequest($client, 'web', (string) ($image['name'] ?? ''));
            ActivityLog::log('server', 'act.web_rebuild', ['name' => $client['name'] ?? '']);
            $this->renderPage($req, I18n::t('account.rebuild.ok_head', $loc), AccountUi::rebuildResultPage($loc, (string) ($client['name'] ?? ''), $pw, $clientId), ['locale' => $loc]);
        } catch (\Throwable $e) {
            Response::redirect('/account/server/' . $clientId . '/rebuild?err=' . rawurlencode($sp->explain($e)));
        }
    }

    private function serverAction(Request $req, string $clientId, string $act): void
    {
        $loc = $this->locale($req);
        $client = $this->requireClientForSession($req, $clientId);
        if (!$client) {
            Response::redirect('/account/login');
        }
        if (Database::isExpired($client) || ($client['active'] ?? true) === false) {
            Response::redirect('/account/server/' . $clientId . '?err=' . rawurlencode(I18n::t('account.server.expired', $loc)));
        }
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid <= 0) {
            Response::redirect('/account/server/' . $clientId . '?err=' . rawurlencode(I18n::t('account.server.no_server', $loc)));
        }
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);
        try {
            if ($act === 'pass') {
                $pw = $sp->resetPassword($sid, $acc);
                AdminNotify::passwordRequest($client, 'web');
                $this->renderPage($req, I18n::t('account.pass.title', $loc), AccountUi::passwordPage($loc, $pw, $clientId), ['locale' => $loc]);
                return;
            }
            match ($act) {
                'on' => $sp->powerOn($sid, $acc),
                'off' => $sp->powerOff($sid, $acc),
                'reboot' => $sp->reboot($sid, $acc),
                default => null,
            };
            Response::redirect('/account/server/' . $clientId . '?ok=1#server');
        } catch (\Throwable $e) {
            Response::redirect('/account/server/' . $clientId . '?err=' . rawurlencode($sp->explain($e)) . '#server');
        }
    }

    private function invoices(Request $req): void
    {
        if (!$this->isLoggedIn($req)) {
            Response::redirect('/account/login');
        }
        $loc = $this->locale($req);
        $cur = Database::getSettings()['currency'] ?? 'USD';
        $invoices = [];
        foreach ($this->sessionClients($req) as $c) {
            foreach (Database::listInvoicesByClient($c['id']) as $inv) {
                $invoices[] = $inv;
            }
        }
        usort($invoices, fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $this->renderPage(
            $req,
            I18n::t('account.invoices.title', $loc),
            AccountUi::invoicesPage($loc, $invoices, $cur, $this->sessionClients($req)),
            ['locale' => $loc]
        );
    }
}
