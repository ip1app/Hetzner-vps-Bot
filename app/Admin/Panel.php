<?php
declare(strict_types=1);

namespace App\Admin;

use App\Bot\BotCommands;
use App\Bot\BotSetup;
use App\Bot\BotTexts;
use App\Controllers\StoreController;
use App\Database\Database;
use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Str;
use App\Helpers\Url;
use App\Request;
use App\Response;
use App\Services\ActivityLog;
use App\Services\AdminPath;
use App\Services\AdminAuth;
use App\Services\BrandTheme;
use App\Services\ClientAccounts;
use App\Services\Config;
use App\Services\Csrf;
use App\Services\EnvFile;
use App\Services\Finance;
use App\Services\ProviderManager;
use App\Services\PayPal;
use App\Services\InstallCleanup;
use App\Services\IntegrityGuard;
use App\Services\NotFound;
use App\Services\StockAlert;
use App\Services\Telegram;
use App\Services\UpdateCheck;
use App\Views\AdminUi;
use App\Views\InvoiceUi;

final class Panel
{
    private static function t(string $key, string $loc, array $vars = []): string
    {
        return I18n::t($key, $loc, $vars);
    }

    private static function e(mixed $v): string
    {
        return Html::esc($v);
    }

    private static function flashFromQuery(Request $req, string $loc): string
    {
        if (!empty($req->query['err'])) {
            return AdminUi::flash($loc, null, $req->query['err']);
        }
        $ok = $req->query['ok'] ?? '';
        if ($ok === '') {
            return '';
        }
        $vars = [];
        foreach ($req->query as $k => $v) {
            if (str_starts_with($k, 'ok_')) {
                $vars[substr($k, 3)] = $v;
            }
        }
        return '<div class="ok">' . self::e(self::t($ok, $loc, $vars)) . '</div>';
    }

    /** @param array<string, mixed> $pageOpts */
    private static function render(Request $req, string $loc, string $title, string $body, string $active = '', array $pageOpts = []): void
    {
        $st = Database::getSettings();
        $opts = array_merge([
            'locale' => $loc,
            'title' => $title,
            'body' => $body,
            'active' => $active,
            'flash' => self::flashFromQuery($req, $loc),
            'storeName' => (string) ($st['store_name'] ?? ''),
            'brandCss' => BrandTheme::cssOverrides($st),
            'brandBg' => BrandTheme::bg($st),
            'brandLogoUrl' => BrandTheme::logoUrl($st),
            'csrf' => Csrf::token($req->cookie('q8admin')),
            'header' => '',
        ], $pageOpts);
        $opts['header'] = ($opts['header'] ?? '') . AdminUi::installSecurityBanner($loc) . IntegrityGuard::adminBannerHtml($loc);
        Response::html(AdminUi::page($opts));
    }

    /** @param array<string, string|int|float> $vars */
    private static function requireCsrf(Request $req, string $loc): void
    {
        $tok = $req->cookie('q8admin');
        $submitted = (string) ($req->body[Csrf::FIELD] ?? '');
        if (!Csrf::verify($tok, $submitted)) {
            Response::redirect(AdminPath::to() . '?err=' . rawurlencode(self::t('admin.err.csrf', $loc)));
        }
    }

    private static function telegramTextsFormHtml(string $loc, array $botTexts): string
    {
        $form = '<div class="card" id="bot-texts"><h2>' . self::e(self::t('admin.telegram.texts_head', $loc)) . '</h2><p class="sub">'
            . self::t('admin.telegram.texts_sub', $loc) . '</p><form method="post" action="' . AdminPath::url('/telegram/texts') . '">';
        foreach (BotTexts::textSections() as $section => $keys) {
            $sectionLbl = self::t('bot.text.section.' . $section, $loc);
            $form .= '<details class="admin-panel" style="margin-top:12px"><summary>' . self::e($sectionLbl)
                . ' <span class="inv-count">(' . count($keys) . ')</span></summary><div class="panel-body">';
            foreach ($keys as $key) {
                $lbl = BotTexts::label($key, $loc);
                $defEn = BotTexts::defaultText($key, 'en');
                $defAr = BotTexts::defaultText($key, 'ar');
                $valEn = trim((string) ($botTexts[$key]['en'] ?? ''));
                $valAr = trim((string) ($botTexts[$key]['ar'] ?? ''));
                $form .= '<div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--adm-border)">'
                    . '<p class="sub" style="font-weight:600;margin-bottom:8px">' . self::e($lbl) . '</p>'
                    . '<label>EN</label><textarea name="text_' . self::e($key) . '_en" rows="3">'
                    . self::e($valEn !== '' ? $valEn : $defEn) . '</textarea>'
                    . '<label>AR</label><textarea name="text_' . self::e($key) . '_ar" dir="rtl" rows="3">'
                    . self::e($valAr !== '' ? $valAr : $defAr) . '</textarea></div>';
            }
            $form .= '</div></details>';
        }
        return $form . '<div class="row" style="margin-top:16px"><button class="btn">' . self::e(self::t('admin.telegram.texts_save', $loc))
            . '</button></div></form>'
            . '<form method="post" action="' . AdminPath::url('/telegram/texts-reset') . '" style="margin-top:10px" onsubmit="return confirm('
            . "'" . self::e(self::t('admin.telegram.texts_reset_confirm', $loc)) . "'" . ')"><button class="btn gray">'
            . self::e(self::t('admin.telegram.texts_reset', $loc)) . '</button></form></div>';
    }

    /** @param array<string, mixed> $st */
    private static function telegramButtonsFormHtml(Request $req, string $loc, array $st): string
    {
        $botBtns = $st['bot_buttons'] ?? [];
        $adminBtns = $st['bot_admin_buttons'] ?? [];
        $form = '<div class="card"><h2>' . self::e(self::t('admin.telegram.buttons_head', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('admin.telegram.buttons_sub', $loc)) . '</p><form method="post" action="' . AdminPath::url('/telegram/buttons') . '">'
            . '<label>' . self::e(self::t('admin.telegram.store_url', $loc)) . '</label><input name="store_url" value="' . self::e($st['store_url'] ?? $req->baseUrl()) . '">'
            . '<p class="sub">' . self::e(self::t('admin.telegram.store_url_hint', $loc)) . '</p>'
            . '<h3 style="font-size:15px;margin:18px 0 8px">' . self::e(self::t('admin.telegram.client_buttons_head', $loc)) . '</h3>'
            . '<table><tr><th>' . self::e(self::t('admin.telegram.buttons_enabled', $loc)) . '</th><th>'
            . self::e(self::t('admin.telegram.buttons_key', $loc)) . '</th><th>EN</th><th>AR</th></tr>';
        foreach (BotTexts::CLIENT_BTN_KEYS as $key) {
            $defEn = BotTexts::defaultClientBtn($key, 'en');
            $defAr = BotTexts::defaultClientBtn($key, 'ar');
            $en = trim((string) ($botBtns[$key]['en'] ?? ''));
            $ar = trim((string) ($botBtns[$key]['ar'] ?? ''));
            $on = ($botBtns[$key]['enabled'] ?? true) !== false;
            $lblKey = $key === 'language' ? 'bot.btn.language' : 'bot.btn.lbl.' . $key;
            $enabledCell = $key === 'language'
                ? '—'
                : '<input type="checkbox" name="btn_' . self::e($key) . '_enabled" ' . ($on ? 'checked' : '') . '>';
            $form .= '<tr><td>' . $enabledCell . '</td><td>' . self::e(self::t($lblKey, $loc)) . '</td>'
                . '<td><input name="btn_' . self::e($key) . '_en" value="' . self::e($en !== '' ? $en : $defEn) . '"></td>'
                . '<td><input name="btn_' . self::e($key) . '_ar" value="' . self::e($ar !== '' ? $ar : $defAr) . '" dir="rtl"></td></tr>';
        }
        $form .= '</table>'
            . '<h3 style="font-size:15px;margin:18px 0 8px">' . self::e(self::t('admin.telegram.admin_buttons_head', $loc)) . '</h3>'
            . '<table><tr><th>' . self::e(self::t('admin.telegram.buttons_key', $loc)) . '</th><th>EN</th><th>AR</th></tr>';
        foreach (BotTexts::ADMIN_BTN_KEYS as $key) {
            $defEn = BotTexts::defaultAdminBtn($key, 'en');
            $defAr = BotTexts::defaultAdminBtn($key, 'ar');
            $en = trim((string) ($adminBtns[$key]['en'] ?? ''));
            $ar = trim((string) ($adminBtns[$key]['ar'] ?? ''));
            $form .= '<tr><td>' . self::e(self::t('bot.admin.btn.' . $key, $loc)) . '</td>'
                . '<td><input name="admin_btn_' . self::e($key) . '_en" value="' . self::e($en !== '' ? $en : $defEn) . '"></td>'
                . '<td><input name="admin_btn_' . self::e($key) . '_ar" value="' . self::e($ar !== '' ? $ar : $defAr) . '" dir="rtl"></td></tr>';
        }
        return $form . '</table><button class="btn">' . self::e(self::t('admin.telegram.buttons_save', $loc)) . '</button></form></div>';
    }

    private static function mapAdminPath(string $url): string
    {
        if ($url === '/admin') {
            return AdminPath::to();
        }
        if (str_starts_with($url, '/admin?')) {
            return AdminPath::to() . substr($url, 6);
        }
        if (str_starts_with($url, '/admin/')) {
            return AdminPath::to(substr($url, 6));
        }
        return $url;
    }

    private static function go(string $url): never
    {
        Response::redirect(self::mapAdminPath($url));
    }

    private static function redirectOk(string $url, string $okKey, array $vars = []): never
    {
        $url = self::mapAdminPath($url);
        $sep = str_contains($url, '?') ? '&' : '?';
        $q = 'ok=' . rawurlencode($okKey);
        foreach ($vars as $k => $v) {
            $q .= '&ok_' . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        Response::redirect($url . $sep . $q);
    }

    private static function redirectErr(string $url, string $loc, string $errKey, array $vars = []): never
    {
        $url = self::mapAdminPath($url);
        Response::redirect($url . (str_contains($url, '?') ? '&' : '?') . 'err=' . rawurlencode(self::t($errKey, $loc, $vars)));
    }

    private static function redirectAddClientErr(string $loc, string $errKey, array $vars = []): never
    {
        self::redirectErr('/admin/clients?add=1', $loc, $errKey, $vars);
    }

    /** @return array<int, true> */
    private static function assignedServerIds(): array
    {
        $ids = [];
        foreach (Database::listClients() as $c) {
            $sid = (int) ($c['server_id'] ?? 0);
            if ($sid > 0) {
                $ids[$sid] = true;
            }
        }
        return $ids;
    }

    /** @return array<int, array<string, mixed>> */
    private static function inventoryByServerId(): array
    {
        $map = [];
        foreach (Database::listInventory() as $item) {
            $sid = (int) ($item['server_id'] ?? 0);
            if ($sid > 0) {
                $map[$sid] = $item;
            }
        }
        return $map;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function assignableStockByProduct(?string $exceptClientId = null): array
    {
        $assigned = self::assignedServerIds();
        if ($exceptClientId !== null && $exceptClientId !== '') {
            $own = Database::getClient($exceptClientId);
            if ($own) {
                $ownSid = (int) ($own['server_id'] ?? 0);
                if ($ownSid > 0) {
                    unset($assigned[$ownSid]);
                }
            }
        }
        $groups = [];
        foreach (Database::listInventory() as $item) {
            $sid = (int) ($item['server_id'] ?? 0);
            if ($sid <= 0 || isset($assigned[$sid])) {
                continue;
            }
            $pid = (string) ($item['product_id'] ?? '');
            $product = Database::getProduct($pid);
            $groups[$pid][] = [
                'inventory_id' => $item['id'],
                'server_id' => $sid,
                'hetzner_account_id' => (string) ($item['hetzner_account_id'] ?? ''),
                'note' => (string) ($item['note'] ?? ''),
                'product_name' => (string) ($product['name'] ?? '—'),
            ];
        }
        return $groups;
    }

    private static function productLabelForServer(int $serverId): string
    {
        if ($serverId <= 0) {
            return '';
        }
        foreach (Database::listInventory() as $item) {
            if ((int) ($item['server_id'] ?? 0) === $serverId) {
                $p = Database::getProduct((string) ($item['product_id'] ?? ''));
                return (string) ($p['name'] ?? '');
            }
        }
        return '';
    }

    /** @param array<string, mixed>|null $client */
    private static function clientStockSelectHtml(string $loc, ?array $client = null): string
    {
        $currentSid = (int) ($client['server_id'] ?? 0);
        $selected = '';
        if ($currentSid > 0) {
            foreach (Database::listInventory() as $item) {
                if ((int) ($item['server_id'] ?? 0) === $currentSid) {
                    $selected = 'inv:' . ($item['id'] ?? '');
                    break;
                }
            }
            if ($selected === '') {
                $selected = 'manual';
            }
        }

        $html = '<option value="">' . self::e(self::t('admin.clients.stock_none', $loc)) . '</option>';
        $groups = self::assignableStockByProduct($client['id'] ?? null);
        foreach ($groups as $items) {
            $pname = $items[0]['product_name'] ?? '—';
            $html .= '<optgroup label="' . self::e($pname) . '">';
            foreach ($items as $it) {
                $val = 'inv:' . $it['inventory_id'];
                $sel = $val === $selected ? ' selected' : '';
                $label = '#' . $it['server_id'];
                if ($it['note'] !== '') {
                    $label .= ' — ' . $it['note'];
                }
                $html .= '<option value="' . self::e($val) . '"' . $sel . '>' . self::e($label) . '</option>';
            }
            $html .= '</optgroup>';
        }
        if ($groups === []) {
            $html .= '<option value="" disabled>' . self::e(self::t('admin.clients.stock_empty', $loc)) . '</option>';
        }
        $manualSel = $selected === 'manual' ? ' selected' : '';
        $html .= '<option value="manual"' . $manualSel . '>' . self::e(self::t('admin.clients.stock_manual', $loc)) . '</option>';
        return $html;
    }

    /** @return array{server_id: int, hetzner_account_id: string} */
    private static function parseClientStockInput(Request $req, string $loc, ?string $exceptClientId = null): array
    {
        $stock = trim((string) ($req->body['stock'] ?? ''));
        $manualSid = (int) ($req->body['server_id_manual'] ?? 0);
        if ($stock === 'manual' || ($stock === '' && $manualSid > 0)) {
            if ($manualSid <= 0) {
                return ['server_id' => 0, 'hetzner_account_id' => ''];
            }
            $acc = trim((string) ($req->body['hetzner_account_id_manual'] ?? ''));
            $assigned = self::assignedServerIds();
            if (isset($assigned[$manualSid])) {
                $owner = null;
                foreach (Database::listClients() as $c) {
                    if ((int) ($c['server_id'] ?? 0) === $manualSid && ($exceptClientId === null || $c['id'] !== $exceptClientId)) {
                        $owner = $c;
                        break;
                    }
                }
                if ($owner) {
                    throw new \RuntimeException(self::t('admin.err.server_taken_by', $loc, ['name' => $owner['name'] ?? '']));
                }
            }
            return ['server_id' => $manualSid, 'hetzner_account_id' => $acc];
        }
        if ($stock === '' || $stock === 'none') {
            return ['server_id' => 0, 'hetzner_account_id' => ''];
        }
        if (!str_starts_with($stock, 'inv:')) {
            return ['server_id' => 0, 'hetzner_account_id' => ''];
        }
        $invId = substr($stock, 4);
        $item = null;
        foreach (Database::listInventory() as $i) {
            if (($i['id'] ?? '') === $invId) {
                $item = $i;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException(self::t('admin.err.stock_not_found', $loc));
        }
        $sid = (int) ($item['server_id'] ?? 0);
        $assigned = self::assignedServerIds();
        if (isset($assigned[$sid])) {
            $owner = null;
            foreach (Database::listClients() as $c) {
                if ((int) ($c['server_id'] ?? 0) === $sid && ($exceptClientId === null || $c['id'] !== $exceptClientId)) {
                    $owner = $c;
                    break;
                }
            }
            if ($owner) {
                throw new \RuntimeException(self::t('admin.err.server_taken_by', $loc, ['name' => $owner['name'] ?? '']));
            }
        }
        return [
            'server_id' => $sid,
            'hetzner_account_id' => (string) ($item['hetzner_account_id'] ?? ''),
        ];
    }

    /** @param array<string, mixed>|null $client */
    private static function clientFormHtml(string $loc, string $postAction, ?array $client = null, bool $collapsibleAdd = false, bool $addOpen = false): string
    {
        $isEdit = $client !== null;
        $currentSid = (int) ($client['server_id'] ?? 0);
        $accOpts = '<option value="">' . self::e(self::t('admin.common.none_dash', $loc)) . '</option>';
        foreach (ProviderManager::active()->getAccounts() as $a) {
            $aid = (string) ($a['id'] ?? '');
            $sel = ($client['hetzner_account_id'] ?? '') === $aid ? ' selected' : '';
            $accOpts .= '<option value="' . self::e($aid) . '"' . $sel . '>' . self::e($a['name'] ?? $aid) . '</option>';
        }

        $stockCount = 0;
        foreach (self::assignableStockByProduct($client['id'] ?? null) as $items) {
            $stockCount += count($items);
        }

        $formInner = '<p class="sub">' . self::e(self::t($isEdit ? 'admin.clients.edit_sub' : 'admin.clients.add_hint', $loc)) . '</p>'
            . '<form method="post" action="' . self::e(Url::to($postAction)) . '">'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:0 18px">'
            . '<div><label>' . self::e(self::t('admin.common.name', $loc)) . '</label><input name="name" value="' . self::e($client['name'] ?? '') . '" required placeholder="' . self::e(self::t('admin.clients.name_ph', $loc)) . '"></div>'
            . '<div><label>' . self::e(self::t('admin.clients.phone_req', $loc)) . '</label><input name="phone" dir="ltr" value="' . self::e($client['phone'] ?? '') . '" required placeholder="+9665xxxxxxxx"></div>'
            . '<div><label>' . self::e(self::t('admin.clients.tg_id_opt', $loc)) . '</label><input name="telegram_id" value="' . self::e($client['telegram_id'] ?? '') . '" placeholder="' . self::e(self::t('admin.clients.tg_id_ph', $loc)) . '"></div>'
            . '<div><label>' . self::e(self::t('admin.clients.expires', $loc)) . '</label>'
            . '<input name="expires" type="date" value="' . self::e($client['expires'] ?? '') . '">'
            . '<p class="sub" style="margin-top:4px">' . self::e(self::t('admin.clients.expires_pick', $loc)) . '</p></div>'
            . '<div><label>' . self::e(self::t('admin.clients.months_quick', $loc)) . '</label><input name="months" type="number" min="0" max="60" value="" placeholder="1"></div>'
            . '</div>';

        if ($isEdit) {
            $formInner .= '<label style="margin-top:14px"><input type="checkbox" name="active" ' . (($client['active'] ?? true) !== false ? 'checked' : '') . '> '
                . self::e(self::t('admin.common.active', $loc)) . '</label>';
        }

        $multiSubs = $isEdit && count(self::relatedClientSubscriptions($client)) > 1;
        $formInner .= '<hr style="border:0;border-top:1px solid #25405f;margin:20px 0">'
            . '<h2 style="font-size:16px;margin-bottom:8px">' . self::e(self::t('admin.clients.server_section', $loc)) . '</h2>'
            . '<p class="sub">' . self::e(self::t($multiSubs ? 'admin.clients.server_section_current' : 'admin.clients.server_section_sub', $loc)) . '</p>';

        if ($stockCount === 0 && $currentSid <= 0) {
            $formInner .= '<div class="warn">' . self::t('admin.clients.stock_warn', $loc, [
                'url_products' => AdminPath::url('/products'),
            ]) . '</div>'
                . '<input type="hidden" name="stock" value="manual">';
        } else {
            $formInner .= '<label>' . self::e(self::t('admin.clients.stock_pick', $loc)) . '</label>'
                . '<select name="stock">' . self::clientStockSelectHtml($loc, $client) . '</select>';
        }

        $formInner .= '<details style="margin-top:14px"><summary style="cursor:pointer;color:#9fb3cc;font-size:13px">'
            . self::e(self::t('admin.clients.stock_manual_head', $loc)) . '</summary>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 18px;margin-top:12px">'
            . '<div><label>' . self::e(self::t('admin.common.server', $loc)) . ' ID</label><input name="server_id_manual" type="number" min="0" value="' . self::e($currentSid > 0 ? (string) $currentSid : '') . '"></div>'
            . '<div><label>' . self::e(self::t('admin.clients.hetzner_project', $loc)) . '</label><select name="hetzner_account_id_manual">' . $accOpts . '</select></div>'
            . '</div></details>'
            . '<div class="row" style="margin-top:18px"><button class="btn">' . self::e(self::t($isEdit ? 'admin.common.save' : 'admin.clients.add_btn', $loc)) . '</button>';
        if ($isEdit) {
            $formInner .= '<a class="btn gray" href="' . AdminPath::url('/clients') . '">' . self::e(self::t('admin.common.back', $loc)) . '</a>';
        } elseif ($collapsibleAdd) {
            $formInner .= '<a class="btn gray" href="' . AdminPath::url('/clients') . '">' . self::e(self::t('admin.common.cancel', $loc)) . '</a>';
        }
        $formInner .= '</div></form>';

        if ($isEdit) {
            return '<div class="card"><h2>' . self::e(self::t('admin.clients.edit_head', $loc, ['name' => $client['name'] ?? ''])) . '</h2>' . $formInner . '</div>'
                . '<div class="card">' . self::clientServersManageHtml($loc, $client) . '</div>';
        }
        if ($collapsibleAdd) {
            $openAttr = $addOpen ? ' open' : '';
            return '<details class="card admin-panel client-add-panel" id="client-add-panel"' . $openAttr . '>'
                . '<summary>' . self::e(self::t('admin.clients.add_new', $loc)) . '</summary>'
                . '<div class="panel-body">' . $formInner . '</div></details>';
        }
        return '<div class="card"><h2>' . self::e(self::t('admin.clients.add_new', $loc)) . '</h2>' . $formInner . '</div>';
    }

    private static function resolveClientExpires(Request $req): string
    {
        $expires = trim((string) ($req->body['expires'] ?? ''));
        if ($expires !== '') {
            return $expires;
        }
        $months = (int) ($req->body['months'] ?? 0);
        if ($months > 0) {
            $d = new \DateTime();
            $d->modify('+' . $months . ' months');
            return $d->format('Y-m-d');
        }
        return '';
    }

    private static function dashDate(string $loc): string
    {
        if ($loc !== 'ar') {
            return date('F j, Y');
        }
        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        return date('j') . ' ' . $months[(int) date('n') - 1] . ' ' . date('Y');
    }

    public static function dashboard(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $cur = (string) ($st['currency'] ?? 'KWD');
        $clients = Database::listClients();
        $uniqueCustomers = count(self::groupClientsByPhone($clients, 'expires_asc'));
        $expired = 0;
        $active = 0;
        $expiring = [];
        foreach ($clients as $c) {
            if (($c['active'] ?? true) === false) {
                continue;
            }
            if (Database::isExpired($c)) {
                $expired++;
            } else {
                $active++;
                $days = Database::daysLeft($c);
                if ($days !== null && $days <= 7) {
                    $expiring[] = $c;
                }
            }
        }
        $report = Finance::buildReport();
        $productNames = [];
        foreach (Database::listProducts() as $p) {
            $productNames[(string) $p['id']] = (string) $p['name'];
        }
        $ipMap = [];
        if (ProviderManager::isActiveConfigured()) {
            try {
                foreach (ProviderManager::active()->listServers() as $s) {
                    $sid = (int) ($s['id'] ?? 0);
                    if ($sid > 0) {
                        $ipMap[$sid] = (string) ($s['public_net']['ipv4']['ip'] ?? '');
                    }
                }
            } catch (\Throwable) {
            }
        }
        $newPhones = [];
        $monthKey = date('Y-m');
        foreach ($clients as $c) {
            if (substr((string) ($c['created_at'] ?? ''), 0, 7) === $monthKey) {
                $phone = Database::normPhone((string) ($c['phone'] ?? ''));
                $key = $phone !== '' ? $phone : (string) ($c['id'] ?? '');
                $newPhones[$key] = true;
            }
        }
        $newThisMonth = count($newPhones);
        $activePct = $uniqueCustomers > 0 ? (int) round(($active / $uniqueCustomers) * 100) : 0;
        $lastMonthRev = (float) $report['lastMonth'];
        $thisMonthRev = (float) $report['thisMonth'];
        $revPct = $lastMonthRev > 0
            ? (int) round((($thisMonthRev - $lastMonthRev) / $lastMonthRev) * 100)
            : ($thisMonthRev > 0 ? 100 : 0);
        $revSubKey = $revPct >= 0 ? 'admin.home.revenue_vs_last' : 'admin.home.revenue_vs_last_down';
        $sortedClients = $clients;
        usort($sortedClients, static fn(array $a, array $b): int => strcmp(
            (string) ($b['created_at'] ?? ''),
            (string) ($a['created_at'] ?? '')
        ));
        $latestRows = [];
        foreach (array_slice($sortedClients, 0, 5) as $c) {
            $pid = Database::productIdForClient($c);
            $plan = $pid !== null ? ($productNames[$pid] ?? '—') : '—';
            $sid = (int) ($c['server_id'] ?? 0);
            $latestRows[] = [
                'client' => $c,
                'plan' => $plan,
                'ip' => $sid > 0 ? ($ipMap[$sid] ?? '') : '',
            ];
        }
        $chartMonths = [];
        foreach ($report['months'] as $m) {
            $chartMonths[] = [
                'key' => $m['key'],
                'label' => AdminUi::chartMonthLabel($m['key'], $loc),
                'sum' => $m['sum'],
            ];
        }
        $payRows = '';
        foreach (array_slice($report['recentPaid'], 0, 5) as $inv) {
            $client = Database::getClient((string) ($inv['client_id'] ?? ''));
            $payRows .= '<tr><td class="mono">' . self::e($inv['number'] ?? '') . '</td><td>' . self::e($client['name'] ?? $inv['buyer_name'] ?? '') . '</td><td>'
                . self::e(number_format((float) ($inv['amount'] ?? 0), 2)) . ' ' . self::e($cur) . '</td><td class="mono">'
                . self::e(substr((string) ($inv['paid_at'] ?? ''), 0, 10)) . '</td></tr>';
        }
        if ($payRows === '') {
            $payRows = '<tr><td colspan="4" class="sub">' . self::e(self::t('admin.home.no_payments', $loc)) . '</td></tr>';
        }
        $actRows = '';
        foreach (Database::listActivity(6) as $a) {
            $actRows .= '<tr><td class="mono">' . self::e(substr((string) ($a['t'] ?? ''), 0, 16)) . '</td><td>'
                . self::e(ActivityLog::typeLabel((string) ($a['type'] ?? ''), $loc)) . '</td><td>'
                . self::e(ActivityLog::formatText($a, $loc)) . '</td></tr>';
        }
        if ($actRows === '') {
            $actRows = '<tr><td colspan="3" class="sub">' . self::e(self::t('admin.home.no_activity', $loc)) . '</td></tr>';
        }
        $banner = '';
        $hasTg = Config::get('TELEGRAM_BOT_TOKEN') !== '';
        $hasHz = ProviderManager::isActiveConfigured();
        if (!$hasTg || !$hasHz) {
            $banner = '<div class="warn">' . self::t('admin.home.setup_banner', $loc, [
                'url_telegram' => AdminPath::url('/telegram'),
                'url_hetzner' => AdminPath::url('/hetzner'),
            ]) . '</div>';
        }
        $updateState = UpdateCheck::check(false);
        $banner .= UpdateCheck::bannerHtml($loc, $updateState);
        $lowStock = StockAlert::lowStockPlans();
        StockAlert::checkAndNotify();
        $hasPaypal = PayPal::configured();
        $pills = AdminUi::dashPill(self::t($hasTg ? 'admin.home.pill_bot_on' : 'admin.home.pill_bot_off', $loc), $hasTg ? 'ok' : 'err')
            . AdminUi::dashPill(self::t($hasHz ? 'admin.home.pill_hetzner_on' : 'admin.home.pill_hetzner_off', $loc), $hasHz ? 'ok' : 'warn')
            . AdminUi::dashPill(self::t($hasPaypal ? 'admin.home.pill_paypal_on' : 'admin.home.pill_paypal_off', $loc), $hasPaypal ? 'ok' : 'warn');
        if ($lowStock !== []) {
            $pills .= AdminUi::dashPill(self::t('admin.home.pill_low_stock', $loc, ['n' => count($lowStock)]), 'warn');
        }
        $heroTitle = self::t('admin.home.greeting', $loc);
        $heroSub = self::t('admin.home.greeting_sub', $loc, ['date' => self::dashDate($loc)]);
        $kpi = AdminUi::kpiRow([
            [
                'n' => $uniqueCustomers,
                'l' => self::t('admin.home.total_clients', $loc),
                'icon' => '👥',
                'sub' => self::t('admin.home.new_this_month', $loc, ['n' => $newThisMonth]),
                'tone' => '',
            ],
            [
                'n' => $active,
                'l' => self::t('admin.home.active_subs', $loc),
                'icon' => '✅',
                'sub' => self::t('admin.home.pct_of_clients', $loc, ['pct' => (string) $activePct]),
                'tone' => 'cyan',
            ],
            [
                'n' => count($expiring),
                'l' => self::t('admin.home.expiring_soon', $loc),
                'icon' => '⏳',
                'sub' => self::t('admin.home.within_days', $loc),
                'tone' => count($expiring) > 0 ? 'warn' : '',
            ],
            [
                'n' => number_format($thisMonthRev, 0),
                'l' => self::t('admin.home.monthly_revenue', $loc),
                'icon' => '💰',
                'sub' => self::t($revSubKey, $loc, ['pct' => (string) abs($revPct), 'currency' => $cur]),
                'tone' => 'gold',
            ],
        ]);
        $quick = AdminUi::quickActions(self::t('admin.home.quick_actions', $loc), [
            ['href' => AdminPath::url('/clients?add=1'), 'icon' => '➕', 'label' => self::t('admin.home.add_client', $loc), 'tone' => 'purple'],
            ['href' => AdminPath::url('/products'), 'icon' => '📦', 'label' => self::t('admin.home.new_package', $loc), 'tone' => 'gold'],
            ['href' => AdminPath::url('/invoices'), 'icon' => '🧾', 'label' => self::t('admin.home.view_invoices', $loc), 'tone' => 'blue'],
            ['href' => AdminPath::url('/hetzner'), 'icon' => '☁️', 'label' => self::t('admin.home.servers_mgmt', $loc), 'tone' => 'blue'],
            ['href' => AdminPath::url('/telegram'), 'icon' => '🤖', 'label' => self::t('admin.home.bot_settings', $loc), 'tone' => 'purple'],
            ['href' => AdminPath::url('/branding'), 'icon' => '🎨', 'label' => self::t('admin.home.branding', $loc), 'tone' => 'gold'],
        ]);
        $chartCard = '<div class="card">'
            . AdminUi::cardHead(self::t('admin.home.revenue_chart', $loc), AdminPath::url('/invoices'), self::t('admin.home.view_invoices', $loc))
            . AdminUi::financeChart($chartMonths, (float) $report['maxSum']) . '</div>';
        $latestCard = '<div class="card">'
            . AdminUi::cardHead(self::t('admin.home.latest_clients', $loc), AdminPath::url('/clients'), self::t('admin.home.view_all', $loc))
            . AdminUi::latestClientsTable($latestRows, $loc) . '</div>';
        $stockCard = AdminUi::stockAlertsCard(array_slice($lowStock, 0, 4), $loc, AdminPath::url('/products'));
        $paymentsCard = '<div class="card">'
            . AdminUi::cardHead(self::t('admin.home.recent_payments', $loc), AdminPath::url('/invoices'), self::t('admin.home.view_all', $loc))
            . AdminUi::tableWrap('<table><tr><th>'
            . self::e(self::t('admin.invoices.number', $loc)) . '</th><th>' . self::e(self::t('admin.common.client', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.amount', $loc)) . '</th><th>' . self::e(self::t('admin.common.date', $loc)) . '</th></tr>'
            . $payRows . '</table>') . '</div>';
        $activityCard = '<div class="card">'
            . AdminUi::cardHead(self::t('admin.home.recent_activity', $loc), AdminPath::url('/activity'), self::t('admin.home.view_all', $loc))
            . AdminUi::tableWrap('<table><tr><th>'
            . self::e(self::t('admin.common.time', $loc)) . '</th><th>' . self::e(self::t('admin.common.type', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.event', $loc)) . '</th></tr>' . $actRows . '</table>') . '</div>';
        $body = $banner
            . AdminUi::dashHero($heroTitle, $heroSub, $pills)
            . $kpi
            . $quick
            . AdminUi::dashCols(
                $chartCard . $stockCard,
                $latestCard . $paymentsCard . $activityCard
            );
        self::render($req, $loc, self::t('admin.home.title', $loc), $body, 'home', ['pageClass' => 'dash-home']);
    }

    public static function dispatchGet(Request $req, string $loc, array $parts): void
    {
        $section = $parts[0] ?? '';
        match ($section) {
            'clients' => self::dispatchClientsGet($req, $loc, $parts),
            'products' => self::dispatchProductsGet($req, $loc, $parts),
            'inventory' => Response::redirect(AdminPath::to('')),
            'invoices' => self::dispatchInvoicesGet($req, $loc, $parts),
            'payments' => self::paymentsGet($req, $loc),
            'telegram' => self::telegramGet($req, $loc),
            'hetzner' => self::dispatchHetznerGet($req, $loc, $parts),
            'activity' => self::activityGet($req, $loc),
            'branding' => self::brandingGet($req, $loc),
            'settings' => self::dispatchSettingsGet($req, $loc, $parts),
            default => NotFound::admin($loc),
        };
    }

    public static function dispatchPost(Request $req, string $loc, string $path): void
    {
        self::requireCsrf($req, $loc);
        $parts = explode('/', trim($path, '/'));
        $section = $parts[0] ?? '';
        match ($section) {
            'clients' => self::dispatchClientsPost($req, $loc, $parts),
            'products' => self::dispatchProductsPost($req, $loc, $parts),
            'inventory' => self::dispatchInventoryPost($req, $loc, $parts),
            'invoices' => self::dispatchInvoicesPost($req, $loc, $parts),
            'payments' => self::dispatchPaymentsPost($req, $loc, $parts),
            'telegram' => self::dispatchTelegramPost($req, $loc, $parts),
            'hetzner' => self::dispatchHetznerPost($req, $loc, $parts),
            'branding' => self::dispatchBrandingPost($req, $loc, $parts),
            'settings' => self::dispatchSettingsPost($req, $loc, $parts),
            'security' => self::securityPost($req, $loc, $parts),
            default => NotFound::admin($loc),
        };
    }

    private static function securityPost(Request $req, string $loc, array $parts): void
    {
        if (($parts[1] ?? '') !== 'remove-install') {
            NotFound::admin($loc);
        }
        $result = InstallCleanup::cleanup();
        if ($result['failed'] === []) {
            Response::redirect(AdminPath::to() . '?ok=admin.security.install_removed');
        }
        $msg = self::t('admin.security.install_partial', $loc, [
            'failed' => implode(', ', $result['failed']),
        ]);
        Response::redirect(AdminPath::to() . '?err=' . rawurlencode($msg));
    }

    // ── Clients ─────────────────────────────────────────────────────────────

    private static function dispatchClientsGet(Request $req, string $loc, array $parts): void
    {
        if (count($parts) === 1) {
            self::clientsList($req, $loc);
            return;
        }
        $id = $parts[1] ?? '';
        $action = $parts[2] ?? '';
        $client = Database::getClient($id);
        if (!$client) {
            NotFound::admin($loc);
        }
        match ($action) {
            'edit' => self::clientEditGet($req, $loc, $client),
            'message' => self::clientMessageGet($req, $loc, $client),
            'rebuild' => self::clientRebuildGet($req, $loc, $client),
            default => NotFound::admin($loc),
        };
    }

    private static function clientsList(Request $req, string $loc): void
    {
        $allClients = Database::listClients();
        $linked = 0;
        $withServer = 0;
        $active = 0;
        foreach ($allClients as $c) {
            if (!empty($c['telegram_id'])) {
                $linked++;
            }
            if ((int) ($c['server_id'] ?? 0) > 0) {
                $withServer++;
            }
            if (($c['active'] ?? true) !== false && !Database::isExpired($c)) {
                $active++;
            }
        }
        $stats = AdminUi::statsGrid([
            ['n' => count(self::groupClientsByPhone($allClients, 'expires_asc')), 'l' => self::t('admin.clients.stat_total', $loc)],
            ['n' => $active, 'l' => self::t('admin.clients.stat_active', $loc)],
            ['n' => $linked, 'l' => self::t('admin.clients.stat_linked', $loc)],
            ['n' => $withServer, 'l' => self::t('admin.clients.stat_servers', $loc)],
        ]);

        $filterQ = [
            'q' => trim((string) ($req->query['q'] ?? '')),
            'filter' => (string) ($req->query['filter'] ?? 'all'),
            'sort' => (string) ($req->query['sort'] ?? 'expires_asc'),
            'list' => (string) ($req->query['list'] ?? ''),
        ];
        $filtered = self::filterAndSortClients($allClients, $filterQ);
        $groups = self::groupClientsByPhone($filtered, $filterQ['sort']);
        $filteredTotal = count($groups);
        $perPage = AdminUi::PAGE_SIZE;
        $page = AdminUi::pageNum($req);
        $pages = max(1, (int) ceil($filteredTotal / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;
        $pageGroups = $filteredTotal > 0 ? array_slice($groups, $offset, $perPage) : [];

        $rows = '';
        foreach ($pageGroups as $subs) {
            $rows .= self::clientGroupRowHtml($subs, $loc);
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="sub">' . self::e(self::t('admin.clients.none_filtered', $loc)) . '</td></tr>';
        }

        $showAddForm = ($req->query['add'] ?? '') === '1';
        $listOpen = ($req->query['list'] ?? '') !== '0';
        $pgQuery = array_filter([
            'q' => $filterQ['q'] !== '' ? $filterQ['q'] : null,
            'filter' => $filterQ['filter'] !== 'all' ? $filterQ['filter'] : null,
            'sort' => $filterQ['sort'] !== 'expires_asc' ? $filterQ['sort'] : null,
            'list' => $listOpen ? '1' : null,
        ], static fn($v) => $v !== null && $v !== '');
        $uniqueCustomers = count(self::groupClientsByPhone($allClients, 'expires_asc'));
        $body = AdminUi::dashHero(self::t('admin.clients.title', $loc), self::t('admin.clients.list_sub', $loc))
            . $stats
            . self::clientFormHtml($loc, AdminPath::to('/clients/add'), null, true, $showAddForm)
            . '<details class="card admin-panel" id="client-list-panel"' . ($listOpen ? ' open' : '') . '>'
            . '<summary>' . self::e(self::t('admin.clients.list', $loc, ['n' => (string) $uniqueCustomers])) . '</summary>'
            . '<div class="panel-body">'
            . AdminUi::clientsFilterBar($loc, $filterQ, count($pageGroups), $filteredTotal)
            . AdminUi::tableWrap('<table id="client-groups-table"><tr><th>' . self::e(self::t('admin.common.name', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.phone', $loc)) . '</th><th>' . self::e(self::t('admin.common.status', $loc)) . '</th><th>'
            . self::e(self::t('admin.clients.col_bot', $loc)) . '</th><th>' . self::e(self::t('admin.clients.col_server', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.expires', $loc)) . '</th><th>' . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>'
            . $rows . '</table>')
            . self::clientGroupListScript()
            . AdminUi::paginationBar($loc, $page, $perPage, $filteredTotal, '/clients', $pgQuery)
            . '</div></details>';
        self::render($req, $loc, self::t('admin.clients.title', $loc), $body, 'clients');
    }

    /**
     * @param list<array<string, mixed>> $clients
     * @return list<list<array<string, mixed>>>
     */
    private static function groupClientsByPhone(array $clients, string $sort): array
    {
        /** @var list<list<array<string, mixed>>> $groups */
        $groups = [];
        foreach ($clients as $c) {
            $placed = false;
            foreach ($groups as $gi => $group) {
                foreach ($group as $ref) {
                    if (self::clientsShareAccount($ref, $c)) {
                        $groups[$gi][] = $c;
                        $placed = true;
                        break 2;
                    }
                }
            }
            if (!$placed) {
                $groups[] = [$c];
            }
        }
        foreach ($groups as &$subs) {
            usort($subs, static function (array $a, array $b) use ($sort): int {
                if ($sort === 'name_asc') {
                    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
                }
                $ea = (string) ($a['expires'] ?? '');
                $eb = (string) ($b['expires'] ?? '');
                if ($ea === '' && $eb === '') {
                    return 0;
                }
                if ($ea === '') {
                    return 1;
                }
                if ($eb === '') {
                    return -1;
                }
                $cmp = strcmp($ea, $eb);
                return $sort === 'expires_desc' ? -$cmp : $cmp;
            });
        }
        unset($subs);
        usort($groups, static function (array $a, array $b) use ($sort): int {
            $pa = $a[0] ?? [];
            $pb = $b[0] ?? [];
            if ($sort === 'name_asc') {
                return strcasecmp((string) ($pa['name'] ?? ''), (string) ($pb['name'] ?? ''));
            }
            $ea = (string) ($pa['expires'] ?? '');
            $eb = (string) ($pb['expires'] ?? '');
            if ($ea === '' && $eb === '') {
                return 0;
            }
            if ($ea === '') {
                return 1;
            }
            if ($eb === '') {
                return -1;
            }
            $cmp = strcmp($ea, $eb);
            return $sort === 'expires_desc' ? -$cmp : $cmp;
        });
        return $groups;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function clientsShareAccount(array $a, array $b): bool
    {
        if (($a['id'] ?? '') === ($b['id'] ?? '')) {
            return true;
        }
        $tgA = trim((string) ($a['telegram_id'] ?? ''));
        $tgB = trim((string) ($b['telegram_id'] ?? ''));
        if ($tgA !== '' && $tgB !== '' && $tgA === $tgB) {
            return true;
        }
        return ClientAccounts::phonesMatch((string) ($a['phone'] ?? ''), (string) ($b['phone'] ?? ''));
    }

    /**
     * @param array<string, mixed> $client
     * @return list<array<string, mixed>>
     */
    private static function relatedClientSubscriptions(array $client): array
    {
        $subs = [];
        foreach (Database::listClients() as $c) {
            if (self::clientsShareAccount($client, $c)) {
                $subs[] = $c;
            }
        }
        usort($subs, static function (array $a, array $b): int {
            $sa = (int) ($a['server_id'] ?? 0);
            $sb = (int) ($b['server_id'] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            return strcmp((string) ($a['expires'] ?? ''), (string) ($b['expires'] ?? ''));
        });
        return $subs;
    }

    /** @return array{server_id: int, hetzner_account_id: string} */
    private static function parseAddServerStockInput(Request $req, string $loc): array
    {
        $stock = trim((string) ($req->body['add_stock'] ?? ''));
        $manualSid = (int) ($req->body['add_server_id_manual'] ?? 0);
        if ($stock === 'manual' || ($stock === '' && $manualSid > 0)) {
            if ($manualSid <= 0) {
                return ['server_id' => 0, 'hetzner_account_id' => ''];
            }
            $acc = trim((string) ($req->body['add_hetzner_account_id_manual'] ?? ''));
            $assigned = self::assignedServerIds();
            if (isset($assigned[$manualSid])) {
                $owner = null;
                foreach (Database::listClients() as $c) {
                    if ((int) ($c['server_id'] ?? 0) === $manualSid) {
                        $owner = $c;
                        break;
                    }
                }
                if ($owner) {
                    throw new \RuntimeException(self::t('admin.err.server_taken_by', $loc, ['name' => $owner['name'] ?? '']));
                }
            }
            return ['server_id' => $manualSid, 'hetzner_account_id' => $acc];
        }
        if ($stock === '' || $stock === 'none') {
            return ['server_id' => 0, 'hetzner_account_id' => ''];
        }
        if (!str_starts_with($stock, 'inv:')) {
            return ['server_id' => 0, 'hetzner_account_id' => ''];
        }
        $invId = substr($stock, 4);
        $item = null;
        foreach (Database::listInventory() as $i) {
            if (($i['id'] ?? '') === $invId) {
                $item = $i;
                break;
            }
        }
        if (!$item) {
            throw new \RuntimeException(self::t('admin.err.stock_not_found', $loc));
        }
        $sid = (int) ($item['server_id'] ?? 0);
        $assigned = self::assignedServerIds();
        if (isset($assigned[$sid])) {
            $owner = null;
            foreach (Database::listClients() as $c) {
                if ((int) ($c['server_id'] ?? 0) === $sid) {
                    $owner = $c;
                    break;
                }
            }
            if ($owner) {
                throw new \RuntimeException(self::t('admin.err.server_taken_by', $loc, ['name' => $owner['name'] ?? '']));
            }
        }
        return [
            'server_id' => $sid,
            'hetzner_account_id' => (string) ($item['hetzner_account_id'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $client */
    private static function clientServersManageHtml(string $loc, array $client): string
    {
        $subs = self::relatedClientSubscriptions($client);
        $clientId = (string) ($client['id'] ?? '');
        $rows = '';
        foreach ($subs as $sub) {
            $subId = (string) ($sub['id'] ?? '');
            $sid = (int) ($sub['server_id'] ?? 0);
            $plan = self::productLabelForServer($sid);
            $serverLabel = $sid > 0
                ? '#' . $sid . ($plan !== '' ? ' — ' . $plan : '')
                : self::t('admin.common.none_dash', $loc);
            $currentMark = $subId === $clientId
                ? ' <span class="badge b-ok">' . self::e(self::t('admin.clients.server_current', $loc)) . '</span>'
                : '';
            $actions = '';
            if ($subId !== $clientId) {
                $actions .= '<a class="btn-sm" href="' . AdminPath::url('/clients/' . self::e($subId) . '/edit') . '">'
                    . self::e(self::t('admin.clients.edit_subscription', $loc)) . '</a> ';
            }
            if ($sid > 0) {
                $removeConfirm = self::t('admin.clients.remove_server_confirm', $loc, ['server' => $serverLabel]);
                $actions .= '<form method="post" action="' . AdminPath::url('/clients/' . self::e($clientId) . '/remove-server') . '" style="display:inline" onsubmit="return confirm('
                    . "'" . self::e($removeConfirm) . "'" . ')">'
                    . '<input type="hidden" name="sub_id" value="' . self::e($subId) . '">'
                    . '<button type="submit" class="btn-sm red">' . self::e(self::t('admin.clients.remove_server_btn', $loc)) . '</button></form>';
            }
            $rows .= '<tr><td class="mono">' . self::e($serverLabel) . $currentMark . '</td>'
                . '<td class="mono">' . self::e((string) ($sub['expires'] ?? '—')) . '</td>'
                . '<td>' . AdminUi::clientStatusBadge($sub, $loc) . '</td>'
                . '<td class="row" style="gap:4px;flex-wrap:wrap">' . $actions . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="sub">' . self::e(self::t('admin.clients.no_servers', $loc)) . '</td></tr>';
        }

        $addStockCount = 0;
        foreach (self::assignableStockByProduct(null) as $items) {
            $addStockCount += count($items);
        }
        $accOpts = '<option value="">' . self::e(self::t('admin.common.none_dash', $loc)) . '</option>';
        foreach (ProviderManager::active()->getAccounts() as $a) {
            $accOpts .= '<option value="' . self::e((string) ($a['id'] ?? '')) . '">' . self::e($a['name'] ?? $a['id']) . '</option>';
        }
        $addForm = '<form method="post" action="' . AdminPath::url('/clients/' . self::e($clientId) . '/add-server') . '" style="margin-top:16px">'
            . '<h3 style="font-size:14px;margin:0 0 8px">' . self::e(self::t('admin.clients.add_server_head', $loc)) . '</h3>'
            . '<p class="sub">' . self::e(self::t('admin.clients.add_server_sub', $loc)) . '</p>';
        if ($addStockCount === 0) {
            $addForm .= '<div class="warn">' . self::t('admin.clients.stock_warn', $loc, [
                'url_products' => AdminPath::url('/products'),
            ]) . '</div>'
                . '<input type="hidden" name="add_stock" value="manual">';
        } else {
            $addForm .= '<label>' . self::e(self::t('admin.clients.stock_pick', $loc)) . '</label>'
                . '<select name="add_stock">' . self::clientStockSelectHtml($loc, null) . '</select>';
        }
        $addForm .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 18px;margin-top:12px">'
            . '<div><label>' . self::e(self::t('admin.clients.expires', $loc)) . '</label><input name="add_expires" type="date"></div>'
            . '<div><label>' . self::e(self::t('admin.clients.months_quick', $loc)) . '</label><input name="add_months" type="number" min="0" max="60" placeholder="1"></div>'
            . '</div>'
            . '<details style="margin-top:12px"><summary style="cursor:pointer;color:#9fb3cc;font-size:13px">'
            . self::e(self::t('admin.clients.stock_manual_head', $loc)) . '</summary>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 18px;margin-top:12px">'
            . '<div><label>' . self::e(self::t('admin.common.server', $loc)) . ' ID</label><input name="add_server_id_manual" type="number" min="0"></div>'
            . '<div><label>' . self::e(self::t('admin.clients.hetzner_project', $loc)) . '</label><select name="add_hetzner_account_id_manual">' . $accOpts . '</select></div>'
            . '</div></details>'
            . '<button type="submit" class="btn" style="margin-top:14px">' . self::e(self::t('admin.clients.add_server_btn', $loc)) . '</button>'
            . '</form>';

        return '<h2 style="font-size:16px;margin-bottom:8px">' . self::e(self::t('admin.clients.servers_list_head', $loc)) . '</h2>'
            . '<p class="sub">' . self::e(self::t('admin.clients.servers_list_sub', $loc)) . '</p>'
            . AdminUi::tableWrap('<table><tr><th>' . self::e(self::t('admin.common.server', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.expires', $loc)) . '</th><th>' . self::e(self::t('admin.common.status', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>' . $rows . '</table>')
            . $addForm;
    }

    private static function clientGroupListScript(): string
    {
        return <<<'JS'
<script>
(function(){
  function statusHtml(opt){
    var s=opt.getAttribute('data-status')||'';
    return s?('<span class="badge '+opt.getAttribute('data-status-cls')+'">'+s+'</span>'):'—';
  }
  function syncRow(sel){
    var row=sel.closest('tr');
    if(!row)return;
    var opt=sel.options[sel.selectedIndex];
    if(!opt)return;
    var id=opt.value;
    var base=sel.getAttribute('data-admin-base')||'';
    var exp=row.querySelector('.client-exp-cell');
    var st=row.querySelector('.client-status-cell');
    if(exp)exp.textContent=opt.getAttribute('data-expires')||'—';
    if(st)st.innerHTML=statusHtml(opt);
    row.querySelectorAll('[data-client-link]').forEach(function(el){
      var kind=el.getAttribute('data-client-link');
      if(kind==='edit'||kind==='message'||kind==='rebuild'){
        el.setAttribute('href',base+'/clients/'+id+'/'+kind);
      }
    });
    row.querySelectorAll('.client-power-form').forEach(function(f){
      f.setAttribute('action',base+'/clients/'+id+'/power');
    });
    var del=row.querySelector('.client-del-form');
    if(del){
      del.setAttribute('action',base+'/clients/'+id+'/delete');
      var msg=opt.getAttribute('data-del-label')||'';
      del.onsubmit=function(){return msg?confirm(msg):true;};
    }
  }
  document.querySelectorAll('.client-server-pick').forEach(function(sel){
    syncRow(sel);
    sel.addEventListener('change',function(){syncRow(sel);});
  });
})();
</script>
JS;
    }

    /** @param list<array<string, mixed>> $clients
     *  @param array{q: string, filter: string, sort: string} $query
     *  @return list<array<string, mixed>>
     */
    private static function filterAndSortClients(array $clients, array $query): array
    {
        $q = $query['q'];
        $qLower = function_exists('mb_strtolower') ? mb_strtolower($q) : strtolower($q);
        $filter = $query['filter'];
        $normQ = $q !== '' ? Database::normPhone($q) : '';
        $out = [];
        foreach ($clients as $c) {
            if (!self::clientMatchesFilter($c, $filter)) {
                continue;
            }
            if ($q !== '') {
                $nameRaw = (string) ($c['name'] ?? '');
                $name = function_exists('mb_strtolower') ? mb_strtolower($nameRaw) : strtolower($nameRaw);
                $phone = Database::normPhone((string) ($c['phone'] ?? ''));
                $digits = preg_replace('/\D+/', '', $q) ?? '';
                $match = str_contains($name, $qLower)
                    || str_contains($phone, $normQ)
                    || ($digits !== '' && str_contains($phone, $digits));
                if (!$match) {
                    continue;
                }
            }
            $out[] = $c;
        }
        $sort = $query['sort'];
        usort($out, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'name_asc') {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            }
            $ea = (string) ($a['expires'] ?? '');
            $eb = (string) ($b['expires'] ?? '');
            if ($ea === '' && $eb === '') {
                return 0;
            }
            if ($ea === '') {
                return 1;
            }
            if ($eb === '') {
                return -1;
            }
            $cmp = strcmp($ea, $eb);
            return $sort === 'expires_desc' ? -$cmp : $cmp;
        });
        return $out;
    }

    /** @param array<string, mixed> $client */
    private static function clientMatchesFilter(array $client, string $filter): bool
    {
        if ($filter === 'all' || $filter === '') {
            return true;
        }
        $suspended = ($client['active'] ?? true) === false;
        $expired = Database::isExpired($client);
        $days = Database::daysLeft($client);
        $sid = (int) ($client['server_id'] ?? 0);
        $linked = !empty($client['telegram_id']);
        return match ($filter) {
            'active' => !$suspended && !$expired,
            'expired' => !$suspended && $expired,
            'expiring' => !$suspended && !$expired && $days !== null && $days >= 0 && $days <= 7,
            'unlinked' => !$linked,
            'no_server' => $sid <= 0,
            'suspended' => $suspended,
            default => true,
        };
    }

    /**
     * @param list<array<string, mixed>> $subs
     */
    private static function clientGroupRowHtml(array $subs, string $loc): string
    {
        if ($subs === []) {
            return '';
        }
        $primary = $subs[0];
        $multi = count($subs) > 1;
        $nameCell = '<strong>' . self::e((string) ($primary['name'] ?? '')) . '</strong>';
        if ($multi) {
            $nameCell .= ' <span class="badge b-ok">' . self::e(self::t('admin.clients.multi_badge', $loc, ['n' => (string) count($subs)])) . '</span>';
        }
        $linked = false;
        foreach ($subs as $c) {
            if (!empty($c['telegram_id'])) {
                $linked = true;
                break;
            }
        }
        $tgBadge = $linked
            ? AdminUi::badge(self::t('admin.badge.bot_linked', $loc), 'b-ok')
            : AdminUi::badge(self::t('admin.badge.bot_unlinked', $loc), 'b-muted');

        $adminBase = AdminPath::url('');
        $pick = '<select class="client-server-pick" data-admin-base="' . self::e($adminBase) . '" style="min-width:160px;max-width:220px">';
        foreach ($subs as $c) {
            $cid = (string) ($c['id'] ?? '');
            $sid = (int) ($c['server_id'] ?? 0);
            $plan = self::productLabelForServer($sid);
            $label = $sid > 0
                ? '#' . $sid . ($plan !== '' ? ' — ' . $plan : '')
                : self::t('admin.common.none_dash', $loc);
            $statusKey = self::clientStatusKey($c);
            $statusLabel = self::clientStatusLabel($c, $loc);
            $statusCls = self::clientStatusBadgeClass($statusKey);
            $delLabel = self::t('admin.clients.delete_confirm', $loc, [
                'name' => ((string) ($c['name'] ?? '')) . ($plan !== '' ? ' (' . $plan . ')' : ''),
            ]);
            $pick .= '<option value="' . self::e($cid) . '"'
                . ' data-expires="' . self::e((string) ($c['expires'] ?? '—')) . '"'
                . ' data-status="' . self::e($statusLabel) . '"'
                . ' data-status-cls="' . self::e($statusCls) . '"'
                . ' data-del-label="' . self::e($delLabel) . '"'
                . '>' . self::e($label) . '</option>';
        }
        $pick .= '</select>';
        if ($multi) {
            $pick = '<div class="client-server-pick-wrap"><label class="sub client-server-pick-label">'
                . self::e(self::t('admin.clients.server_pick', $loc)) . '</label>' . $pick . '</div>';
        }

        $first = $subs[0];
        $firstId = (string) ($first['id'] ?? '');
        $firstSid = (int) ($first['server_id'] ?? 0);
        $row = '<tr class="client-group-row"><td>' . $nameCell . '</td><td class="mono">' . self::e((string) ($primary['phone'] ?? '')) . '</td><td class="client-status-cell">'
            . AdminUi::clientStatusBadge($first, $loc) . '</td><td>' . $tgBadge . '</td><td>' . $pick . '</td><td class="mono client-exp-cell">'
            . self::e($first['expires'] ?? '—') . '</td><td class="row" style="gap:4px;flex-wrap:wrap">'
            . '<a class="btn-sm" data-client-link="edit" href="' . AdminPath::url('/clients/' . self::e($firstId) . '/edit') . '">' . self::e(self::t('admin.common.edit', $loc)) . '</a>';
        if ($linked) {
            $row .= '<a class="btn-sm" data-client-link="message" href="' . AdminPath::url('/clients/' . self::e($firstId) . '/message') . '">' . self::e(self::t('admin.common.bot_message', $loc)) . '</a>';
        }
        if ($firstSid > 0 || $multi) {
            $row .= '<a class="btn-sm" data-client-link="rebuild" href="' . AdminPath::url('/clients/' . self::e($firstId) . '/rebuild') . '">' . self::e(self::t('admin.clients.rebuild_title', $loc)) . '</a>'
                . '<form class="client-power-form" method="post" action="' . AdminPath::url('/clients/' . self::e($firstId) . '/power') . '" style="display:inline"><input type="hidden" name="action" value="on">'
                . '<button class="btn-sm green" title="' . self::e(self::t('admin.common.power_on', $loc)) . '">▶</button></form>'
                . '<form class="client-power-form" method="post" action="' . AdminPath::url('/clients/' . self::e($firstId) . '/power') . '" style="display:inline"><input type="hidden" name="action" value="off">'
                . '<button class="btn-sm gray" title="' . self::e(self::t('admin.common.power_off', $loc)) . '">⏹</button></form>'
                . '<form class="client-power-form" method="post" action="' . AdminPath::url('/clients/' . self::e($firstId) . '/power') . '" style="display:inline"><input type="hidden" name="action" value="reboot">'
                . '<button class="btn-sm" title="' . self::e(self::t('admin.common.reboot', $loc)) . '">🔄</button></form>';
        }
        $delConfirm = self::t('admin.clients.delete_confirm', $loc, ['name' => (string) ($first['name'] ?? '')]);
        return $row . '<form class="client-del-form" method="post" action="' . AdminPath::url('/clients/' . self::e($firstId) . '/delete') . '" style="display:inline" onsubmit="return confirm('
            . "'" . self::e($delConfirm) . "'" . ')">'
            . '<button class="btn-sm red">' . self::e(self::t('admin.common.delete', $loc)) . '</button></form></td></tr>';
    }

    /** @param array<string, mixed> $c */
    private static function clientStatusKey(array $c): string
    {
        if (($c['active'] ?? true) === false) {
            return 'suspended';
        }
        if (Database::isExpired($c)) {
            return 'expired';
        }
        $days = Database::daysLeft($c);
        if ($days !== null && $days >= 0 && $days <= 7) {
            return 'expiring';
        }
        return 'active';
    }

    /** @param array<string, mixed> $c */
    private static function clientStatusLabel(array $c, string $loc): string
    {
        if (($c['active'] ?? true) === false) {
            return self::t('admin.badge.suspended', $loc);
        }
        if (Database::isExpired($c)) {
            return self::t('admin.badge.expired', $loc);
        }
        $days = Database::daysLeft($c);
        if ($days !== null && $days <= 7) {
            return self::t('admin.badge.days_left', $loc, ['n' => (string) max(0, $days)]);
        }
        return self::t('admin.badge.plan_active', $loc);
    }

    /** @param array<string, mixed> $c */
    private static function clientStatusBadgeClass(string $key): string
    {
        return match ($key) {
            'active' => 'b-ok',
            'expiring' => 'b-warn',
            'expired', 'suspended' => 'b-exp',
            default => 'b-muted',
        };
    }

    /** @param array<string, mixed> $c
     * @deprecated use clientGroupRowHtml()
     */
    private static function clientListRowHtml(array $c, string $loc, array $phoneCounts = []): string
    {
        return self::clientGroupRowHtml([$c], $loc);
    }

    /** @param array<string, mixed> $client */
    private static function clientEditGet(Request $req, string $loc, array $client): void
    {
        $body = self::clientFormHtml($loc, AdminPath::to('/clients/' . ($client['id'] ?? '') . '/edit'), $client);
        self::render($req, $loc, self::t('admin.clients.edit_title', $loc), $body, 'clients');
    }

    /** @param array<string, mixed> $client */
    private static function clientMessageGet(Request $req, string $loc, array $client): void
    {
        if (empty($client['telegram_id'])) {
            self::redirectErr('/admin/clients', $loc, 'admin.err.client_not_connected');
        }
        $body = '<div class="card"><h2>' . self::e(self::t('admin.clients.message_head', $loc, ['name' => $client['name']])) . '</h2><p class="sub">'
            . self::t('admin.clients.message_sub', $loc, ['phone' => $client['phone']]) . '</p><form method="post" action="' . AdminPath::url('/clients/' . self::e($client['id']) . '/message') . '">'
            . '<label>' . self::e(self::t('admin.clients.message_label', $loc)) . '</label><textarea name="text" required></textarea>'
            . '<button class="btn">' . self::e(self::t('admin.clients.message_send', $loc)) . '</button></form></div>';
        self::render($req, $loc, self::t('admin.clients.message_title', $loc), $body, 'clients');
    }

    /** @param array<string, mixed> $client */
    private static function clientRebuildGet(Request $req, string $loc, array $client): void
    {
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid <= 0) {
            self::redirectErr('/admin/clients', $loc, 'admin.err.select_server');
        }
        if (!empty($req->query['done'])) {
            $pw = $req->query['pw'] ?? '';
            $body = '<div class="card"><h2>' . self::e(self::t('admin.clients.rebuild_ok_head', $loc)) . '</h2><p class="sub">'
                . self::e(self::t('admin.clients.rebuild_ok_sub', $loc, ['name' => $client['name']])) . '</p>';
            if ($pw !== '') {
                $body .= '<p>' . self::e(self::t('admin.clients.rebuild_pw', $loc)) . '</p><p class="mono" style="font-size:18px;padding:12px;background:#0d1a2c;border-radius:8px">'
                    . self::e($pw) . '</p>';
            }
            $body .= '<a class="btn" href="' . AdminPath::url('/clients') . '">' . self::e(self::t('admin.clients.rebuild_back', $loc)) . '</a></div>';
            self::render($req, $loc, self::t('admin.clients.rebuild_ok_title', $loc), $body, 'clients');
            return;
        }
        $images = [];
        $sp = ProviderManager::forClient($client);
        try {
            $images = $sp->listImages(ProviderManager::accountIdForClient($client));
        } catch (\Throwable $e) {
            $body = '<div class="err">' . self::e($sp->explain($e)) . '</div>';
            self::render($req, $loc, self::t('admin.clients.rebuild_title', $loc), $body, 'clients');
            return;
        }
        $opts = '';
        foreach ($images as $img) {
            $opts .= '<option value="' . self::e((string) ($img['id'] ?? '')) . '">' . self::e($img['name'] ?? '') . ' (' . self::e($img['description'] ?? '') . ')</option>';
        }
        $body = '<div class="card"><h2>' . self::e(self::t('admin.clients.rebuild_head', $loc, ['name' => $client['name']])) . '</h2>'
            . '<div class="warn">' . self::t('admin.clients.rebuild_warn', $loc) . '</div>'
            . '<form method="post" action="' . AdminPath::url('/clients/' . self::e($client['id']) . '/rebuild') . '">'
            . '<label>' . self::e(self::t('admin.common.os', $loc)) . '</label><select name="image_id" required>' . $opts . '</select>'
            . '<label><input type="checkbox" name="confirm" value="1" required> ' . self::e(self::t('admin.clients.rebuild_confirm', $loc)) . '</label>'
            . '<button class="btn red">' . self::e(self::t('admin.clients.rebuild_btn', $loc)) . '</button></form></div>';
        self::render($req, $loc, self::t('admin.clients.rebuild_title', $loc), $body, 'clients');
    }

    private static function dispatchClientsPost(Request $req, string $loc, array $parts): void
    {
        if (($parts[1] ?? '') === 'add') {
            self::clientAddPost($req, $loc);
            return;
        }
        $id = $parts[1] ?? '';
        $action = $parts[2] ?? '';
        $client = Database::getClient($id);
        if (!$client) {
            NotFound::admin($loc);
        }
        match ($action) {
            'edit' => self::clientEditPost($req, $loc, $client),
            'add-server' => self::clientAddServerPost($req, $loc, $client),
            'remove-server' => self::clientRemoveServerPost($req, $loc, $client),
            'message' => self::clientMessagePost($req, $loc, $client),
            'rebuild' => self::clientRebuildPost($req, $loc, $client),
            'power' => self::clientPowerPost($req, $loc, $client),
            'delete' => self::clientDeletePost($req, $loc, $client),
            default => NotFound::admin($loc),
        };
    }

    private static function clientAddPost(Request $req, string $loc): void
    {
        $name = trim((string) ($req->body['name'] ?? ''));
        $phone = trim((string) ($req->body['phone'] ?? ''));
        if ($name === '') {
            self::redirectAddClientErr($loc, 'admin.err.name_required');
        }
        if (strlen(Database::normPhone($phone)) < 8) {
            self::redirectAddClientErr($loc, 'admin.err.phone_required');
        }
        $tg = trim((string) ($req->body['telegram_id'] ?? ''));
        if ($tg !== '' && !ctype_digit($tg)) {
            self::redirectAddClientErr($loc, 'admin.err.tg_id_numeric');
        }
        try {
            $stock = self::parseClientStockInput($req, $loc);
        } catch (\Throwable $e) {
            self::go('/admin/clients?add=1&err=' . rawurlencode($e->getMessage()));
        }
        $c = Database::addClient([
            'name' => $name,
            'phone' => $phone,
            'telegram_id' => $tg !== '' ? $tg : ClientAccounts::telegramForPhone($phone),
            'server_id' => $stock['server_id'],
            'expires' => self::resolveClientExpires($req),
            'hetzner_account_id' => $stock['hetzner_account_id'],
        ]);
        if ($tg !== '') {
            ClientAccounts::linkTelegramToPhone($tg, $phone);
        }
        ActivityLog::log('client', 'admin.act.client_added', ['name' => $c['name'], 'phone' => $c['phone']]);
        \App\Services\AdminNotify::newClient($c, 'admin');
        self::redirectOk('/admin/clients', 'admin.ok.client_added', ['name' => $c['name']]);
    }

    /** @param array<string, mixed> $client */
    private static function clientEditPost(Request $req, string $loc, array $client): void
    {
        $name = trim((string) ($req->body['name'] ?? ''));
        $phone = trim((string) ($req->body['phone'] ?? ''));
        if ($name === '') {
            self::redirectErr('/admin/clients/' . $client['id'] . '/edit', $loc, 'admin.err.name_required');
        }
        if (strlen(Database::normPhone($phone)) < 8) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/edit', $loc, 'admin.err.phone_required');
        }
        $tg = trim((string) ($req->body['telegram_id'] ?? ''));
        if ($tg !== '' && !ctype_digit($tg)) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/edit', $loc, 'admin.err.tg_id_numeric');
        }
        try {
            $stock = self::parseClientStockInput($req, $loc, $client['id']);
        } catch (\Throwable $e) {
            self::go('/admin/clients/' . $client['id'] . '/edit?err=' . rawurlencode($e->getMessage()));
        }
        $expires = trim((string) ($req->body['expires'] ?? ''));
        $months = (int) ($req->body['months'] ?? 0);
        if ($months > 0) {
            $base = !empty($client['expires']) && !Database::isExpired($client)
                ? new \DateTime($client['expires'] . ' 12:00:00')
                : new \DateTime();
            $base->modify('+' . $months . ' months');
            $expires = $base->format('Y-m-d');
        }
        $active = isset($req->body['active']);
        foreach (self::relatedClientSubscriptions($client) as $sub) {
            if (($sub['id'] ?? '') === ($client['id'] ?? '')) {
                continue;
            }
            Database::updateClient((string) $sub['id'], [
                'name' => $name,
                'phone' => $phone,
                'telegram_id' => $tg,
                'active' => $active,
            ]);
        }
        Database::updateClient($client['id'], [
            'name' => $name,
            'phone' => $phone,
            'telegram_id' => $tg,
            'server_id' => $stock['server_id'],
            'hetzner_account_id' => $stock['hetzner_account_id'],
            'expires' => $expires,
            'active' => $active,
        ]);
        if ($tg !== '') {
            ClientAccounts::linkTelegramToPhone($tg, $phone);
        }
        ActivityLog::log('client', 'admin.act.client_updated', ['name' => $name]);
        self::redirectOk(AdminPath::to('/clients/' . $client['id'] . '/edit'), 'admin.ok.saved');
    }

    /** @param array<string, mixed> $client */
    private static function clientAddServerPost(Request $req, string $loc, array $client): void
    {
        try {
            $stock = self::parseAddServerStockInput($req, $loc);
        } catch (\Throwable $e) {
            self::go('/admin/clients/' . $client['id'] . '/edit?err=' . rawurlencode($e->getMessage()));
        }
        if ($stock['server_id'] <= 0) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/edit', $loc, 'admin.err.server_pick_required');
        }
        $subs = self::relatedClientSubscriptions($client);
        $primary = $subs[0] ?? $client;
        $name = trim((string) ($primary['name'] ?? ''));
        $phone = trim((string) ($primary['phone'] ?? ''));
        $tg = trim((string) ($primary['telegram_id'] ?? ''));
        if ($tg === '') {
            $tg = ClientAccounts::telegramForPhone($phone);
        }
        $expires = trim((string) ($req->body['add_expires'] ?? ''));
        $months = (int) ($req->body['add_months'] ?? 0);
        if ($months > 0) {
            $base = new \DateTime();
            $base->modify('+' . $months . ' months');
            $expires = $base->format('Y-m-d');
        }
        if ($expires === '') {
            $expires = (string) ($primary['expires'] ?? '');
        }
        Database::addClient([
            'name' => $name,
            'phone' => $phone,
            'telegram_id' => $tg,
            'server_id' => $stock['server_id'],
            'hetzner_account_id' => $stock['hetzner_account_id'],
            'expires' => $expires,
        ]);
        if ($tg !== '') {
            ClientAccounts::linkTelegramToPhone($tg, $phone);
        }
        ActivityLog::log('client', 'admin.act.client_server_added', [
            'name' => $name,
            'server' => (string) $stock['server_id'],
        ]);
        self::redirectOk(AdminPath::to('/clients/' . $client['id'] . '/edit'), 'admin.ok.server_added_client', [
            'server' => (string) $stock['server_id'],
        ]);
    }

    /** @param array<string, mixed> $client */
    private static function clientRemoveServerPost(Request $req, string $loc, array $client): void
    {
        $subId = trim((string) ($req->body['sub_id'] ?? ''));
        $sub = $subId !== '' ? Database::getClient($subId) : null;
        if (!$sub || !self::clientsShareAccount($client, $sub)) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/edit', $loc, 'admin.err.client_not_found');
        }
        $sid = (int) ($sub['server_id'] ?? 0);
        if ($sid <= 0) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/edit', $loc, 'admin.err.no_server_assigned');
        }
        $group = self::relatedClientSubscriptions($client);
        if (count($group) > 1) {
            Database::removeClient($subId);
        } else {
            Database::updateClient($subId, [
                'server_id' => 0,
                'hetzner_account_id' => '',
            ]);
        }
        ActivityLog::log('client', 'admin.act.client_server_removed', [
            'name' => (string) ($sub['name'] ?? ''),
            'server' => (string) $sid,
        ]);
        $redirectId = (string) ($client['id'] ?? '');
        if ($subId === $redirectId && count($group) > 1) {
            foreach ($group as $g) {
                if (($g['id'] ?? '') !== $subId) {
                    $redirectId = (string) $g['id'];
                    break;
                }
            }
        }
        self::redirectOk(AdminPath::to('/clients/' . $redirectId . '/edit'), 'admin.ok.server_removed_client', [
            'server' => (string) $sid,
        ]);
    }

    /** @param array<string, mixed> $client */
    private static function clientMessagePost(Request $req, string $loc, array $client): void
    {
        if (Config::get('TELEGRAM_BOT_TOKEN') === '') {
            self::redirectErr('/admin/clients/' . $client['id'] . '/message', $loc, 'admin.err.bot_offline');
        }
        if (empty($client['telegram_id'])) {
            self::redirectErr('/admin/clients', $loc, 'admin.err.client_not_connected');
        }
        $text = trim((string) ($req->body['text'] ?? ''));
        if ($text === '') {
            self::redirectErr('/admin/clients/' . $client['id'] . '/message', $loc, 'admin.err.message_empty');
        }
        try {
            $st = Database::getSettings();
            $prefix = $st['bot_texts']['msg_from_admin'][$loc] ?? $st['bot_texts']['msg_from_admin']['en'] ?? '';
            $msg = ($prefix !== '' ? $prefix . "\n\n" : '') . $text;
            Telegram::sendMessage((string) $client['telegram_id'], $msg);
            ActivityLog::log('message', 'admin.act.message_sent', ['name' => $client['name']]);
            self::redirectOk('/admin/clients/' . $client['id'] . '/message', 'admin.ok.message_sent', ['name' => $client['name']]);
        } catch (\Throwable $e) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/message', $loc, 'admin.err.send_failed', ['msg' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $client */
    private static function clientRebuildPost(Request $req, string $loc, array $client): void
    {
        if (empty($req->body['confirm'])) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/rebuild', $loc, 'admin.err.confirm_required');
        }
        $sid = (int) ($client['server_id'] ?? 0);
        $imageId = (int) ($req->body['image_id'] ?? 0);
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);
        try {
            $sp->rebuild($sid, $imageId, $acc);
            $pw = $sp->resetPassword($sid, $acc);
            \App\Services\AdminNotify::rebuildRequest($client, 'admin', 'ID ' . $imageId);
            ActivityLog::log('server', 'admin.act.server_rebuild_panel', ['name' => $client['name']]);
            self::go('/admin/clients/' . $client['id'] . '/rebuild?done=1&pw=' . rawurlencode($pw));
        } catch (\Throwable $e) {
            self::redirectErr('/admin/clients/' . $client['id'] . '/rebuild', $loc, 'admin.err.rebuild_failed', ['msg' => $sp->explain($e)]);
        }
    }

    /** @param array<string, mixed> $client */
    private static function clientPowerPost(Request $req, string $loc, array $client): void
    {
        $action = (string) ($req->body['action'] ?? '');
        $sid = (int) ($client['server_id'] ?? 0);
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);
        $labels = ['on' => 'admin.act.action.on', 'off' => 'admin.act.action.off', 'reboot' => 'admin.act.action.reboot'];
        if (!isset($labels[$action]) || $sid <= 0) {
            self::redirectErr('/admin/clients', $loc, 'admin.err.unknown_action');
        }
        try {
            match ($action) {
                'on' => $sp->powerOn($sid, $acc),
                'off' => $sp->powerOff($sid, $acc),
                'reboot' => $sp->reboot($sid, $acc),
                default => null,
            };
            ActivityLog::log('server', 'admin.act.server_power_panel', [
                'action' => self::t($labels[$action], $loc),
                'name' => $client['name'],
            ]);
            self::redirectOk('/admin/clients', 'admin.ok.power_sent', [
                'action' => self::t($labels[$action], $loc),
                'name' => $client['name'],
            ]);
        } catch (\Throwable $e) {
            self::go('/admin/clients?err=' . rawurlencode($sp->explain($e)));
        }
    }

    /** @param array<string, mixed> $client */
    private static function clientDeletePost(Request $req, string $loc, array $client): void
    {
        Database::removeClient($client['id']);
        ActivityLog::log('client', 'admin.act.client_deleted', ['name' => $client['name']]);
        self::redirectOk(AdminPath::to('/clients'), 'admin.ok.client_deleted', ['name' => $client['name']]);
    }

    // ── Products ────────────────────────────────────────────────────────────

    private static function dispatchProductsGet(Request $req, string $loc, array $parts): void
    {
        if (count($parts) === 1) {
            self::productsList($req, $loc);
            return;
        }
        $id = $parts[1] ?? '';
        $action = $parts[2] ?? '';
        $product = Database::getProduct($id);
        if (!$product) {
            NotFound::admin($loc);
        }
        match ($action) {
            'edit' => self::productEditGet($req, $loc, $product),
            'servers' => self::productServersGet($req, $loc, $product),
            default => NotFound::admin($loc),
        };
    }

    private static function productsList(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $cur = (string) ($st['currency'] ?? 'KWD');
        $products = Database::listProducts();
        $assigned = self::assignedServerIds();
        $allInv = Database::listInventory();
        $available = 0;
        $sold = 0;
        foreach ($allInv as $item) {
            $sid = (int) ($item['server_id'] ?? 0);
            if (isset($assigned[$sid])) {
                $sold++;
            } else {
                $available++;
            }
        }
        $stats = AdminUi::statsGrid([
            ['n' => count($products), 'l' => self::t('admin.products.stat_plans', $loc)],
            ['n' => count($allInv), 'l' => self::t('admin.products.stat_stock', $loc)],
            ['n' => $available, 'l' => self::t('admin.products.stat_available', $loc), 'tone' => 'ok'],
            ['n' => $sold, 'l' => self::t('admin.products.stat_sold', $loc), 'tone' => $sold > 0 ? 'exp' : ''],
        ]);
        $rows = '';
        foreach ($products as $p) {
            $stock = count(Database::availableStockForProduct($p['id']));
            $active = ($p['active'] ?? true) !== false;
            $rows .= '<tr><td>' . self::e($p['name']) . '</td><td>' . self::e($p['price']) . ' ' . self::e($cur) . '</td><td>'
                . self::e((string) ($p['months'] ?? 1)) . '</td><td>' . AdminUi::badge((string) $stock, 'b-muted') . '</td><td>'
                . AdminUi::badge(self::t($active ? 'admin.badge.plan_active' : 'admin.badge.plan_inactive', $loc), $active ? 'b-ok' : 'b-exp') . '</td><td class="row">'
                . '<a class="btn-sm" href="' . AdminPath::url('/products/' . self::e($p['id']) . '/servers') . '">' . self::e(self::t('admin.products.servers_btn', $loc)) . '</a>'
                . '<a class="btn-sm" href="' . AdminPath::url('/products/' . self::e($p['id']) . '/edit') . '">' . self::e(self::t('admin.common.edit', $loc)) . '</a>'
                . '<form method="post" action="' . AdminPath::url('/products/' . self::e($p['id']) . '/toggle') . '" style="display:inline"><button class="btn-sm gray">'
                . self::e(self::t($active ? 'admin.common.toggle_off' : 'admin.common.toggle_on', $loc)) . '</button></form>'
                . '<form method="post" action="' . AdminPath::url('/products/' . self::e($p['id']) . '/delete') . '" style="display:inline" onsubmit="return confirm('
                . "'" . self::e(self::t('admin.products.delete_confirm', $loc)) . "'" . ')"><button class="btn-sm red">'
                . self::e(self::t('admin.common.delete', $loc)) . '</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="sub">' . self::e(self::t('admin.products.none', $loc)) . '</td></tr>';
        }
        $newOpen = ($req->query['new'] ?? '') === '1';
        $form = '<details class="card admin-panel" id="product-new-panel"' . ($newOpen ? ' open' : '') . '>'
            . '<summary>' . self::e(self::t('admin.products.new', $loc)) . '</summary>'
            . '<div class="panel-body"><form method="post" action="' . AdminPath::url('/products/add') . '">'
            . '<label>' . self::e(self::t('admin.common.name', $loc)) . '</label><input name="name" placeholder="' . self::e(self::t('admin.products.name_ph', $loc)) . '" required>'
            . '<label>' . self::e(self::t('admin.products.price', $loc, ['currency' => $cur])) . '</label><input name="price" type="number" step="0.01" required>'
            . '<label>' . self::e(self::t('admin.products.duration', $loc)) . '</label><input name="months" type="number" value="1" min="1">'
            . '<label>' . self::e(self::t('admin.products.specs_label', $loc)) . '</label>'
            . '<textarea name="desc" rows="6" placeholder="' . self::e(self::t('admin.products.desc_ph', $loc)) . '"></textarea>'
            . '<p class="sub">' . self::e(self::t('admin.products.desc_hint', $loc)) . '</p>'
            . '<button class="btn">' . self::e(self::t('admin.common.add', $loc)) . '</button></form></div></details>';
        StockAlert::checkAndNotify();
        $body = StockAlert::bannerHtml($loc)
            . AdminUi::dashHero(self::t('admin.products.title', $loc), self::t('admin.products.sub', $loc))
            . $stats . $form
            . '<div class="card">' . AdminUi::cardHead(self::t('admin.products.list', $loc, ['n' => count($products)]))
            . AdminUi::tableWrap('<table><tr><th>'
            . self::e(self::t('admin.common.name', $loc)) . '</th><th>' . self::e(self::t('admin.common.price', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.duration', $loc)) . '</th><th>' . self::e(self::t('admin.products.stock_col', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.status', $loc)) . '</th><th>' . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>' . $rows . '</table>')
            . '</div>'
            . self::productsOpsCardHtml($st, $loc)
            . self::inventorySectionHtml($loc);
        self::render($req, $loc, self::t('admin.products.title', $loc), $body, 'products');
    }

    /** @param array<string, mixed> $st */
    private static function productsOpsCardHtml(array $st, string $loc): string
    {
        return '<div class="card"><h2>' . self::e(self::t('admin.products.ops_head', $loc)) . '</h2><form method="post" action="' . AdminPath::url('/products/ops') . '">'
            . '<label><input type="checkbox" name="auto_stop_expired" ' . (!empty($st['auto_stop_expired']) ? 'checked' : '') . '> '
            . self::e(self::t('settings.auto_stop', $loc)) . '</label><p class="sub">'
            . self::e(self::t('settings.auto_stop_hint', $loc)) . '</p>'
            . '<label>' . self::e(self::t('settings.low_stock_threshold', $loc)) . '</label>'
            . '<input name="low_stock_threshold" type="number" min="0" max="50" value="' . self::e((string) ((int) ($st['low_stock_threshold'] ?? 1))) . '">'
            . '<p class="sub">' . self::e(self::t('settings.low_stock_threshold_hint', $loc)) . '</p>'
            . '<button class="btn">' . self::e(self::t('settings.save_ops', $loc)) . '</button></form></div>';
    }

    private static function inventorySectionHtml(string $loc): string
    {
        $products = Database::listProducts();
        $productMap = [];
        foreach ($products as $p) {
            $productMap[$p['id']] = $p;
        }
        $assigned = self::assignedServerIds();
        $groups = [];
        foreach (Database::listInventory() as $item) {
            $pid = (string) ($item['product_id'] ?? '');
            $groups[$pid][] = $item;
        }
        $sections = '';
        if ($groups === []) {
            $sections = '<p class="sub">' . self::e(self::t('admin.inventory.empty', $loc)) . '</p>';
        } else {
            foreach ($groups as $pid => $items) {
                $pname = $productMap[$pid]['name'] ?? $pid;
                $rows = '';
                foreach ($items as $item) {
                    $sid = (int) ($item['server_id'] ?? 0);
                    $sold = isset($assigned[$sid]);
                    $rows .= '<tr><td class="mono">#' . self::e((string) $sid) . '</td><td>' . self::e($item['note'] ?? '') . '</td><td>'
                        . AdminUi::badge(self::t($sold ? 'admin.badge.sold_stock' : 'admin.badge.available_stock', $loc), $sold ? 'b-exp' : 'b-ok') . '</td>'
                        . '<td><a class="btn-sm" href="' . AdminPath::url('/products/' . self::e($pid) . '/servers') . '">'
                        . self::e(self::t('admin.common.view', $loc)) . '</a></td></tr>';
                }
                $sections .= '<details class="card admin-panel inv-plan-panel">'
                    . '<summary>' . self::e($pname) . ' <span class="inv-count">(' . count($items) . ')</span></summary>'
                    . '<div class="panel-body">' . AdminUi::tableWrap('<table><tr><th>'
                    . self::e(self::t('admin.common.server', $loc)) . '</th><th>' . self::e(self::t('admin.common.note', $loc)) . '</th><th>'
                    . self::e(self::t('admin.common.status', $loc)) . '</th><th></th></tr>' . $rows . '</table>') . '</div></details>';
            }
        }
        $prodOpts = $products ? implode('', array_map(fn($p) => '<option value="' . self::e($p['id']) . '">' . self::e($p['name']) . '</option>', $products))
            : '<option value="">' . self::e(self::t('admin.inventory.no_products', $loc)) . '</option>';
        $invMap = self::inventoryByServerId();
        $srvOpts = '';
        try {
            foreach (ProviderManager::active()->listServers() as $s) {
                $sid = (int) ($s['id'] ?? 0);
                if (isset($invMap[$sid])) {
                    continue;
                }
                $srvOpts .= '<option value="' . self::e((string) $sid) . '">' . self::e($s['name'] ?? '') . '</option>';
            }
        } catch (\Throwable) {
            $srvOpts = '';
        }
        if ($srvOpts === '') {
            $srvOpts = '<option value="">' . self::e(self::t('admin.inventory.no_servers', $loc)) . '</option>';
        }
        $total = count(Database::listInventory());
        $quick = '<details class="card admin-panel" id="inventory-quick-panel" open>'
            . '<summary>' . self::e(self::t('admin.inventory.quick_add', $loc)) . '</summary>'
            . '<div class="panel-body"><form method="post" action="' . AdminPath::url('/products/inventory/add') . '">'
            . '<label>' . self::e(self::t('admin.products.plan_link', $loc)) . '</label><select name="product_id" required>' . $prodOpts . '</select>'
            . '<label>' . self::e(self::t('admin.common.server', $loc)) . '</label><select name="server_id" required>' . $srvOpts . '</select>'
            . '<label>' . self::e(self::t('admin.common.note_opt', $loc)) . '</label><input name="note" placeholder="' . self::e(self::t('admin.inventory.note_ph', $loc)) . '">'
            . '<button class="btn">' . self::e(self::t('admin.inventory.add_btn', $loc)) . '</button></form></div></details>';
        return $quick
            . '<div class="card"><h2>' . self::e(self::t('admin.inventory.overview', $loc, ['n' => $total])) . '</h2>'
            . $sections . '</div>';
    }

    /** @param array<string, mixed> $product */
    private static function productEditGet(Request $req, string $loc, array $product): void
    {
        $st = Database::getSettings();
        $cur = (string) ($st['currency'] ?? 'KWD');
        $body = '<div class="card"><h2>' . self::e(self::t('admin.products.edit_title', $loc)) . ': ' . self::e($product['name']) . '</h2>'
            . '<form method="post" action="' . AdminPath::url('/products/' . self::e($product['id']) . '/edit') . '">'
            . '<label>' . self::e(self::t('admin.common.name', $loc)) . '</label><input name="name" value="' . self::e($product['name']) . '" required>'
            . '<label>' . self::e(self::t('admin.products.price', $loc, ['currency' => $cur])) . '</label><input name="price" type="number" step="0.01" value="' . self::e($product['price']) . '" required>'
            . '<label>' . self::e(self::t('admin.products.duration', $loc)) . '</label><input name="months" type="number" value="' . self::e((string) ($product['months'] ?? 1)) . '" min="1">'
            . '<label>' . self::e(self::t('admin.products.specs_label', $loc)) . '</label>'
            . '<textarea name="desc" rows="6" placeholder="' . self::e(self::t('admin.products.desc_ph', $loc)) . '">' . self::e($product['desc'] ?? '') . '</textarea>'
            . '<p class="sub">' . self::e(self::t('admin.products.desc_hint', $loc)) . '</p>'
            . '<button class="btn">' . self::e(self::t('admin.common.save', $loc)) . '</button></form></div>';
        self::render($req, $loc, self::t('admin.products.edit_title', $loc), $body, 'products');
    }

    /** @param array<string, mixed> $product */
    private static function productServersGet(Request $req, string $loc, array $product): void
    {
        $assigned = self::assignedServerIds();
        $inventory = array_values(array_filter(Database::listInventory(), fn($i) => ($i['product_id'] ?? '') === $product['id']));
        $rows = '';
        $otherProducts = array_filter(Database::listProducts(), fn($p) => $p['id'] !== $product['id']);
        foreach ($inventory as $item) {
            $sid = (int) ($item['server_id'] ?? 0);
            $sold = isset($assigned[$sid]);
            $moveOpts = '';
            foreach ($otherProducts as $op) {
                $moveOpts .= '<option value="' . self::e($op['id']) . '">' . self::e($op['name']) . '</option>';
            }
            $rows .= '<tr><td class="mono">#' . self::e((string) $sid) . '</td><td>' . self::e($item['note'] ?? '') . '</td><td>'
                . AdminUi::badge(self::t($sold ? 'admin.badge.sold_stock' : 'admin.badge.available_stock', $loc), $sold ? 'b-exp' : 'b-ok') . '</td><td class="row">';
            if (!$sold && $moveOpts !== '') {
                $rows .= '<form method="post" action="' . AdminPath::url('/products/' . self::e($product['id']) . '/servers/' . self::e($item['id']) . '/move') . '" class="row" onsubmit="return confirm('
                    . "'" . self::e(self::t('admin.products.move_confirm', $loc)) . "'" . ')"><select name="target_product_id">' . $moveOpts . '</select>'
                    . '<button class="btn-sm">' . self::e(self::t('admin.common.move', $loc)) . '</button></form>';
            }
            $rows .= '<form method="post" action="' . AdminPath::url('/products/' . self::e($product['id']) . '/servers/' . self::e($item['id']) . '/remove') . '" style="display:inline" onsubmit="return confirm('
                . "'" . self::e(self::t('admin.products.remove_confirm', $loc)) . "'" . ')"><button class="btn-sm red">'
                . self::e(self::t('admin.common.remove', $loc)) . '</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="sub">' . self::e(self::t('admin.products.no_servers', $loc)) . '</td></tr>';
        }
        $invMap = self::inventoryByServerId();
        $hzOpts = '';
        try {
            foreach (ProviderManager::active()->listServers() as $s) {
                $sid = (int) ($s['id'] ?? 0);
                if (isset($invMap[$sid])) {
                    continue;
                }
                $hzOpts .= '<option value="' . self::e((string) $sid) . '" data-acc="' . self::e($s['_account_id'] ?? '') . '">'
                    . self::e($s['name'] ?? '') . ' [' . self::e($s['_account_name'] ?? '') . ']</option>';
            }
        } catch (\Throwable) {
            $hzOpts = '';
        }
        $addForm = $hzOpts !== ''
            ? '<div class="card"><h2>' . self::e(self::t('admin.products.add_from_hz', $loc)) . '</h2><form method="post" action="' . AdminPath::url('/products/' . self::e($product['id']) . '/servers/add') . '">'
                . '<label>' . self::e(self::t('admin.common.server', $loc)) . '</label><select name="server_id" required>' . $hzOpts . '</select>'
                . '<input type="hidden" name="hetzner_account_id" id="hz_acc" value="">'
                . '<label>' . self::e(self::t('admin.common.note_opt', $loc)) . '</label><input name="note" placeholder="' . self::e(self::t('admin.products.note_ph', $loc)) . '">'
                . '<button class="btn">' . self::e(self::t('admin.products.add_to_plan', $loc)) . '</button></form></div>'
            : '<div class="card sub">' . self::e(self::t('admin.products.all_assigned', $loc)) . '</div>';
        $body = '<p class="sub">' . self::e(self::t('admin.products.servers_sub', $loc, ['name' => $product['name']])) . '</p>'
            . '<p class="sub">' . self::e(self::t('admin.products.move_hint', $loc)) . '</p>' . $addForm
            . '<div class="card"><h2>' . self::e(self::t('admin.products.servers_head', $loc, ['n' => count($inventory)])) . '</h2><table><tr><th>'
            . self::e(self::t('admin.common.server', $loc)) . '</th><th>' . self::e(self::t('admin.common.note', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.status', $loc)) . '</th><th>' . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>' . $rows . '</table></div>';
        self::render($req, $loc, self::t('admin.products.title', $loc), $body, 'products');
    }

    private static function dispatchProductsPost(Request $req, string $loc, array $parts): void
    {
        if (($parts[1] ?? '') === 'ops') {
            self::productsOpsPost($req, $loc);
            return;
        }
        if (($parts[1] ?? '') === 'inventory' && ($parts[2] ?? '') === 'add') {
            self::inventoryAddPost($req, $loc);
            return;
        }
        if (($parts[1] ?? '') === 'add') {
            $p = Database::addProduct($req->body);
            ActivityLog::log('product', 'admin.act.product_added', ['name' => $p['name']]);
            self::redirectOk('/admin/products', 'admin.ok.product_added', ['name' => $p['name']]);
        }
        $id = $parts[1] ?? '';
        $product = Database::getProduct($id);
        if (!$product) {
            NotFound::admin($loc);
        }
        $action = $parts[2] ?? '';
        if ($action === 'edit') {
            Database::updateProduct($id, [
                'name' => trim((string) ($req->body['name'] ?? '')),
                'price' => (float) ($req->body['price'] ?? 0),
                'months' => (int) ($req->body['months'] ?? 1),
                'desc' => trim((string) ($req->body['desc'] ?? '')),
            ]);
            self::redirectOk('/admin/products/' . $id . '/edit', 'admin.ok.saved');
        }
        if ($action === 'toggle') {
            $active = ($product['active'] ?? true) !== false;
            Database::updateProduct($id, ['active' => !$active]);
            self::redirectOk('/admin/products', 'admin.ok.saved');
        }
        if ($action === 'delete') {
            Database::removeProduct($id);
            self::redirectOk('/admin/products', 'admin.ok.deleted');
        }
        if ($action === 'servers') {
            if (($parts[3] ?? '') === 'add') {
                $sid = (int) ($req->body['server_id'] ?? 0);
                if ($sid <= 0) {
                    self::redirectErr('/admin/products/' . $id . '/servers', $loc, 'admin.err.select_server');
                }
                $invMap = self::inventoryByServerId();
                if (isset($invMap[$sid])) {
                    self::redirectErr('/admin/products/' . $id . '/servers', $loc, 'admin.err.server_taken');
                }
                $accId = trim((string) ($req->body['hetzner_account_id'] ?? ''));
                if ($accId === '') {
                    try {
                        foreach (ProviderManager::active()->listServers() as $s) {
                            if ((int) ($s['id'] ?? 0) === $sid) {
                                $accId = (string) ($s['_account_id'] ?? '');
                                break;
                            }
                        }
                    } catch (\Throwable) {
                    }
                }
                Database::addInventory([
                    'product_id' => $id,
                    'server_id' => $sid,
                    'hetzner_account_id' => $accId,
                    'note' => trim((string) ($req->body['note'] ?? '')),
                ]);
                ActivityLog::log('product', 'admin.act.product_server_added', ['id' => $sid, 'name' => $product['name']]);
                StockAlert::checkAndNotify();
                self::redirectOk('/admin/products/' . $id . '/servers', 'admin.ok.server_added_plan');
            }
            $invId = $parts[3] ?? '';
            $subAction = $parts[4] ?? '';
            if ($subAction === 'move' && $invId !== '') {
                $item = null;
                foreach (Database::listInventory() as $i) {
                    if ($i['id'] === $invId) {
                        $item = $i;
                        break;
                    }
                }
                if (!$item || ($item['product_id'] ?? '') !== $id) {
                    self::redirectErr('/admin/products/' . $id . '/servers', $loc, 'admin.err.server_not_in_plan');
                }
                $sid = (int) ($item['server_id'] ?? 0);
                if (isset(self::assignedServerIds()[$sid])) {
                    self::redirectErr('/admin/products/' . $id . '/servers', $loc, 'admin.err.cannot_move_sold');
                }
                $targetId = (string) ($req->body['target_product_id'] ?? '');
                $target = Database::getProduct($targetId);
                if (!$target) {
                    self::redirectErr('/admin/products/' . $id . '/servers', $loc, 'admin.err.target_not_found');
                }
                if ($targetId === $id) {
                    self::redirectErr('/admin/products/' . $id . '/servers', $loc, 'admin.err.select_different_plan');
                }
                Database::updateInventory($invId, ['product_id' => $targetId]);
                ActivityLog::log('product', 'admin.act.product_server_moved', [
                    'id' => $sid, 'from' => $product['name'], 'to' => $target['name'],
                ]);
                self::redirectOk('/admin/products/' . $id . '/servers', 'admin.ok.server_moved', ['name' => $target['name']]);
            }
            if ($subAction === 'remove' && $invId !== '') {
                Database::removeInventory($invId);
                ActivityLog::log('product', 'admin.act.product_server_removed', ['id' => 0, 'name' => $product['name']]);
                self::redirectOk('/admin/products/' . $id . '/servers', 'admin.ok.server_removed_plan');
            }
        }
        NotFound::admin($loc);
    }

    // ── Inventory ───────────────────────────────────────────────────────────

    private static function dispatchInventoryPost(Request $req, string $loc, array $parts): void
    {
        if (($parts[1] ?? '') !== 'add') {
            NotFound::admin($loc);
        }
        self::inventoryAddPost($req, $loc);
    }

    private static function inventoryAddPost(Request $req, string $loc): void
    {
        $pid = trim((string) ($req->body['product_id'] ?? ''));
        $sid = (int) ($req->body['server_id'] ?? 0);
        if (!Database::getProduct($pid)) {
            self::redirectErr('/admin/products', $loc, 'admin.err.select_valid_plan');
        }
        if ($sid <= 0) {
            self::redirectErr('/admin/products', $loc, 'admin.err.select_server');
        }
        if (isset(self::inventoryByServerId()[$sid])) {
            self::redirectErr('/admin/products', $loc, 'admin.err.server_taken');
        }
        $product = Database::getProduct($pid);
        Database::addInventory([
            'product_id' => $pid,
            'server_id' => $sid,
            'hetzner_account_id' => Database::findAccountForServer($sid),
            'note' => trim((string) ($req->body['note'] ?? '')),
        ]);
        ActivityLog::log('inventory', 'admin.act.inventory_added', ['id' => $sid, 'name' => $product['name'] ?? '']);
        StockAlert::checkAndNotify();
        self::redirectOk('/admin/products', 'admin.ok.added_inventory');
    }

    // ── Invoices ────────────────────────────────────────────────────────────

    private static function dispatchInvoicesGet(Request $req, string $loc, array $parts): void
    {
        if (count($parts) === 1) {
            self::invoicesList($req, $loc);
            return;
        }
        if (($parts[2] ?? '') === 'print') {
            self::invoicePrint($req, $loc, $parts[1] ?? '');
            return;
        }
        NotFound::admin($loc);
    }

    private static function invoicesList(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $cur = (string) ($st['currency'] ?? 'KWD');
        $filterQ = [
            'q' => trim((string) ($req->query['q'] ?? '')),
            'filter' => (string) ($req->query['filter'] ?? 'all'),
        ];
        $perPage = AdminUi::PAGE_SIZE;
        $page = AdminUi::pageNum($req);
        $result = Database::listInvoicesPage([
            'q' => $filterQ['q'],
            'filter' => $filterQ['filter'],
            'page' => $page,
            'per_page' => $perPage,
        ]);
        $invoices = $result['items'];
        $filteredTotal = $result['total'];
        $page = $result['page'];
        $allTotal = Database::countInvoices();
        $rows = '';
        foreach ($invoices as $inv) {
            $client = Database::getClient((string) ($inv['client_id'] ?? ''));
            $paid = !empty($inv['paid']);
            $rows .= '<tr><td class="mono">' . self::e($inv['number'] ?? '') . '</td><td>' . self::e($client['name'] ?? $inv['buyer_name'] ?? '') . '</td><td>'
                . self::e($inv['desc'] ?? '') . '</td><td>' . self::e(number_format((float) ($inv['amount'] ?? 0), 2)) . ' ' . self::e($cur) . '</td><td>'
                . AdminUi::badge(self::t($paid ? 'admin.badge.paid' : 'admin.badge.unpaid', $loc), $paid ? 'b-ok' : 'b-warn') . '</td><td class="row">'
                . '<a class="btn-sm" href="' . AdminPath::url('/invoices/' . self::e($inv['id']) . '/print') . '" target="_blank">' . self::e(self::t('admin.common.print', $loc)) . '</a>';
            if (!$paid) {
                $months = (int) ($inv['months'] ?? 1);
                $rows .= '<form method="post" action="' . AdminPath::url('/invoices/' . self::e($inv['id']) . '/pay') . '" style="display:inline" onsubmit="return confirm('
                    . "'" . self::e(self::t('admin.invoices.pay_confirm', $loc, ['n' => $months])) . "'" . ')"><button class="btn-sm green">'
                    . self::e(self::t('admin.common.confirm_pay', $loc)) . '</button></form>';
            }
            $rows .= '<form method="post" action="' . AdminPath::url('/invoices/' . self::e($inv['id']) . '/delete') . '" style="display:inline" onsubmit="return confirm('
                . "'" . self::e(self::t('admin.invoices.delete_confirm', $loc)) . "'" . ')"><button class="btn-sm red">'
                . self::e(self::t('admin.common.delete', $loc)) . '</button></form></td></tr>';
        }
        if ($rows === '') {
            $msg = ($filterQ['q'] !== '' || $filterQ['filter'] !== 'all')
                ? self::t('admin.invoices.none_filtered', $loc)
                : self::t('admin.invoices.none', $loc);
            $rows = '<tr><td colspan="6" class="sub">' . self::e($msg) . '</td></tr>';
        }
        $clients = Database::listClients();
        $clientOpts = $clients ? implode('', array_map(fn($c) => '<option value="' . self::e($c['id']) . '">' . self::e($c['name']) . ' — ' . self::e($c['phone']) . '</option>', $clients))
            : '<option value="">' . self::e(self::t('admin.invoices.no_clients', $loc)) . '</option>';
        $prodOpts = '<option value="">' . self::e(self::t('admin.invoices.manual_opt', $loc)) . '</option>';
        foreach (Database::listProducts() as $p) {
            $prodOpts .= '<option value="' . self::e($p['id']) . '" data-price="' . self::e($p['price']) . '" data-months="' . self::e((string) $p['months']) . '">' . self::e($p['name']) . '</option>';
        }
        $form = '<div class="card"><h2>' . self::e(self::t('admin.invoices.new', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('admin.invoices.pay_hint', $loc)) . '</p><form method="post" action="' . AdminPath::url('/invoices/create') . '">'
            . '<label>' . self::e(self::t('admin.invoices.select_client', $loc)) . '</label><select name="client_id" required>' . $clientOpts . '</select>'
            . '<label>' . self::e(self::t('admin.invoices.select_product', $loc)) . '</label><select name="product_id">' . $prodOpts . '</select>'
            . '<label>' . self::e(self::t('admin.invoices.amount_manual', $loc, ['currency' => $cur])) . '</label><input name="amount" type="number" step="0.01">'
            . '<label>' . self::e(self::t('admin.invoices.months_manual', $loc)) . '</label><input name="months" type="number" value="1" min="1">'
            . '<label>' . self::e(self::t('admin.invoices.desc_opt', $loc)) . '</label><input name="desc" placeholder="' . self::e(self::t('admin.invoices.desc_ph', $loc)) . '">'
            . '<button class="btn">' . self::e(self::t('admin.invoices.create_btn', $loc)) . '</button></form></div>';
        $pgQuery = array_filter([
            'q' => $filterQ['q'] !== '' ? $filterQ['q'] : null,
            'filter' => $filterQ['filter'] !== 'all' ? $filterQ['filter'] : null,
        ], static fn($v) => $v !== null && $v !== '');
        $body = $form . '<div class="card"><h2>' . self::e(self::t('admin.invoices.list', $loc, ['n' => $allTotal])) . '</h2>'
            . AdminUi::invoicesFilterBar($loc, $filterQ, count($invoices), $filteredTotal, $allTotal)
            . AdminUi::tableWrap('<table><tr><th>'
            . self::e(self::t('admin.invoices.number', $loc)) . '</th><th>' . self::e(self::t('admin.common.client', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.desc', $loc)) . '</th><th>' . self::e(self::t('admin.common.amount', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.status', $loc)) . '</th><th>' . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>' . $rows . '</table>')
            . AdminUi::paginationBar($loc, $page, $perPage, $filteredTotal, '/invoices', $pgQuery)
            . '</div>';
        self::render($req, $loc, self::t('admin.invoices.title', $loc), $body, 'invoices');
    }

    private static function invoicePrint(Request $req, string $loc, string $id): void
    {
        $inv = Database::getInvoice($id);
        if (!$inv) {
            NotFound::admin($loc);
        }
        $st = Database::getSettings();
        $client = Database::getClient((string) ($inv['client_id'] ?? ''));
        Response::html(InvoiceUi::printPage($inv, $client, $st, $loc));
    }

    private static function dispatchInvoicesPost(Request $req, string $loc, array $parts): void
    {
        if (($parts[1] ?? '') === 'create') {
            $clientId = trim((string) ($req->body['client_id'] ?? ''));
            $client = Database::getClient($clientId);
            if (!$client) {
                self::redirectErr('/admin/invoices', $loc, 'admin.err.select_client');
            }
            $productId = trim((string) ($req->body['product_id'] ?? ''));
            $amount = (float) ($req->body['amount'] ?? 0);
            $months = (int) ($req->body['months'] ?? 1);
            $desc = trim((string) ($req->body['desc'] ?? ''));
            if ($productId !== '') {
                $p = Database::getProduct($productId);
                if ($p) {
                    $amount = (float) $p['price'];
                    $months = (int) $p['months'];
                    if ($desc === '') {
                        $desc = $p['name'];
                    }
                }
            }
            if ($amount <= 0) {
                self::redirectErr('/admin/invoices', $loc, 'admin.err.not_found_f');
            }
            $inv = Database::addInvoice([
                'client_id' => $clientId,
                'desc' => $desc ?: self::t('admin.invoices.default_desc', $loc),
                'amount' => $amount,
                'months' => $months,
                'product_id' => $productId,
                'source' => 'admin',
                'buyer_name' => $client['name'],
                'phone' => $client['phone'],
            ]);
            ActivityLog::log('invoice', 'admin.act.invoice_created', [
                'number' => $inv['number'], 'name' => $client['name'], 'amount' => $amount,
            ]);
            self::redirectOk('/admin/invoices', 'admin.ok.invoice_created', ['number' => $inv['number']]);
        }
        $id = $parts[1] ?? '';
        $action = $parts[2] ?? '';
        $inv = Database::getInvoice($id);
        if (!$inv) {
            NotFound::admin($loc);
        }
        if ($action === 'pay') {
            if (!empty($inv['paid'])) {
                self::redirectErr('/admin/invoices', $loc, 'admin.err.invoice_not_found');
            }
            $r = StoreController::activateInvoice($id);
            if (!$r) {
                self::redirectErr('/admin/invoices', $loc, 'admin.err.invoice_not_found');
            }
            $client = $r['client'] ?? Database::getClient((string) ($inv['client_id'] ?? ''));
            if (empty($r['already'])) {
                ActivityLog::log('payment', 'admin.act.payment_confirmed', [
                    'number' => $inv['number'],
                    'name' => $client['name'] ?? '',
                    'expires' => $client['expires'] ?? '',
                ]);
            }
            self::redirectOk('/admin/invoices', 'admin.ok.invoice_paid', [
                'name' => $client['name'] ?? '',
                'expires' => $client['expires'] ?? '',
            ]);
        }
        if ($action === 'delete') {
            Database::removeInvoice($id);
            self::redirectOk('/admin/invoices', 'admin.ok.invoice_deleted');
        }
        NotFound::admin($loc);
    }

    // ── Payments ────────────────────────────────────────────────────────────

    private static function paymentsGet(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $hasSecret = ($st['paypal_secret'] ?? '') !== '';
        $mode = ($st['paypal_mode'] ?? '') === 'live' ? 'live' : 'sandbox';
        $webhookOn = !empty($st['paypal_webhook_id']);
        $paypalReady = PayPal::configured();
        $ppCurrency = strtoupper((string) ($st['paypal_currency'] ?? 'USD'));
        $currencyOk = PayPal::isCurrencySupported($ppCurrency);
        $webhookUrl = '';
        try {
            $webhookUrl = PayPal::webhookUrl($req);
        } catch (\Throwable) {
        }
        $saveUrl = AdminPath::url('/payments');
        $testUrl = AdminPath::url('/payments/test');
        $hookUrl = AdminPath::url('/payments/webhook');
        $body = '<div class="card"><h2>' . self::e(self::t('admin.payments.head', $loc)) . '</h2>'
            . '<form method="post" action="' . $saveUrl . '">'
            . '<label>' . self::e(self::t('admin.payments.manual_label', $loc)) . '</label><textarea name="manual_payment_instructions" placeholder="'
            . self::e(self::t('admin.payments.manual_ph', $loc)) . '">' . self::e($st['manual_payment_instructions'] ?? '') . '</textarea>'
            . '<h2 style="margin-top:20px">' . self::e(self::t('admin.payments.paypal_head', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('admin.payments.paypal_hint', $loc)) . '</p>'
            . '<p style="margin:10px 0">'
            . AdminUi::badge(self::t($paypalReady ? 'admin.payments.status_ready' : 'admin.payments.status_incomplete', $loc), $paypalReady ? 'b-ok' : 'b-warn')
            . ' ' . AdminUi::badge($mode === 'live' ? 'Live' : 'Sandbox', $mode === 'live' ? 'b-warn' : 'b-ok')
            . ($currencyOk ? '' : ' ' . AdminUi::badge(self::t('admin.payments.currency_bad', $loc, ['code' => $ppCurrency]), 'b-exp'))
            . '</p>'
            . '<label>Client ID</label><input name="paypal_client_id" dir="ltr" autocomplete="off" value="' . self::e($st['paypal_client_id'] ?? '') . '">'
            . '<label>' . self::e(self::t('admin.payments.secret_label', $loc)) . '</label><input name="paypal_secret" type="password" dir="ltr" autocomplete="new-password" placeholder="'
            . self::e(self::t($hasSecret ? 'admin.payments.secret_ph_saved' : 'admin.payments.secret_ph_new', $loc)) . '">'
            . '<p class="sub">' . self::e(self::t('admin.payments.secret_hint', $loc)) . '</p>'
            . '<label>' . self::e(self::t('admin.payments.mode', $loc)) . '</label><select name="paypal_mode">'
            . '<option value="sandbox"' . ($mode === 'sandbox' ? ' selected' : '') . '>' . self::e(self::t('admin.payments.mode_sandbox', $loc)) . '</option>'
            . '<option value="live"' . ($mode === 'live' ? ' selected' : '') . '>' . self::e(self::t('admin.payments.mode_live', $loc)) . '</option></select>'
            . '<label>' . self::e(self::t('admin.payments.currency', $loc)) . '</label><input name="paypal_currency" dir="ltr" value="' . self::e($ppCurrency) . '">'
            . '<p class="sub">' . self::e(self::t('admin.payments.currency_hint', $loc)) . '</p>'
            . '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px">'
            . '<button type="submit" class="btn">' . self::e(self::t('admin.common.save', $loc)) . '</button>'
            . '<button type="submit" class="btn gray" formaction="' . $testUrl . '">' . self::e(self::t('admin.payments.test_btn', $loc)) . '</button>'
            . '<button type="submit" class="btn" formaction="' . $hookUrl . '">' . self::e(self::t('admin.payments.webhook_register_btn', $loc)) . '</button>'
            . '</div></form></div>'
            . '<div class="card"><h2>' . self::e(self::t('admin.payments.webhook_head', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('admin.payments.webhook_hint', $loc)) . '</p><p>'
            . AdminUi::badge(self::t($webhookOn ? 'admin.payments.webhook_on' : 'admin.payments.webhook_off', $loc), $webhookOn ? 'b-ok' : 'b-warn') . '</p>';
        if ($webhookUrl !== '') {
            $body .= '<p class="sub" dir="ltr"><b>URL:</b> <span class="mono">' . self::e($webhookUrl) . '</span></p>';
        } else {
            $body .= '<p class="warn">' . self::e(self::t('admin.payments.webhook_no_domain', $loc)) . '</p>';
        }
        if (!empty($st['paypal_webhook_id'])) {
            $body .= '<p class="sub" dir="ltr"><b>ID:</b> <span class="mono">' . self::e((string) $st['paypal_webhook_id']) . '</span></p>';
        }
        $body .= '</div>';
        self::render($req, $loc, self::t('admin.payments.title', $loc), $body, 'payments');
    }

    private static function dispatchPaymentsPost(Request $req, string $loc, array $parts): void
    {
        $action = $parts[1] ?? '';
        if ($action === '') {
            $st = Database::getSettings();
            $patch = [
                'manual_payment_instructions' => trim((string) ($req->body['manual_payment_instructions'] ?? '')),
                'payment_gateway' => 'paypal',
                'paypal_client_id' => trim((string) ($req->body['paypal_client_id'] ?? '')),
                'paypal_mode' => ($req->body['paypal_mode'] ?? '') === 'live' ? 'live' : 'sandbox',
                'paypal_currency' => strtoupper(trim((string) ($req->body['paypal_currency'] ?? 'USD'))),
            ];
            $secret = trim((string) ($req->body['paypal_secret'] ?? ''));
            if ($secret !== '') {
                $patch['paypal_secret'] = $secret;
            }
            Database::saveSettings($patch);
            ActivityLog::log('settings', 'admin.act.payments_updated');
            self::redirectOk('/admin/payments', 'admin.ok.paypal_saved');
        }
        if ($action === 'test') {
            try {
                $r = PayPal::testConnection(PayPal::overridesFromRequest($req));
                ActivityLog::log('payment', 'admin.act.paypal_ok');
                $extra = ['mode' => $r['modeLabel']];
                if (!$r['currencyOk']) {
                    self::redirectErr('/admin/payments', $loc, 'admin.err.paypal_currency', ['code' => $r['currency']]);
                }
                self::redirectOk('/admin/payments', 'admin.ok.paypal_test_ok', $extra);
            } catch (\Throwable $e) {
                self::redirectErr('/admin/payments', $loc, 'admin.err.paypal_test_failed', ['msg' => PayPal::explain($e)]);
            }
        }
        if ($action === 'webhook') {
            try {
                PayPal::ensureWebhook($req);
                self::redirectOk('/admin/payments', 'admin.ok.webhook_registered');
            } catch (\Throwable $e) {
                self::redirectErr('/admin/payments', $loc, 'admin.err.webhook_failed', ['msg' => PayPal::explain($e)]);
            }
        }
        NotFound::admin($loc);
    }

    // ── Telegram ────────────────────────────────────────────────────────────

    private static function telegramGet(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $token = Config::get('TELEGRAM_BOT_TOKEN');
        $hasToken = $token !== '';
        $webhookDomain = Config::get('WEBHOOK_DOMAIN') ?: ($st['store_url'] ?? '');
        if ($webhookDomain === '' || !Telegram::isPublicWebhookUrl($webhookDomain)) {
            $webhookDomain = Telegram::isPublicWebhookUrl($req->baseUrl()) ? $req->baseUrl() : '';
        }
        $secret = Config::get('WEBHOOK_SECRET');
        $webhookUrl = ($webhookDomain !== '' && $secret !== '') ? Telegram::buildWebhookUrl($webhookDomain, $secret) : '';

        $status = '';
        $webhookStatus = '';
        if ($hasToken) {
            try {
                $me = Telegram::getMe();
                $info = Telegram::getWebhookInfo();
                $whUrl = (string) ($info['url'] ?? '');
                $pending = (int) ($info['pending_update_count'] ?? 0);
                $lastErr = (string) ($info['last_error_message'] ?? '');
                $status = self::t('admin.telegram.connected', $loc, [
                    'username' => $me['username'] ?? '',
                    'firstName' => $me['first_name'] ?? '',
                    'mode' => 'Webhook',
                ]);
                if ($whUrl !== '') {
                    $webhookStatus = self::t('admin.telegram.webhook_ok', $loc, [
                        'url' => $whUrl,
                        'pending' => (string) $pending,
                    ]);
                    if ($lastErr !== '') {
                        $webhookStatus .= '<div class="warn">' . self::e(self::t('admin.telegram.webhook_last_error', $loc, ['msg' => $lastErr])) . '</div>';
                    }
                } else {
                    $webhookStatus = '<div class="warn">' . self::e(self::t('admin.telegram.webhook_not_set', $loc)) . '</div>';
                }
            } catch (\Throwable $e) {
                $status = self::t('admin.telegram.disconnected', $loc, ['error' => ': ' . $e->getMessage()]);
            }
        } else {
            $status = self::t('admin.telegram.disconnected', $loc, ['error' => '']);
        }

        $mask = $hasToken ? substr($token, 0, 6) . '…' . substr($token, -4) : '';
        $linked = count(array_filter(Database::listClients(), fn($c) => !empty($c['telegram_id']) && ($c['active'] ?? true) !== false));

        $setupSteps = '<ol class="sub" style="margin:12px 0 16px 20px;line-height:1.8">'
            . '<li>' . self::t('admin.telegram.step_token', $loc) . '</li>'
            . '<li>' . self::t('admin.telegram.step_admin_id', $loc) . '</li>'
            . '<li>' . self::t('admin.telegram.step_webhook', $loc) . '</li>'
            . '<li>' . self::t('admin.telegram.step_connect', $loc) . '</li></ol>';

        $body = '<div class="card"><h2>' . self::e(self::t('admin.telegram.status_head', $loc)) . '</h2>'
            . $setupSteps . '<p>' . $status . '</p>' . $webhookStatus
            . ($webhookUrl !== '' ? '<p class="sub">' . self::e(self::t('admin.telegram.webhook_preview', $loc)) . ': <span class="mono">'
                . self::e($webhookUrl) . '</span></p>' : '')
            . '<form method="post" action="' . AdminPath::url('/telegram/connect') . '">'
            . '<label>' . self::e(self::t('admin.telegram.token_label', $loc)) . '</label>'
            . '<input name="token" placeholder="' . self::e(self::t($hasToken ? 'admin.telegram.token_ph_keep' : 'admin.telegram.token_ph_new', $loc)) . '">'
            . ($hasToken ? '<p class="sub">' . self::e(self::t('admin.telegram.token_saved', $loc, ['mask' => $mask])) . '</p>' : '')
            . '<label>' . self::e(self::t('admin.telegram.chat_id', $loc)) . '</label>'
            . '<input name="admin_id" value="' . self::e(Config::get('ADMIN_IDS')) . '">'
            . '<p class="sub">' . self::e(self::t('admin.telegram.chat_id_hint', $loc)) . '</p>'
            . '<label>' . self::e(self::t('admin.telegram.webhook_domain', $loc)) . '</label>'
            . '<input name="webhook_domain" value="' . self::e($webhookDomain) . '" placeholder="https://yourdomain.com">'
            . '<p class="sub">' . self::e(self::t('admin.telegram.webhook_domain_hint', $loc)) . '</p>'
            . '<button class="btn">' . self::e(self::t($hasToken ? 'admin.telegram.save_run' : 'admin.telegram.connect_bot', $loc)) . '</button></form>';
        if ($hasToken) {
            $body .= '<div class="row" style="margin-top:14px">'
                . '<form method="post" action="' . AdminPath::url('/telegram/register-commands') . '" style="display:inline"><button class="btn gray">'
                . self::e(self::t('admin.telegram.register_commands', $loc)) . '</button></form>'
                . '<form method="post" action="' . AdminPath::url('/telegram/disconnect') . '" style="display:inline" onsubmit="return confirm('
                . "'" . self::e(self::t('admin.telegram.disconnect_confirm', $loc)) . "'" . ')"><button class="btn red">'
                . self::e(self::t('admin.telegram.disconnect_btn', $loc)) . '</button></form></div>';
        }
        $body .= '</div>';

        $body .= '<div class="card" id="bot-commands"><h2>' . self::e(self::t('admin.telegram.commands_head', $loc)) . '</h2>'
            . '<p class="sub">' . self::e(self::t('admin.telegram.commands_edit_sub', $loc)) . '</p>'
            . BotCommands::commandsFormHtml($loc, $st['bot_commands'] ?? [])
            . BotCommands::adminInlineButtonsHtml($loc)
            . '<p class="sub">' . self::e(self::t('admin.telegram.commands_inline', $loc)) . '</p></div>';
        $botTexts = $st['bot_texts'] ?? [];
        $textForm = self::telegramTextsFormHtml($loc, $botTexts);
        $btnForm = self::telegramButtonsFormHtml($req, $loc, $st);
        $osForm = '<div class="card"><h2>' . self::e(self::t('admin.telegram.os_head', $loc)) . '</h2>'
            . '<div class="warn">' . self::t('admin.telegram.os_warn', $loc) . '</div><p class="sub">'
            . self::e(self::t('admin.telegram.os_sub', $loc)) . '</p>';
        $allowed = $st['bot_os_images'] ?? [];
        try {
            $images = ProviderManager::active()->listImages();
            $osForm .= '<form method="post" action="' . AdminPath::url('/telegram/os') . '">';
            foreach ($images as $img) {
                $iid = (int) ($img['id'] ?? 0);
                $checked = $allowed === [] || in_array($iid, (array) $allowed, true);
                $osForm .= '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="os[]" value="' . self::e((string) $iid) . '" '
                    . ($checked ? 'checked' : '') . '> ' . self::e($img['name'] ?? '') . ' — ' . self::e($img['description'] ?? '') . '</label>';
            }
            $osForm .= '<button class="btn">' . self::e(self::t('admin.telegram.os_save', $loc)) . '</button></form>'
                . '<form method="post" action="' . AdminPath::url('/telegram/os-reset') . '" style="margin-top:10px"><button class="btn gray">'
                . self::e(self::t('admin.telegram.os_reset', $loc)) . '</button></form>';
        } catch (\Throwable $e) {
            $osForm .= '<p class="err">' . self::e(self::t('admin.telegram.os_none', $loc)) . ': ' . self::e(ProviderManager::active()->explain($e)) . '</p>';
        }
        $osForm .= '</div>';
        $body .= $textForm . $btnForm . $osForm
            . '<div class="card"><h2>' . self::e(self::t('admin.telegram.broadcast_head', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('admin.telegram.broadcast_sub', $loc, ['n' => $linked])) . '</p>'
            . '<form method="post" action="' . AdminPath::url('/telegram/broadcast') . '" onsubmit="return confirm('
            . "'" . self::e(self::t('admin.telegram.broadcast_confirm', $loc, ['n' => $linked])) . "'" . ')">'
            . '<label>' . self::e(self::t('admin.telegram.broadcast_label', $loc)) . '</label><textarea name="text" required></textarea>'
            . '<button class="btn">' . self::e(self::t('admin.telegram.broadcast_btn', $loc)) . '</button></form></div>';
        self::render($req, $loc, self::t('admin.telegram.title', $loc), $body, 'telegram');
    }

    private static function dispatchTelegramPost(Request $req, string $loc, array $parts): void
    {
        $action = $parts[1] ?? '';
        match ($action) {
            'connect' => self::telegramConnectPost($req, $loc),
            'register-commands' => self::telegramRegisterCommandsPost($loc),
            'disconnect' => self::telegramDisconnectPost($loc),
            'texts' => self::telegramTextsPost($req, $loc),
            'texts-reset' => self::telegramTextsResetPost($loc),
            'commands' => self::telegramCommandsPost($req, $loc),
            'commands-reset' => self::telegramCommandsResetPost($loc),
            'buttons' => self::telegramButtonsPost($req, $loc),
            'os' => self::telegramOsPost($req, $loc),
            'os-reset' => self::telegramOsResetPost($loc),
            'broadcast' => self::telegramBroadcastPost($req, $loc),
            default => NotFound::admin($loc),
        };
    }

    private static function telegramConnectPost(Request $req, string $loc): void
    {
        $token = trim((string) ($req->body['token'] ?? ''));
        $adminId = trim((string) ($req->body['admin_id'] ?? ''));
        $webhookDomain = rtrim(trim((string) ($req->body['webhook_domain'] ?? '')), '/');

        if ($adminId === '') {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.chat_id_required');
        }
        if (!ctype_digit($adminId)) {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.chat_id_invalid');
        }
        if ($token === '') {
            $token = Config::get('TELEGRAM_BOT_TOKEN');
        }
        if ($token === '') {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.token_required');
        }
        if ($webhookDomain === '') {
            $webhookDomain = Config::get('WEBHOOK_DOMAIN') ?: $req->baseUrl();
        }
        if (!Telegram::isPublicWebhookUrl($webhookDomain)) {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.webhook_https_required');
        }

        try {
            $me = Telegram::verifyToken($token);
            $secret = Config::get('WEBHOOK_SECRET') ?: bin2hex(random_bytes(8));
            $webhookUrl = Telegram::buildWebhookUrl($webhookDomain, $secret);

            EnvFile::update([
                'TELEGRAM_BOT_TOKEN' => $token,
                'ADMIN_IDS' => $adminId,
                'WEBHOOK_DOMAIN' => $webhookDomain,
                'WEBHOOK_SECRET' => $secret,
            ]);

            Telegram::setWebhookWithToken($token, $webhookUrl, $secret);
            $setupWarn = BotSetup::apply($token, $adminId);

            $info = Telegram::getWebhookInfoWithToken($token);
            if (empty($info['url'])) {
                throw new \RuntimeException(self::t('admin.err.webhook_not_registered', $loc));
            }

            Database::saveSettings([
                'telegram_link' => 'https://t.me/' . ($me['username'] ?? ''),
                'store_url' => Database::getSettings()['store_url'] ?? $webhookDomain,
            ]);
            ActivityLog::log('bot', 'admin.act.bot_connected', ['user' => $me['username'] ?? '', 'id' => $adminId]);
            if ($setupWarn === 'admin_start_required') {
                self::go('/admin/telegram?ok=' . rawurlencode(self::t('admin.ok.bot_connected', $loc, ['username' => $me['username'] ?? '']))
                    . ' ' . self::t('admin.ok.bot_connected_admin_start', $loc));
            }
            if ($setupWarn !== '') {
                self::go('/admin/telegram?ok=' . rawurlencode(self::t('admin.ok.bot_connected', $loc, ['username' => $me['username'] ?? '']))
                    . ' (' . $setupWarn . ')');
            }
            self::redirectOk('/admin/telegram', 'admin.ok.bot_connected', ['username' => $me['username'] ?? '']);
        } catch (\Throwable $e) {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.bot_connect_failed', ['msg' => $e->getMessage()]);
        }
    }

    private static function telegramRegisterCommandsPost(string $loc): void
    {
        $token = Config::get('TELEGRAM_BOT_TOKEN');
        if ($token === '') {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.token_required');
        }
        try {
            BotSetup::apply($token, trim(explode(',', Config::get('ADMIN_IDS'))[0] ?? ''));
            self::redirectOk('/admin/telegram', 'admin.ok.commands_registered');
        } catch (\Throwable $e) {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.commands_failed', ['msg' => $e->getMessage()]);
        }
    }

    private static function telegramDisconnectPost(string $loc): void
    {
        try {
            Telegram::deleteWebhook();
        } catch (\Throwable) {
        }
        EnvFile::update(['TELEGRAM_BOT_TOKEN' => '']);
        ActivityLog::log('bot', 'admin.act.bot_disconnected');
        self::redirectOk('/admin/telegram', 'admin.ok.bot_disconnected');
    }

    private static function telegramTextsPost(Request $req, string $loc): void
    {
        $texts = [];
        foreach (BotTexts::allTextKeys() as $key) {
            $en = trim((string) ($req->body['text_' . $key . '_en'] ?? ''));
            $ar = trim((string) ($req->body['text_' . $key . '_ar'] ?? ''));
            if ($en === BotTexts::defaultText($key, 'en')) {
                $en = '';
            }
            if ($ar === BotTexts::defaultText($key, 'ar')) {
                $ar = '';
            }
            $texts[$key] = ['en' => $en, 'ar' => $ar];
        }
        Database::saveSettings(['bot_texts' => $texts]);
        ActivityLog::log('bot', 'admin.act.bot_texts_updated');
        $token = Config::get('TELEGRAM_BOT_TOKEN');
        if ($token !== '') {
            try {
                BotSetup::syncProfile($token);
            } catch (\Throwable) {
            }
        }
        self::redirectOk('/admin/telegram', 'admin.ok.texts_saved');
    }

    private static function telegramTextsResetPost(string $loc): void
    {
        Database::saveSettings(['bot_texts' => []]);
        ActivityLog::log('bot', 'admin.act.bot_texts_reset');
        self::redirectOk('/admin/telegram', 'admin.ok.texts_reset');
    }

    private static function telegramCommandsPost(Request $req, string $loc): void
    {
        $commands = [];
        foreach (BotCommands::allCommands() as $c) {
            $cmd = $c['command'];
            $en = trim((string) ($req->body['cmd_' . $cmd . '_en'] ?? ''));
            $ar = trim((string) ($req->body['cmd_' . $cmd . '_ar'] ?? ''));
            if ($en === BotCommands::defaultDescription($cmd, 'en')) {
                $en = '';
            }
            if ($ar === BotCommands::defaultDescription($cmd, 'ar')) {
                $ar = '';
            }
            $commands[$cmd] = ['en' => Str::limit($en, 256), 'ar' => Str::limit($ar, 256)];
        }
        Database::saveSettings(['bot_commands' => $commands]);
        ActivityLog::log('bot', 'admin.act.bot_commands_updated');
        $token = Config::get('TELEGRAM_BOT_TOKEN');
        if ($token !== '') {
            try {
                BotCommands::sync($token);
            } catch (\Throwable) {
            }
        }
        self::redirectOk('/admin/telegram', 'admin.ok.commands_saved');
    }

    private static function telegramCommandsResetPost(string $loc): void
    {
        Database::saveSettings(['bot_commands' => []]);
        ActivityLog::log('bot', 'admin.act.bot_commands_reset');
        $token = Config::get('TELEGRAM_BOT_TOKEN');
        if ($token !== '') {
            try {
                BotCommands::sync($token);
            } catch (\Throwable) {
            }
        }
        self::redirectOk('/admin/telegram', 'admin.ok.commands_reset');
    }

    private static function telegramButtonsPost(Request $req, string $loc): void
    {
        $buttons = [];
        foreach (BotTexts::CLIENT_BTN_KEYS as $key) {
            $en = trim((string) ($req->body['btn_' . $key . '_en'] ?? ''));
            $ar = trim((string) ($req->body['btn_' . $key . '_ar'] ?? ''));
            $defEn = BotTexts::defaultClientBtn($key, 'en');
            $defAr = BotTexts::defaultClientBtn($key, 'ar');
            if ($en === $defEn) {
                $en = '';
            }
            if ($ar === $defAr) {
                $ar = '';
            }
            $buttons[$key] = [
                'enabled' => $key === 'language' ? true : isset($req->body['btn_' . $key . '_enabled']),
                'en' => $en,
                'ar' => $ar,
            ];
        }
        $adminButtons = [];
        foreach (BotTexts::ADMIN_BTN_KEYS as $key) {
            $en = trim((string) ($req->body['admin_btn_' . $key . '_en'] ?? ''));
            $ar = trim((string) ($req->body['admin_btn_' . $key . '_ar'] ?? ''));
            $defEn = BotTexts::defaultAdminBtn($key, 'en');
            $defAr = BotTexts::defaultAdminBtn($key, 'ar');
            if ($en === $defEn) {
                $en = '';
            }
            if ($ar === $defAr) {
                $ar = '';
            }
            $adminButtons[$key] = ['en' => $en, 'ar' => $ar];
        }
        Database::saveSettings([
            'bot_buttons' => $buttons,
            'bot_admin_buttons' => $adminButtons,
            'store_url' => trim((string) ($req->body['store_url'] ?? '')),
        ]);
        ActivityLog::log('bot', 'admin.act.bot_buttons_updated');
        self::redirectOk('/admin/telegram', 'admin.ok.bot_settings_saved');
    }

    private static function telegramOsPost(Request $req, string $loc): void
    {
        $os = array_values(array_unique(array_map('intval', (array) ($req->body['os'] ?? []))));
        Database::saveSettings(['bot_os_images' => $os]);
        ActivityLog::log('bot', 'admin.act.bot_os_updated', ['n' => count($os), 'total' => count($os)]);
        self::redirectOk('/admin/telegram', 'admin.ok.os_saved', ['n' => count($os)]);
    }

    private static function telegramOsResetPost(string $loc): void
    {
        Database::saveSettings(['bot_os_images' => []]);
        ActivityLog::log('bot', 'admin.act.bot_os_reset');
        self::redirectOk('/admin/telegram', 'admin.ok.os_all');
    }

    private static function telegramBroadcastPost(Request $req, string $loc): void
    {
        if (Config::get('TELEGRAM_BOT_TOKEN') === '') {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.bot_offline_short');
        }
        $text = trim((string) ($req->body['text'] ?? ''));
        if ($text === '') {
            self::redirectErr('/admin/telegram', $loc, 'admin.err.broadcast_empty');
        }
        $sent = 0;
        $failed = 0;
        foreach (Database::listClients() as $c) {
            if (empty($c['telegram_id']) || ($c['active'] ?? true) === false) {
                continue;
            }
            try {
                Telegram::sendMessage((string) $c['telegram_id'], $text);
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }
        $failSuffix = $failed > 0 ? self::t('admin.ok.broadcast_failed_suffix', $loc, ['n' => $failed]) : '';
        ActivityLog::log('broadcast', 'admin.act.broadcast_sent', ['sent' => $sent, 'failed' => $failSuffix]);
        self::redirectOk('/admin/telegram', 'admin.ok.broadcast_sent', ['sent' => $sent, 'failed' => $failSuffix]);
    }

    // ── Hetzner ─────────────────────────────────────────────────────────────

    private static function dispatchHetznerGet(Request $req, string $loc, array $parts): void
    {
        if (($parts[1] ?? '') === 'password') {
            $flash = \App\Services\AdminFlash::pull($req->cookie('q8admin'), 'hetzner_pw');
            if (!is_array($flash) || ($flash['pw'] ?? '') === '') {
                Response::redirect(AdminPath::to(''));
            }
            $pw = (string) $flash['pw'];
            $name = (string) ($flash['name'] ?? '');
            $body = '<div class="card"><h2>' . self::e(self::t('admin.hetzner.pw_head', $loc)) . '</h2>'
                . ($name !== '' ? '<p>' . self::e($name) . '</p>' : '')
                . '<div class="warn">' . self::e(self::t('admin.hetzner.pw_warn', $loc)) . '</div>'
                . '<p class="mono" style="font-size:20px;padding:14px;background:#0d1a2c;border-radius:8px">' . self::e($pw) . '</p>'
                . '<a class="btn" href="' . AdminPath::url('/hetzner') . '">' . self::e(self::t('admin.hetzner.pw_back', $loc)) . '</a></div>';
            self::render($req, $loc, self::t('admin.hetzner.pw_title', $loc), $body, 'hetzner');
            return;
        }
        self::hetznerGet($req, $loc);
    }

    private static function hetznerGet(Request $req, string $loc): void
    {
        $hz = ProviderManager::get('hetzner') ?? ProviderManager::active();
        $accounts = $hz->getAccounts();
        $enabled = $hz->enabledAccounts();
        $status = '';
        if ($enabled === []) {
            $status = '<div class="warn">' . self::e(self::t('admin.hetzner.no_projects', $loc)) . '</div>'
                . '<p class="sub">' . self::e(self::t('admin.hetzner.no_projects_hint', $loc)) . '</p>';
        } else {
            $names = implode(', ', array_map(fn($a) => $a['name'] ?? '', $enabled));
            $total = 0;
            $apiErr = '';
            try {
                $total = count($hz->listServers());
            } catch (\Throwable $e) {
                $apiErr = $hz->explain($e);
            }
            if ($apiErr !== '') {
                $status = '<div class="err">' . self::e($apiErr) . '</div>';
            } else {
                $status = '<p>' . self::t('admin.hetzner.projects_ok', $loc, ['n' => count($enabled), 'names' => $names, 'total' => $total]) . '</p>';
            }
        }
        $connRows = '';
        foreach ($accounts as $a) {
            $on = ($a['enabled'] ?? true) !== false;
            $connRows .= '<tr><td>' . self::e($a['name'] ?? '') . '</td><td>'
                . AdminUi::badge(self::t($on ? 'admin.badge.conn_on' : 'admin.badge.conn_off', $loc), $on ? 'b-ok' : 'b-muted') . '</td><td class="row">'
                . '<form method="post" action="' . AdminPath::url('/hetzner/' . self::e($a['id'] ?? '') . '/toggle') . '" style="display:inline"><button class="btn-sm">'
                . self::e(self::t($on ? 'admin.common.toggle_off' : 'admin.common.toggle_on', $loc)) . '</button></form>'
                . '<form method="post" action="' . AdminPath::url('/hetzner/' . self::e($a['id'] ?? '') . '/delete') . '" style="display:inline" onsubmit="return confirm('
                . "'" . self::e(self::t('admin.hetzner.delete_conn_confirm', $loc, ['name' => $a['name'] ?? ''])) . "'" . ')"><button class="btn-sm red">'
                . self::e(self::t('admin.common.delete', $loc)) . '</button></form></td></tr>';
        }
        if ($connRows === '') {
            $connRows = '<tr><td colspan="3" class="sub">' . self::e(self::t('admin.hetzner.no_connections', $loc)) . '</td></tr>';
        }
        $srvRows = '';
        try {
            foreach ($hz->listServers() as $s) {
                $sid = (int) ($s['id'] ?? 0);
                $ip = $s['public_net']['ipv4']['ip'] ?? '';
                $stype = $s['server_type']['name'] ?? '';
                $statusBadge = ($s['status'] ?? '') === 'running'
                    ? AdminUi::badge(self::t('admin.badge.running', $loc), 'b-ok')
                    : AdminUi::badge(self::t('admin.badge.stopped', $loc, ['status' => $s['status'] ?? '']), 'b-exp');
                $srvRows .= '<tr><td>' . self::e($s['name'] ?? '') . '</td><td class="mono">' . self::e($ip) . '</td><td>'
                    . self::e($stype) . '</td><td>' . $statusBadge . '</td><td>' . self::e($s['_account_name'] ?? '') . '</td><td class="row">'
                    . '<form method="post" action="' . AdminPath::url('/hetzner/servers/' . self::e((string) $sid) . '/power') . '" style="display:inline"><input type="hidden" name="action" value="on">'
                    . '<input type="hidden" name="account_id" value="' . self::e($s['_account_id'] ?? '') . '"><button class="btn-sm green">'
                    . self::e(self::t('admin.common.power_on', $loc)) . '</button></form>'
                    . '<form method="post" action="' . AdminPath::url('/hetzner/servers/' . self::e((string) $sid) . '/power') . '" style="display:inline"><input type="hidden" name="action" value="off">'
                    . '<input type="hidden" name="account_id" value="' . self::e($s['_account_id'] ?? '') . '"><button class="btn-sm gray">'
                    . self::e(self::t('admin.common.power_off', $loc)) . '</button></form>'
                    . '<form method="post" action="' . AdminPath::url('/hetzner/servers/' . self::e((string) $sid) . '/power') . '" style="display:inline"><input type="hidden" name="action" value="reboot">'
                    . '<input type="hidden" name="account_id" value="' . self::e($s['_account_id'] ?? '') . '"><button class="btn-sm">'
                    . self::e(self::t('admin.common.reboot', $loc)) . '</button></form>'
                    . '<form method="post" action="' . AdminPath::url('/hetzner/servers/' . self::e((string) $sid) . '/password') . '" style="display:inline" onsubmit="return confirm('
                    . "'" . self::e(self::t('admin.hetzner.pw_confirm', $loc, ['name' => $s['name'] ?? ''])) . "'" . ')">'
                    . '<input type="hidden" name="account_id" value="' . self::e($s['_account_id'] ?? '') . '">'
                    . '<input type="hidden" name="name" value="' . self::e($s['name'] ?? '') . '"><button class="btn-sm">'
                    . self::e(self::t('admin.hetzner.root_pw', $loc)) . '</button></form></td></tr>';
            }
        } catch (\Throwable $e) {
            $srvRows = '<tr><td colspan="6" class="err">' . self::e($hz->explain($e)) . '</td></tr>';
        }
        if ($srvRows === '') {
            $srvRows = '<tr><td colspan="6" class="sub">' . self::e(self::t('admin.hetzner.no_servers', $loc)) . '</td></tr>';
        }
        $body = '<div class="card"><h2>' . self::e(self::t('admin.hetzner.status_head', $loc)) . '</h2>' . $status . '</div>'
            . '<div class="card"><h2>' . self::e(self::t('admin.hetzner.connections', $loc, ['n' => count($accounts)])) . '</h2><p class="sub">'
            . self::t('admin.hetzner.connections_sub', $loc) . '</p><table><tr><th>'
            . self::e(self::t('admin.hetzner.conn_name', $loc)) . '</th><th>' . self::e(self::t('admin.common.status', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>' . $connRows . '</table>'
            . '<h2 style="margin-top:18px">' . self::e(self::t('admin.hetzner.add_connection', $loc)) . '</h2>'
            . '<form method="post" action="' . AdminPath::url('/hetzner/add') . '"><label>' . self::e(self::t('admin.hetzner.conn_name', $loc)) . '</label>'
            . '<input name="name" placeholder="' . self::e(self::t('admin.hetzner.conn_name_ph', $loc)) . '" required>'
            . '<label>' . self::e(self::t('admin.hetzner.conn_token', $loc)) . '</label><input name="token" placeholder="'
            . self::e(self::t('admin.hetzner.conn_token_ph', $loc)) . '" required>'
            . '<p class="sub">' . self::t('admin.hetzner.conn_hint', $loc) . '</p>'
            . '<button class="btn">' . self::e(self::t('admin.hetzner.conn_add_btn', $loc)) . '</button></form></div>'
            . '<div class="card"><h2>' . self::e(self::t('admin.hetzner.all_servers', $loc, ['n' => substr_count($srvRows, '<tr>')])) . '</h2><p class="sub">'
            . self::e(self::t('admin.hetzner.servers_hint', $loc)) . '</p><table><tr><th>'
            . self::e(self::t('admin.common.name', $loc)) . '</th><th>' . self::e(self::t('admin.common.ip', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.specs', $loc)) . '</th><th>' . self::e(self::t('admin.common.status', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.project', $loc)) . '</th><th>' . self::e(self::t('admin.common.actions', $loc)) . '</th></tr>' . $srvRows . '</table></div>';
        self::render($req, $loc, self::t('admin.hetzner.title', $loc), $body, 'hetzner');
    }

    private static function dispatchHetznerPost(Request $req, string $loc, array $parts): void
    {
        $hz = ProviderManager::get('hetzner') ?? ProviderManager::active();
        if (($parts[1] ?? '') === 'add') {
            $name = trim((string) ($req->body['name'] ?? ''));
            $token = trim((string) ($req->body['token'] ?? ''));
            if (strlen($token) < 20) {
                self::redirectErr('/admin/hetzner', $loc, 'admin.err.token_short');
            }
            foreach ($hz->getAccounts() as $a) {
                if (($a['token'] ?? '') === $token) {
                    self::redirectErr('/admin/hetzner', $loc, 'admin.err.token_duplicate');
                }
            }
            try {
                $check = $hz->validateToken($token);
            } catch (\Throwable $e) {
                self::go('/admin/hetzner?err=' . rawurlencode($hz->explain($e)));
                return;
            }
            $st = Database::getSettings();
            $accounts = $st['hetzner_accounts'] ?? [];
            if (!is_array($accounts)) {
                $accounts = [];
            }
            $accounts[] = ['id' => bin2hex(random_bytes(4)), 'name' => $name, 'token' => $token, 'enabled' => true];
            Database::saveSettings(['hetzner_accounts' => $accounts, 'server_provider' => 'hetzner']);
            ActivityLog::log('hetzner', 'admin.act.hetzner_added', ['name' => $name, 'servers' => $check['servers'] ?? 0]);
            self::redirectOk('/admin/hetzner', 'admin.ok.connection_added', ['name' => $name]);
        }
        if (($parts[1] ?? '') === 'servers') {
            $sid = (int) ($parts[2] ?? 0);
            $action = $parts[3] ?? '';
            $acc = trim((string) ($req->body['account_id'] ?? ''));
            $labels = ['on' => 'admin.act.action.on', 'off' => 'admin.act.action.off', 'reboot' => 'admin.act.action.reboot'];
            if ($action === 'power') {
                $act = (string) ($req->body['action'] ?? '');
                if (!isset($labels[$act])) {
                    self::redirectErr('/admin/hetzner', $loc, 'admin.err.unknown_action');
                }
                try {
                    match ($act) {
                        'on' => $hz->powerOn($sid, $acc),
                        'off' => $hz->powerOff($sid, $acc),
                        'reboot' => $hz->reboot($sid, $acc),
                        default => null,
                    };
                    ActivityLog::log('hetzner', 'admin.act.hetzner_power', [
                        'action' => self::t($labels[$act], $loc), 'id' => $sid,
                    ]);
                    self::redirectOk('/admin/hetzner', 'admin.ok.command_sent', ['action' => self::t($labels[$act], $loc)]);
                } catch (\Throwable $e) {
                    self::go('/admin/hetzner?err=' . rawurlencode($hz->explain($e)));
                }
            }
            if ($action === 'password') {
                try {
                    $pw = $hz->resetPassword($sid, $acc);
                    $name = trim((string) ($req->body['name'] ?? ''));
                    ActivityLog::log('hetzner', 'admin.act.hetzner_password', ['id' => $sid]);
                    \App\Services\AdminFlash::put($req->cookie('q8admin'), 'hetzner_pw', ['pw' => $pw, 'name' => $name]);
                    Response::redirect(AdminPath::to('/hetzner'));
                } catch (\Throwable $e) {
                    self::go('/admin/hetzner?err=' . rawurlencode($hz->explain($e)));
                }
            }
        }
        $connId = $parts[1] ?? '';
        $connAction = $parts[2] ?? '';
        if ($connAction === 'toggle' || $connAction === 'delete') {
            $st = Database::getSettings();
            $accounts = $st['hetzner_accounts'] ?? [];
            if (!is_array($accounts)) {
                self::redirectErr('/admin/hetzner', $loc, 'admin.err.connection_not_found');
            }
            $found = null;
            foreach ($accounts as $i => $a) {
                if (($a['id'] ?? '') === $connId) {
                    $found = ['idx' => $i, 'acc' => $a];
                    break;
                }
            }
            if (!$found) {
                self::redirectErr('/admin/hetzner', $loc, 'admin.err.connection_not_found');
            }
            $name = $found['acc']['name'] ?? '';
            if ($connAction === 'toggle') {
                $accounts[$found['idx']]['enabled'] = !($found['acc']['enabled'] ?? true);
                Database::saveSettings(['hetzner_accounts' => $accounts]);
                $on = ($accounts[$found['idx']]['enabled'] ?? true) !== false;
                ActivityLog::log('hetzner', 'admin.act.hetzner_toggled', [
                    'state' => self::t($on ? 'admin.act.state.enabled' : 'admin.act.state.disabled', $loc),
                    'name' => $name,
                ]);
                self::redirectOk('/admin/hetzner', $on ? 'admin.ok.connection_on' : 'admin.ok.connection_off', ['name' => $name]);
            }
            unset($accounts[$found['idx']]);
            Database::saveSettings(['hetzner_accounts' => array_values($accounts)]);
            ActivityLog::log('hetzner', 'admin.act.hetzner_deleted', ['name' => $name]);
            self::redirectOk('/admin/hetzner', 'admin.ok.connection_deleted');
        }
        NotFound::admin($loc);
    }

    // ── Activity ────────────────────────────────────────────────────────────

    private static function activityGet(Request $req, string $loc): void
    {
        $limit = 100;
        $rows = '';
        foreach (Database::listActivity($limit) as $a) {
            $rows .= '<tr><td class="mono">' . self::e(substr((string) ($a['t'] ?? ''), 0, 19)) . '</td><td>'
                . self::e(ActivityLog::typeLabel((string) ($a['type'] ?? ''), $loc)) . '</td><td>'
                . self::e(ActivityLog::formatText($a, $loc)) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="sub">' . self::e(self::t('admin.activity.none', $loc)) . '</td></tr>';
        }
        $body = '<div class="card"><h2>' . self::e(self::t('admin.activity.head', $loc, ['n' => $limit])) . '</h2><table><tr><th>'
            . self::e(self::t('admin.common.time', $loc)) . '</th><th>' . self::e(self::t('admin.common.type', $loc)) . '</th><th>'
            . self::e(self::t('admin.common.event', $loc)) . '</th></tr>' . $rows . '</table></div>';
        self::render($req, $loc, self::t('admin.activity.title', $loc), $body, 'activity');
    }

    // ── Branding (visual identity) ────────────────────────────────────────────

    private static function brandingGet(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $body = '<p class="sub">' . self::e(self::t('branding.head', $loc)) . '</p>'
            . '<div class="card"><h2>' . self::e(self::t('settings.store', $loc)) . '</h2><form method="post" action="' . AdminPath::url('/branding/store') . '">'
            . '<label>' . self::e(self::t('settings.store_name', $loc)) . '</label><input name="store_name" value="' . self::e($st['store_name'] ?? '') . '">'
            . '<label>' . self::e(self::t('settings.currency', $loc)) . '</label><input name="currency" value="' . self::e($st['currency'] ?? 'KWD') . '">'
            . self::descSettingsFields($st, $loc)
            . '<label>' . self::e(self::t('settings.telegram', $loc)) . '</label><input name="telegram_link" value="' . self::e($st['telegram_link'] ?? '') . '">'
            . '<h2 style="margin-top:22px">' . self::e(self::t('settings.brand_head', $loc)) . '</h2>'
            . '<p class="sub">' . self::e(self::t('settings.brand_hint', $loc)) . '</p>'
            . self::brandSettingsFields($st, $loc)
            . '<h2 style="margin-top:22px">' . self::e(self::t('settings.faq_head', $loc)) . '</h2>'
            . '<p class="sub">' . self::e(self::t('settings.faq_hint', $loc)) . '</p>'
            . '<label><input type="checkbox" name="show_faq_home" ' . (!empty($st['show_faq_home']) ? 'checked' : '') . '> '
            . self::e(self::t('settings.show_faq_home', $loc)) . '</label><p class="sub">'
            . self::e(self::t('settings.show_faq_home_hint', $loc)) . '</p>'
            . self::faqSettingsFields($st, $loc)
            . '<label>' . self::e(self::t('settings.invoice_footer', $loc)) . '</label><textarea name="invoice_footer">' . self::e($st['invoice_footer'] ?? '') . '</textarea>'
            . '<button class="btn">' . self::e(self::t('settings.save_store', $loc)) . '</button></form></div>'
            . '<div class="card"><h2>' . self::e(self::t('settings.lang', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('settings.lang_sub', $loc)) . '</p><form method="post" action="' . AdminPath::url('/branding/lang') . '">'
            . '<label>' . self::e(self::t('settings.store_locale', $loc)) . '</label><select name="store_locale">'
            . self::localeOpt('en', $st['store_locale'] ?? 'en', $loc) . self::localeOpt('ar', $st['store_locale'] ?? 'en', $loc) . '</select>'
            . '<label>' . self::e(self::t('settings.admin_locale', $loc)) . '</label><select name="admin_locale">'
            . self::localeOpt('en', $st['admin_locale'] ?? 'en', $loc) . self::localeOpt('ar', $st['admin_locale'] ?? 'en', $loc) . '</select>'
            . '<label><input type="checkbox" name="allow_locale_switch" ' . (($st['allow_locale_switch'] ?? true) !== false ? 'checked' : '') . '> '
            . self::e(self::t('settings.allow_switch', $loc)) . '</label>'
            . '<label>' . self::e(self::t('settings.bot_default_locale', $loc)) . '</label><select name="bot_default_locale">'
            . self::localeOpt('en', $st['bot_default_locale'] ?? 'en', $loc) . self::localeOpt('ar', $st['bot_default_locale'] ?? 'en', $loc) . '</select>'
            . '<label><input type="checkbox" name="bot_allow_locale_switch" ' . (($st['bot_allow_locale_switch'] ?? true) !== false ? 'checked' : '') . '> '
            . self::e(self::t('settings.bot_allow_switch', $loc)) . '</label>'
            . '<button class="btn">' . self::e(self::t('settings.save_lang', $loc)) . '</button></form></div>';
        self::render($req, $loc, self::t('branding.title', $loc), $body, 'branding');
    }

    private static function dispatchBrandingPost(Request $req, string $loc, array $parts): void
    {
        $action = $parts[1] ?? '';
        match ($action) {
            'store' => self::brandingStorePost($req, $loc),
            'lang' => self::brandingLangPost($req, $loc),
            default => NotFound::admin($loc),
        };
    }

    private static function brandingStorePost(Request $req, string $loc): void
    {
        try {
            $descAr = trim((string) ($req->body['store_desc_ar'] ?? ''));
            $descEn = trim((string) ($req->body['store_desc_en'] ?? ''));
            $faqAr = trim((string) ($req->body['faq_ar'] ?? ''));
            $faqEn = trim((string) ($req->body['faq_en'] ?? ''));
            $patch = [
                'store_name' => trim((string) ($req->body['store_name'] ?? '')),
                'currency' => trim((string) ($req->body['currency'] ?? 'KWD')),
                'store_desc_ar' => $descAr,
                'store_desc_en' => $descEn,
                'store_desc' => $descAr !== '' ? $descAr : $descEn,
                'telegram_link' => trim((string) ($req->body['telegram_link'] ?? '')),
                'faq_ar' => $faqAr,
                'faq_en' => $faqEn,
                'faq' => $faqAr !== '' ? $faqAr : $faqEn,
                'show_faq_home' => isset($req->body['show_faq_home']),
                'invoice_footer' => trim((string) ($req->body['invoice_footer'] ?? '')),
                'brand_primary' => BrandTheme::normalizeHex((string) ($req->body['brand_primary'] ?? ''), BrandTheme::DEFAULT_PRIMARY),
                'brand_secondary' => BrandTheme::normalizeHex((string) ($req->body['brand_secondary'] ?? ''), BrandTheme::DEFAULT_SECONDARY),
                'brand_bg' => BrandTheme::normalizeHex((string) ($req->body['brand_bg'] ?? ''), BrandTheme::DEFAULT_BG),
            ];
            if (!empty($req->body['remove_logo'])) {
                BrandTheme::removeLogo();
                $patch['store_logo'] = '';
            } elseif (array_key_exists('store_logo_url', $req->body)) {
                $logoUrl = trim((string) ($req->body['store_logo_url'] ?? ''));
                if ($logoUrl !== '') {
                    $patch['store_logo'] = BrandTheme::normalizeLogoUrl($logoUrl);
                    BrandTheme::removeLogo();
                } else {
                    $patch['store_logo'] = '';
                    BrandTheme::removeLogo();
                }
            }
            Database::saveSettings($patch);
            ActivityLog::log('settings', 'admin.act.settings_updated');
            self::redirectOk('/admin/branding', 'settings.saved_store');
        } catch (\Throwable $e) {
            self::redirectErr('/admin/branding', $loc, 'settings.brand_save_failed', ['msg' => $e->getMessage()]);
        }
    }

    private static function brandingLangPost(Request $req, string $loc): void
    {
        Database::saveSettings([
            'store_locale' => ($req->body['store_locale'] ?? '') === 'ar' ? 'ar' : 'en',
            'admin_locale' => ($req->body['admin_locale'] ?? '') === 'ar' ? 'ar' : 'en',
            'allow_locale_switch' => isset($req->body['allow_locale_switch']),
            'bot_default_locale' => ($req->body['bot_default_locale'] ?? '') === 'ar' ? 'ar' : 'en',
            'bot_allow_locale_switch' => isset($req->body['bot_allow_locale_switch']),
        ]);
        ActivityLog::log('settings', 'admin.act.lang_updated');
        self::redirectOk('/admin/branding', 'settings.saved_lang');
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    private static function dispatchSettingsGet(Request $req, string $loc, array $parts): void
    {
        $sub = $parts[1] ?? '';
        if ($sub === 'export.sql') {
            $sql = Database::exportSql();
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="q8-backup-' . date('Y-m-d') . '.sql"');
            echo $sql;
            exit;
        }
        if ($sub === 'export.csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="clients-' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'name', 'phone', 'telegram_id', 'server_id', 'expires', 'active']);
            foreach (Database::listClients() as $c) {
                fputcsv($out, [
                    $c['id'], $c['name'], $c['phone'], $c['telegram_id'] ?? '',
                    $c['server_id'] ?? '', $c['expires'] ?? '', ($c['active'] ?? true) ? '1' : '0',
                ]);
            }
            fclose($out);
            exit;
        }
        self::settingsGet($req, $loc);
    }

    private static function settingsGet(Request $req, string $loc): void
    {
        $st = Database::getSettings();
        $dbInfo = Database::getDbInfo();
        $stats = $dbInfo['stats'] ?? [];
        $statsLine = '';
        if ($stats !== []) {
            $parts = [
                self::t('settings.db_stat_clients', $loc, ['n' => $stats['q8_clients'] ?? 0]),
                self::t('settings.db_stat_products', $loc, ['n' => $stats['q8_products'] ?? 0]),
                self::t('settings.db_stat_invoices', $loc, ['n' => $stats['q8_invoices'] ?? 0]),
            ];
            $statsLine = '<p class="sub">' . self::e(implode(' · ', $parts)) . '</p>';
        }
        $body = '<p class="sub">' . self::e(self::t('settings.head', $loc)) . '</p>'
            . '<div class="card"><h2>' . self::e(self::t('settings.admin_account', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('settings.admin_sub', $loc)) . '</p><form method="post" action="' . AdminPath::url('/settings/admin') . '">'
            . '<label>' . self::e(self::t('settings.admin_user', $loc)) . '</label><input value="' . self::e(AdminAuth::adminUser()) . '" disabled>'
            . '<label>' . self::e(self::t('settings.admin_user_new', $loc)) . '</label><input name="admin_user">'
            . '<label>' . self::e(self::t('settings.admin_pass_new', $loc)) . '</label><input name="admin_pass" type="password">'
            . '<label>' . self::e(self::t('settings.admin_pass_confirm', $loc)) . '</label><input name="admin_pass_confirm" type="password">'
            . '<label>' . self::e(self::t('settings.admin_pass_current', $loc)) . '</label><input name="current_pass" type="password" required>'
            . '<p class="sub">' . self::e(self::t('settings.admin_pass_hint', $loc)) . '</p>'
            . '<button class="btn">' . self::e(self::t('settings.save_admin', $loc)) . '</button></form></div>'
            . '<div class="card"><h2>' . self::e(self::t('settings.admin_path_head', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('settings.admin_path_sub', $loc)) . '</p>'
            . '<div class="kv"><b>' . self::e(self::t('settings.admin_path_current', $loc)) . '</b>'
            . '<span class="mono">' . self::e(AdminPath::prefix()) . '</span></div>'
            . '<form method="post" action="' . AdminPath::url('/settings/path') . '">'
            . '<label>' . self::e(self::t('settings.admin_path_new', $loc)) . '</label>'
            . '<input name="admin_path" dir="ltr" placeholder="panel" pattern="[a-z][a-z0-9-]{1,31}" autocomplete="off">'
            . '<p class="sub">' . self::e(self::t('settings.admin_path_hint', $loc)) . '</p>'
            . '<label>' . self::e(self::t('settings.admin_pass_current', $loc)) . '</label>'
            . '<input name="current_pass" type="password" required>'
            . '<button class="btn">' . self::e(self::t('settings.admin_path_save', $loc)) . '</button></form></div>'
            . '<div class="card"><h2>' . self::e(self::t('settings.backup', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('settings.backup_sub', $loc)) . '</p><p class="sub">'
            . self::e(self::t('settings.export_hint', $loc)) . '</p><div class="row" style="flex-wrap:wrap;gap:10px">'
            . '<a class="btn" href="' . AdminPath::url('/settings/export.sql') . '">' . self::e(self::t('settings.backup_sql', $loc)) . '</a>'
            . '<a class="btn gray" href="' . AdminPath::url('/settings/export.csv') . '">' . self::e(self::t('settings.backup_csv', $loc)) . '</a></div>'
            . '<h2 style="margin-top:18px">' . self::e(self::t('settings.import', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('settings.import_hint', $loc)) . '</p>'
            . '<form method="post" action="' . AdminPath::url('/settings/import') . '" enctype="multipart/form-data" onsubmit="return confirm('
            . "'" . self::e(self::t('settings.import_confirm', $loc)) . "'" . ')">'
            . '<label>' . self::e(self::t('settings.import_file', $loc)) . '</label><input type="file" name="backup" accept=".sql,text/plain" required>'
            . '<button class="btn">' . self::e(self::t('settings.import_btn', $loc)) . '</button></form></div>'
            . '<div class="card"><h2>' . self::e(self::t('settings.database', $loc)) . '</h2><p class="sub">'
            . self::e(self::t('settings.database_sub', $loc)) . '</p><p><b>' . self::e(self::t('settings.db_current', $loc)) . ':</b> '
            . self::e($dbInfo['label']) . '</p>' . $statsLine . '</div>'
            . UpdateCheck::settingsBlockHtml($loc, UpdateCheck::check(false), (string) ($req->cookie('q8admin') ?? ''));
        self::render($req, $loc, self::t('settings.title', $loc), $body, 'settings');
    }

    private static function localeOpt(string $code, string $current, string $loc): string
    {
        $sel = $current === $code ? ' selected' : '';
        $label = $code === 'ar' ? I18n::t('lang.ar', $loc) : I18n::t('lang.en', $loc);
        return '<option value="' . self::e($code) . '"' . $sel . '>' . self::e($label) . '</option>';
    }

    private static function dispatchSettingsPost(Request $req, string $loc, array $parts): void
    {
        $action = $parts[1] ?? '';
        match ($action) {
            'admin' => self::settingsAdminPost($req, $loc),
            'path' => self::settingsPathPost($req, $loc),
            'import' => self::settingsImportPost($req, $loc),
            'check-update' => self::settingsUpdateCheckPost($req, $loc),
            default => NotFound::admin($loc),
        };
    }

    /** @param array<string, mixed> $st */
    private static function descSettingsFields(array $st, string $loc): string
    {
        $desc = \App\Views\StoreUi::descFieldsForAdmin($st);
        return '<label>' . self::e(self::t('settings.store_desc_ar', $loc)) . '</label>'
            . '<textarea name="store_desc_ar" rows="4" dir="rtl">' . self::e($desc['ar']) . '</textarea>'
            . '<label>' . self::e(self::t('settings.store_desc_en', $loc)) . '</label>'
            . '<textarea name="store_desc_en" rows="4" dir="ltr">' . self::e($desc['en']) . '</textarea>';
    }

    /** @param array<string, mixed> $st */
    private static function faqSettingsFields(array $st, string $loc): string
    {
        $faq = \App\Views\StoreUi::faqFieldsForAdmin($st);
        return '<label>' . self::e(self::t('settings.faq_ar', $loc)) . '</label>'
            . '<textarea name="faq_ar" rows="10" dir="rtl" placeholder="' . self::e(self::t('settings.faq_ph_ar', $loc)) . '">' . self::e($faq['ar']) . '</textarea>'
            . '<p class="sub">' . self::t('settings.faq_count', $loc, [
                'n' => count(\App\Views\StoreUi::parseFaq($faq['ar'])),
            ]) . '</p>'
            . '<label>' . self::e(self::t('settings.faq_en', $loc)) . '</label>'
            . '<textarea name="faq_en" rows="10" dir="ltr" placeholder="' . self::e(self::t('settings.faq_ph_en', $loc)) . '">' . self::e($faq['en']) . '</textarea>'
            . '<p class="sub">' . self::t('settings.faq_count', $loc, [
                'n' => count(\App\Views\StoreUi::parseFaq($faq['en'])),
            ]) . '</p>';
    }

    /** @param array<string, mixed> $st */
    private static function brandSettingsFields(array $st, string $loc): string
    {
        $logoUrl = BrandTheme::logoUrl($st);
        $logoInput = BrandTheme::logoInputValue($st);
        $preview = $logoUrl !== ''
            ? '<p class="sub"><img src="' . self::e($logoUrl) . '" alt="" style="max-height:48px;max-width:180px;border-radius:8px;background:rgba(255,255,255,.06);padding:6px"></p>'
            : '<p class="sub">' . self::e(self::t('settings.brand_logo_none', $loc)) . '</p>';
        $p = BrandTheme::primary($st);
        $s = BrandTheme::secondary($st);
        $bg = BrandTheme::bg($st);
        $remove = $logoUrl !== ''
            ? '<label style="margin-top:10px"><input type="checkbox" name="remove_logo" value="1"> '
                . self::e(self::t('settings.brand_logo_remove', $loc)) . '</label>'
            : '';
        return $preview
            . '<label>' . self::e(self::t('settings.brand_logo', $loc)) . '</label>'
            . '<input type="url" name="store_logo_url" dir="ltr" placeholder="https://example.com/logo.png" value="' . self::e($logoInput) . '">'
            . '<p class="sub">' . self::e(self::t('settings.brand_logo_hint', $loc)) . '</p>'
            . $remove
            . '<div class="row" style="flex-wrap:wrap;gap:14px;margin-top:12px;align-items:flex-end">'
            . '<div><label>' . self::e(self::t('settings.brand_primary', $loc)) . '</label>'
            . '<input type="color" name="brand_primary" id="brand_primary" value="' . self::e($p) . '"></div>'
            . '<div><label>' . self::e(self::t('settings.brand_secondary', $loc)) . '</label>'
            . '<input type="color" name="brand_secondary" id="brand_secondary" value="' . self::e($s) . '"></div>'
            . '<div><label>' . self::e(self::t('settings.brand_bg', $loc)) . '</label>'
            . '<input type="color" name="brand_bg" id="brand_bg" value="' . self::e($bg) . '"></div>'
            . '<div><button type="button" class="btn gray" id="brand-colors-reset">'
            . self::e(self::t('settings.brand_colors_reset', $loc)) . '</button></div>'
            . '</div>'
            . '<p class="sub">' . self::e(self::t('settings.brand_colors_hint', $loc)) . '</p>'
            . '<script>document.getElementById("brand-colors-reset")?.addEventListener("click",function(){'
            . 'var p=document.getElementById("brand_primary"),s=document.getElementById("brand_secondary"),b=document.getElementById("brand_bg");'
            . 'if(p)p.value="' . BrandTheme::DEFAULT_PRIMARY . '";if(s)s.value="' . BrandTheme::DEFAULT_SECONDARY . '";if(b)b.value="' . BrandTheme::DEFAULT_BG . '";'
            . '});</script>';
    }

    private static function productsOpsPost(Request $req, string $loc): void
    {
        Database::saveSettings([
            'auto_stop_expired' => isset($req->body['auto_stop_expired']),
            'low_stock_threshold' => max(0, min(50, (int) ($req->body['low_stock_threshold'] ?? 1))),
        ]);
        ActivityLog::log('settings', 'admin.act.settings_updated');
        self::redirectOk('/admin/products', 'settings.saved_ops');
    }

    private static function settingsAdminPost(Request $req, string $loc): void
    {
        $current = (string) ($req->body['current_pass'] ?? '');
        if (!AdminAuth::verifyLogin(AdminAuth::adminUser(), $current)) {
            self::go('/admin/settings?err=' . rawurlencode(self::t('admin.login.err', $loc)));
        }
        $newPass = (string) ($req->body['admin_pass'] ?? '');
        $confirm = (string) ($req->body['admin_pass_confirm'] ?? '');
        if ($newPass !== '' && $newPass !== $confirm) {
            self::redirectErr('/admin/settings', $loc, 'admin.err.pass_mismatch');
        }
        $patch = [];
        $newUser = trim((string) ($req->body['admin_user'] ?? ''));
        if ($newUser !== '') {
            $patch['ADMIN_USER'] = $newUser;
        }
        if ($newPass !== '') {
            $patch['ADMIN_PASS'] = AdminAuth::hashPassword($newPass);
        }
        if ($patch !== []) {
            EnvFile::update($patch);
            ActivityLog::log('security', 'admin.act.admin_updated');
        }
        self::redirectOk(AdminPath::to('/settings'), 'settings.saved_admin');
    }

    private static function settingsPathPost(Request $req, string $loc): void
    {
        $current = (string) ($req->body['current_pass'] ?? '');
        if (!AdminAuth::verifyLogin(AdminAuth::adminUser(), $current)) {
            self::go('/admin/settings?err=' . rawurlencode(self::t('admin.login.err', $loc)));
        }
        $newPath = strtolower(trim((string) ($req->body['admin_path'] ?? '')));
        if ($newPath === '') {
            self::redirectErr('/admin/settings', $loc, 'settings.admin_path_required');
        }
        $errKey = AdminPath::validateSegment($newPath);
        if ($errKey !== null) {
            self::redirectErr('/admin/settings', $loc, $errKey);
        }
        if ($newPath === AdminPath::segment()) {
            self::redirectOk('/admin/settings', 'settings.admin_path_unchanged');
        }
        $oldPrefix = AdminPath::prefix();
        AdminPath::updateSegment($newPath);
        AdminAuth::destroySession($req->cookie('q8admin'));
        foreach (['q8admin', 'q8lcsrf', 'q8a2fa'] as $cookie) {
            Response::cookie($cookie, '', Url::cookiePath($oldPrefix), -3600);
            Response::cookie($cookie, '', AdminPath::cookiePath(), -3600);
        }
        ActivityLog::log('security', 'admin.act.path_changed', ['path' => AdminPath::prefix()]);
        Response::redirect(AdminPath::url('/login') . '?ok=' . rawurlencode(self::t('settings.admin_path_changed', $loc, ['path' => AdminPath::prefix()])));
    }

    private static function settingsUpdateCheckPost(Request $req, string $loc): void
    {
        $state = UpdateCheck::check(true);
        if ($state['error'] !== '') {
            self::redirectErr('/admin/settings', $loc, 'settings.updates_check_failed', ['msg' => $state['error']]);
        }
        if ($state['update_available']) {
            self::redirectOk('/admin/settings', 'settings.updates_check_found', [
                'latest' => $state['latest'],
            ]);
        }
        self::redirectOk('/admin/settings', 'settings.updates_check_ok');
    }

    private static function settingsImportPost(Request $req, string $loc): void
    {
        if (!isset($_FILES['backup']) || ($_FILES['backup']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::redirectErr('/admin/settings', $loc, 'admin.err.no_backup_file');
        }
        $maxSize = 50 * 1024 * 1024;
        if (($_FILES['backup']['size'] ?? 0) > $maxSize) {
            self::redirectErr('/admin/settings', $loc, 'admin.err.file_too_large');
        }
        $raw = file_get_contents((string) $_FILES['backup']['tmp_name']);
        if ($raw === false) {
            self::redirectErr('/admin/settings', $loc, 'admin.err.upload_failed');
        }
        $name = strtolower((string) ($_FILES['backup']['name'] ?? 'backup'));
        if (!str_ends_with($name, '.sql') && !str_contains(ltrim($raw), '-- Q8 VPS Platform MySQL Backup')) {
            self::redirectErr('/admin/settings', $loc, 'admin.err.import_failed', ['msg' => 'Upload a .sql backup exported from this panel']);
        }
        try {
            Database::importSql($raw);
            ActivityLog::log('backup', 'admin.act.backup_imported', ['file' => $_FILES['backup']['name'] ?? 'backup']);
            self::redirectOk('/admin/settings', 'admin.ok.import_done');
        } catch (\Throwable $e) {
            self::redirectErr('/admin/settings', $loc, 'admin.err.import_failed', ['msg' => $e->getMessage()]);
        }
    }
}
