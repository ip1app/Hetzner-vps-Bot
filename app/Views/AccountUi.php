<?php
declare(strict_types=1);

namespace App\Views;

use App\Database\Database;
use App\Helpers\Datacenter;
use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Url;
use App\Services\ClientAccounts;
use App\Services\Renewal;

final class AccountUi
{
    public static function flash(?string $ok, ?string $err): string
    {
        if ($err !== null && $err !== '') {
            return '<div class="err" role="alert">' . Html::esc($err) . '</div>';
        }
        if ($ok !== null && $ok !== '') {
            return '<div class="ok" role="status">' . Html::esc($ok) . '</div>';
        }
        return '';
    }

    public static function loginForm(string $loc, ?string $err = null): string
    {
        $st = Database::getSettings();
        $tg = trim((string) ($st['telegram_link'] ?? ''));
        $hint = $tg !== ''
            ? '<p class="auth-hint">' . I18n::t('account.login.hint', $loc)
                . ' <a href="' . Html::esc($tg) . '" target="_blank" rel="noopener" class="link">'
                . I18n::t('account.login.open_bot', $loc) . '</a></p>'
            : '<p class="auth-hint">' . I18n::t('account.login.hint', $loc) . '</p>';
        return '<div class="authwrap">'
            . self::flash(null, $err)
            . '<div class="auth">'
            . '<div class="auth-ic" aria-hidden="true">🔐</div>'
            . '<h3>' . Html::esc(I18n::t('account.login.h1', $loc)) . '</h3>'
            . '<p>' . I18n::t('account.login.sub', $loc) . '</p>'
            . $hint
            . '<form method="post" action="' . Url::to('/account/login') . '">'
            . '<div class="field"><label for="acct-phone">' . Html::esc(I18n::t('account.login.phone', $loc)) . '</label>'
            . '<input id="acct-phone" name="phone" dir="ltr" inputmode="tel" autocomplete="tel" required placeholder="+9665xxxxxxxx"></div>'
            . '<button type="submit" class="btn block">' . I18n::t('account.login.send', $loc) . '</button>'
            . '</form></div></div>';
    }

    public static function verifyForm(string $loc, string $phone, ?string $err = null, ?string $warn = null): string
    {
        $warnHtml = ($warn !== null && $warn !== '')
            ? '<div class="warn" role="status">' . Html::esc($warn) . '</div>'
            : '';
        return '<div class="authwrap">'
            . self::flash(null, $err)
            . $warnHtml
            . '<div class="auth">'
            . '<div class="auth-ic" aria-hidden="true">📲</div>'
            . '<h3>' . Html::esc(I18n::t('account.verify.h1', $loc)) . '</h3>'
            . '<p>' . I18n::t('account.verify.sub', $loc) . '</p>'
            . '<form method="post" action="' . Url::to('/account/verify') . '">'
            . '<input type="hidden" name="phone" value="' . Html::esc($phone) . '">'
            . '<div class="field"><label for="acct-code">' . Html::esc(I18n::t('account.verify.code', $loc)) . '</label>'
            . '<input id="acct-code" name="code" inputmode="numeric" autocomplete="one-time-code" required placeholder="123456" class="mono acct-code-input"></div>'
            . '<button type="submit" class="btn block">' . I18n::t('account.verify.btn', $loc) . '</button>'
            . '</form>'
            . '<p class="auth-foot"><a class="link" href="' . Url::to('/account/login') . '">' . I18n::t('account.verify.resend', $loc) . '</a></p>'
            . '</div></div>';
    }

    /**
     * @param list<array<string, mixed>> $clients
     */
    public static function productsPage(array $clients, string $loc, ?string $flashOk = null, ?string $flashErr = null): string
    {
        $primary = $clients[0] ?? [];
        $name = (string) ($primary['name'] ?? '');
        $html = self::flash($flashOk, $flashErr)
            . '<div class="acct">'
            . self::sidebar($clients, $loc, 'products')
            . '<div class="main"><div class="panel">'
            . '<div class="panel-head"><h3>' . I18n::t('account.products.title', $loc) . '</h3></div>'
            . '<p class="sub">' . I18n::t('account.products.sub', $loc, ['name' => $name]) . '</p>'
            . '<div class="acct-products">';

        foreach ($clients as $c) {
            $cid = (string) ($c['id'] ?? '');
            $label = ClientAccounts::labelFor($c, $loc);
            $expired = Database::isExpired($c);
            $suspended = ($c['active'] ?? true) === false;
            $days = Database::daysLeft($c);
            $statusLabel = $suspended
                ? I18n::t('account.status.suspended', $loc)
                : ($expired ? I18n::t('account.status.expired', $loc) : I18n::t('account.status.active', $loc));
            $daysLabel = $days !== null
                ? ($days <= 0 ? I18n::t('account.days_expired', $loc) : I18n::t('account.days_left', $loc, ['n' => max(0, $days)]))
                : '—';
            $sid = (int) ($c['server_id'] ?? 0);
            $renewHref = self::renewHref($c);
            $html .= '<div class="acct-product-card">'
                . '<div class="acct-product-top"><b>' . Html::esc($label) . '</b>'
                . '<span class="status' . (!$expired && !$suspended ? '' : ' off') . '">' . Html::esc($statusLabel) . '</span></div>'
                . '<div class="acct-product-meta">'
                . '<span>' . Html::esc(I18n::t('account.expires', $loc) . ': ' . ($c['expires'] ?? '—')) . '</span>'
                . '<span>' . Html::esc($daysLabel) . '</span>'
                . ($sid > 0 ? '<span class="mono">#' . $sid . '</span>' : '')
                . '</div>'
                . '<div class="acct-product-actions">'
                . '<a class="btn sm" href="' . Url::to('/account/server/' . $cid) . '">' . I18n::t('account.products.manage', $loc) . '</a>'
                . '<a class="btn sm ghost" href="' . Html::esc($renewHref) . '">' . I18n::t('account.btn.renew_now', $loc) . '</a>'
                . '</div></div>';
        }

        return $html . '</div></div></div></div>';
    }

    /**
     * @param list<array<string, mixed>> $allClients
     * @param array<string, mixed> $client
     * @param array<string, mixed>|null $server
     */
    public static function serverDashboard(array $allClients, array $client, string $loc, ?array $server, string $serverErr = '', ?string $flashOk = null, ?string $flashErr = null): string
    {
        $expired = Database::isExpired($client);
        $suspended = ($client['active'] ?? true) === false;
        $days = Database::daysLeft($client);
        $sid = (int) ($client['server_id'] ?? 0);
        $clientId = (string) ($client['id'] ?? '');
        $planLabel = ClientAccounts::labelFor($client, $loc);

        $statusLabel = $suspended
            ? I18n::t('account.status.suspended', $loc)
            : ($expired ? I18n::t('account.status.expired', $loc) : I18n::t('account.status.active', $loc));
        $statusCls = (!$suspended && !$expired) ? 'ok' : '';

        $daysLabel = $days !== null
            ? ($days <= 0
                ? I18n::t('account.days_expired', $loc)
                : I18n::t('account.days_left', $loc, ['n' => max(0, $days)]))
            : '—';

        $renewHref = self::renewHref($client);

        $invoiceCount = 0;
        foreach ($allClients as $c) {
            $invoiceCount += count(Database::listInvoicesByClient((string) ($c['id'] ?? '')));
        }

        $html = self::flash($flashOk, $flashErr);

        if ($expired || $suspended) {
            $html .= '<div class="warn acct-warn-banner" role="alert">' . I18n::t('account.server.expired', $loc) . '</div>';
        }

        $html .= '<div class="acct">'
            . self::sidebar($allClients, $loc, 'server')
            . '<div class="main">'
            . '<div class="panel">'
            . '<div class="panel-head"><h3>' . Html::esc($planLabel) . '</h3></div>'
            . '<div class="stats">'
            . self::stat(I18n::t('account.subscription', $loc), $statusLabel, $statusCls)
            . self::stat(I18n::t('account.expires', $loc), $daysLabel, '')
            . self::stat(I18n::t('account.phone', $loc), (string) ($client['phone'] ?? '—'), 'mono')
            . self::stat(I18n::t('account.invoices.title', $loc), (string) $invoiceCount, '')
            . '</div></div>';

        if ($sid > 0) {
            $html .= self::serverPanel($clientId, $loc, $server, $serverErr, $expired || $suspended);
        } else {
            $html .= '<div class="panel"><p class="sub">' . I18n::t('account.server.no_server', $loc) . '</p></div>';
        }

        $html .= '<div class="panel"><a class="btn block" href="' . Html::esc($renewHref) . '">'
            . I18n::t('account.btn.renew_now', $loc) . '</a></div>';

        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $client */
    private static function renewHref(array $client): string
    {
        $url = Renewal::checkoutUrl($client);
        if ($url !== null) {
            return $url;
        }
        return Url::to('/account/server/' . (string) ($client['id'] ?? '') . '/renew');
    }

    /**
     * @param list<array<string, mixed>> $clients
     */
    private static function sidebar(array $clients, string $loc, string $active): string
    {
        $primary = $clients[0] ?? [];
        $name = (string) ($primary['name'] ?? '');
        $initial = '?';
        if ($name !== '') {
            $initial = function_exists('mb_substr')
                ? mb_strtoupper(mb_substr($name, 0, 1))
                : strtoupper(substr($name, 0, 1));
        }
        if (function_exists('mb_substr') && mb_strlen($name) >= 2) {
            $initial = mb_strtoupper(mb_substr($name, 0, 2));
        }
        $since = substr((string) ($primary['created_at'] ?? ''), 0, 4);
        if ($since === '') {
            $since = '—';
        }
        $productsCls = ($active === 'products' || $active === 'server') ? 'on' : '';
        $invoicesCls = $active === 'invoices' ? 'on' : '';

        $html = '<aside class="aside"><div class="who">'
            . '<div class="ava">' . Html::esc($initial) . '</div>'
            . '<div><b>' . Html::esc($name) . '</b><small>' . I18n::t('account.member_since', $loc, ['year' => $since]) . '</small></div>'
            . '</div><nav class="menu">';
        $html .= '<a class="' . $productsCls . '" href="' . Url::to('/account') . '"><span class="mi">📦</span> '
            . I18n::t('account.products.title', $loc) . '</a>';
        $html .= '<a class="' . $invoicesCls . '" href="' . Url::to('/account/invoices') . '"><span class="mi">🧾</span> '
            . I18n::t('account.btn.invoices', $loc) . '</a>'
            . '<a class="danger" href="' . Url::to('/account/logout') . '"><span class="mi">↩</span> '
            . I18n::t('account.btn.logout', $loc) . '</a>'
            . '</nav></aside>';
        return $html;
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed>|null $server
     * @deprecated use serverDashboard()
     */
    public static function dashboard(array $client, string $loc, ?array $server, string $serverErr = '', ?string $flashOk = null, ?string $flashErr = null): string
    {
        return self::serverDashboard([$client], $client, $loc, $server, $serverErr, $flashOk, $flashErr);
    }

    private static function stat(string $label, string $value, string $extraCls): string
    {
        $vCls = 'v';
        if ($extraCls === 'ok') {
            $vCls .= ' ok';
        } elseif ($extraCls === 'mono') {
            $vCls .= ' mono';
        }
        return '<div class="stat"><div class="l">' . Html::esc($label) . '</div><div class="' . $vCls . '">' . Html::esc($value) . '</div></div>';
    }

    /**
     * @param array<string, mixed>|null $server
     */
    private static function serverPanel(string $clientId, string $loc, ?array $server, string $serverErr, bool $disabled): string
    {
        $html = '<div class="panel" id="server">'
            . '<div class="panel-head">'
            . '<h3>' . I18n::t('account.server.title', $loc) . '</h3>';

        if ($serverErr !== '') {
            return $html . '</div><div class="err" role="alert">' . Html::esc($serverErr) . '</div></div>';
        }
        if ($server === null) {
            return $html . '</div><p class="sub">' . I18n::t('account.server.unavailable', $loc) . '</p></div>';
        }

        $status = (string) ($server['status'] ?? '');
        $statusLabel = self::serverStatusLabel($status, $loc);
        $statusRunning = $status === 'running';
        $ip = (string) ($server['public_net']['ipv4']['ip'] ?? '—');
        $type = (string) ($server['server_type']['name'] ?? '—');
        $dc = Datacenter::countryLabel($server, $loc);
        $os = (string) ($server['image']['description'] ?? $server['image']['name'] ?? '—');

        $html .= '<span class="status' . ($statusRunning ? '' : ' off') . '">' . Html::esc($statusLabel) . '</span></div>'
            . '<div class="info-grid">'
            . self::infoTile(I18n::t('account.server.name', $loc), (string) ($server['name'] ?? '—'), false)
            . self::infoTile(I18n::t('account.server.ip', $loc), $ip, true)
            . self::infoTile(I18n::t('account.server.dc', $loc), $dc, false)
            . self::infoTile(I18n::t('account.server.os', $loc), $os, false)
            . self::infoTile(I18n::t('account.server.specs', $loc), $type, false)
            . '</div>';

        if (!$disabled) {
            $base = '/account/server/' . $clientId;
            $html .= '<div class="controls">'
                . self::controlForm($base . '/on', '▶ ' . I18n::t('account.btn.on', $loc), '', I18n::t('account.confirm.on', $loc))
                . self::controlForm($base . '/off', '⏸ ' . I18n::t('account.btn.off', $loc), 'ghost', I18n::t('account.confirm.off', $loc))
                . self::controlForm($base . '/reboot', '🔄 ' . I18n::t('account.btn.reboot', $loc), 'ghost', I18n::t('account.confirm.reboot', $loc))
                . self::controlForm($base . '/pass', '🔑 ' . I18n::t('account.btn.pass', $loc), 'ghost', I18n::t('account.confirm.pass', $loc))
                . '<a class="btn sm ghost" href="' . Url::to($base . '/rebuild') . '">💿 ' . I18n::t('account.btn.rebuild', $loc) . '</a>'
                . '</div>';
        }

        return $html . '</div>';
    }

    private static function infoTile(string $label, string $value, bool $mono): string
    {
        $vCls = $mono ? 'v mono' : 'v';
        return '<div class="info"><div class="l">' . Html::esc($label) . '</div><div class="' . $vCls . '">' . Html::esc($value) . '</div></div>';
    }

    private static function controlForm(string $action, string $label, string $variant, string $confirm): string
    {
        $cls = 'btn sm' . ($variant !== '' ? ' ' . $variant : '');
        $c = Html::esc($confirm);
        return '<form method="post" action="' . Url::to($action) . '" class="ctrl-form" onsubmit="return confirm(' . "'" . $c . "'" . ')">'
            . '<button type="submit" class="' . $cls . '">' . $label . '</button></form>';
    }

    public static function serverStatusLabel(string $status, string $loc): string
    {
        if ($status === 'running' || $status === 'off') {
            return I18n::t('bot.status.' . $status, $loc);
        }
        $t = I18n::t('bot.status.' . $status, $loc);
        if (!str_starts_with($t, 'bot.status.')) {
            return $t;
        }
        return I18n::t('bot.status.unknown', $loc, ['status' => $status]);
    }

    /**
     * @param list<array<string, mixed>> $images
     */
    public static function rebuildSelectPage(string $loc, array $images, string $clientId, ?string $err = null): string
    {
        $opts = '';
        foreach ($images as $img) {
            $id = (int) ($img['id'] ?? 0);
            $label = trim((string) ($img['name'] ?? '') . ' — ' . (string) ($img['description'] ?? ''));
            $opts .= '<option value="' . $id . '">' . Html::esc($label) . '</option>';
        }
        $back = Url::to('/account/server/' . $clientId);
        return '<div class="acct-inner">'
            . '<div class="panel acct-narrow">'
            . self::pageHeader(I18n::t('account.rebuild.title', $loc), $back, I18n::t('account.rebuild.back', $loc))
            . self::flash(null, $err)
            . '<div class="warn" role="alert">' . I18n::t('account.rebuild.warn', $loc) . '</div>'
            . '<p class="sub">' . I18n::t('account.rebuild.sub', $loc) . '</p>'
            . '<form method="post" action="' . Url::to('/account/server/' . $clientId . '/rebuild/confirm') . '">'
            . '<div class="field"><label for="acct-os">' . Html::esc(I18n::t('account.rebuild.choose', $loc)) . '</label>'
            . '<select id="acct-os" name="image_id" required>' . $opts . '</select></div>'
            . '<button type="submit" class="btn block">' . I18n::t('account.rebuild.continue', $loc) . '</button>'
            . '</form></div></div>';
    }

    /** @param array<string, mixed> $image */
    public static function rebuildConfirmPage(string $loc, array $image, string $clientId): string
    {
        $id = (int) ($image['id'] ?? 0);
        $label = trim((string) ($image['name'] ?? '') . ' — ' . (string) ($image['description'] ?? ''));
        return '<div class="acct-inner">'
            . '<div class="panel acct-narrow">'
            . self::pageHeader(I18n::t('account.rebuild.confirm_title', $loc), Url::to('/account/server/' . $clientId . '/rebuild'), I18n::t('account.rebuild.back', $loc))
            . '<div class="warn" role="alert">' . I18n::t('account.rebuild.warn', $loc) . '</div>'
            . '<p class="sub">' . I18n::t('account.rebuild.confirm_sub', $loc) . '</p>'
            . '<div class="info"><div class="l">' . Html::esc(I18n::t('account.rebuild.selected', $loc)) . '</div><div class="v">' . Html::esc($label) . '</div></div>'
            . '<form method="post" action="' . Url::to('/account/server/' . $clientId . '/rebuild/run') . '" onsubmit="return confirm('
            . "'" . Html::esc(I18n::t('account.rebuild.final_confirm', $loc)) . "'" . ')">'
            . '<input type="hidden" name="image_id" value="' . $id . '">'
            . '<label class="acct-check"><input type="checkbox" name="confirm" value="1" required> '
            . I18n::t('account.rebuild.confirm_check', $loc) . '</label>'
            . '<button type="submit" class="btn red block">' . I18n::t('account.rebuild.btn', $loc) . '</button>'
            . '</form></div></div>';
    }

    public static function rebuildResultPage(string $loc, string $clientName, string $password, string $clientId): string
    {
        return '<div class="acct-inner"><div class="panel acct-narrow">'
            . '<h1>' . I18n::t('account.rebuild.ok_head', $loc) . '</h1>'
            . '<p class="sub">' . I18n::t('account.rebuild.ok_sub', $loc, ['name' => $clientName]) . '</p>'
            . '<div class="warn" role="alert">' . I18n::t('account.pass.warn', $loc) . '</div>'
            . '<p class="sub">' . I18n::t('account.rebuild.pw_label', $loc) . '</p>'
            . '<div class="acct-pass-box mono" tabindex="0">' . Html::esc($password) . '</div>'
            . '<a class="btn block" href="' . Url::to('/account/server/' . $clientId) . '">' . I18n::t('account.rebuild.back_account', $loc) . '</a>'
            . '</div></div>';
    }

    public static function passwordPage(string $loc, string $password, string $clientId): string
    {
        return '<div class="acct-inner"><div class="panel acct-narrow">'
            . '<h1>' . I18n::t('account.pass.title', $loc) . '</h1>'
            . '<div class="warn" role="alert">' . I18n::t('account.pass.warn', $loc) . '</div>'
            . '<div class="acct-pass-box mono" tabindex="0">' . Html::esc($password) . '</div>'
            . '<a class="btn block" href="' . Url::to('/account/server/' . $clientId) . '">' . I18n::t('account.pass.back', $loc) . '</a>'
            . '</div></div>';
    }

    /**
     * @param list<array<string, mixed>> $invoices
     * @param list<array<string, mixed>> $clients
     */
    public static function invoicesPage(string $loc, array $invoices, string $currency, array $clients = []): string
    {
        $back = Url::to('/account');
        $html = '<div class="acct">'
            . ($clients !== [] ? self::sidebar($clients, $loc, 'invoices') : '')
            . '<div class="main"><div class="panel">'
            . self::pageHeader(I18n::t('account.invoices.title', $loc), $back, I18n::t('account.server.back', $loc));

        if ($invoices === []) {
            return $html . '<p class="sub">' . I18n::t('account.invoices.none', $loc) . '</p></div></div></div>';
        }

        foreach ($invoices as $inv) {
            $paid = !empty($inv['paid']);
            $paidBadge = $paid
                ? '<span class="paid">' . I18n::t('account.invoices.paid', $loc) . '</span>'
                : '<span class="badge b-exp">' . I18n::t('account.invoices.unpaid', $loc) . '</span>';
            $html .= '<div class="inv">'
                . '<div class="ii">📄</div>'
                . '<div><b>' . Html::esc((string) ($inv['description'] ?? $inv['number'] ?? '')) . '</b>'
                . '<small>' . Html::esc(substr((string) ($inv['created_at'] ?? ''), 0, 10)) . '</small></div>'
                . '<div class="amt"><b>' . Html::esc(number_format((float) ($inv['amount'] ?? 0), 3) . ' ' . $currency) . '</b><br>'
                . $paidBadge . '</div></div>';
        }
        return $html . '</div></div></div>';
    }

    private static function pageHeader(string $title, string $backHref, string $backLabel): string
    {
        return '<div class="panel-head">'
            . '<h3>' . Html::esc($title) . '</h3>'
            . '<a class="btn sm ghost" href="' . Html::esc($backHref) . '">' . Html::esc($backLabel) . '</a>'
            . '</div>';
    }
}
