<?php
declare(strict_types=1);

namespace App\Bot;

use App\Database\Database;
use App\Helpers\I18n;
use App\Helpers\Locale;

final class BotTexts
{
    /** @var array<string, string> Map editable key → legacy i18n default key */
    private const LEGACY_DEF_KEYS = [
        'help_title' => 'bot.help.title',
        'help_inline' => 'bot.help.inline',
        'share_contact' => 'bot.share_contact',
        'lang_disabled' => 'bot.lang.disabled',
        'lang_set' => 'bot.lang.set',
        'lang_title' => 'bot.lang.title',
        'lang_current' => 'bot.lang.current',
        'action_unknown' => 'bot.action_unknown',
        'no_os_images' => 'bot.no_os_images',
        'server_type' => 'bot.server.type',
        'server_country' => 'bot.server.country',
        'server_phone' => 'bot.server.phone',
        'status_running' => 'bot.status.running',
        'status_off' => 'bot.status.off',
        'status_starting' => 'bot.status.starting',
        'status_stopping' => 'bot.status.stopping',
        'status_rebuilding' => 'bot.status.rebuilding',
        'status_migrating' => 'bot.status.migrating',
        'status_initializing' => 'bot.status.initializing',
        'status_unknown' => 'bot.status.unknown',
        'bot_desc' => 'bot.desc',
        'bot_short_desc' => 'bot.short_desc',
        'admin_stats_title' => 'bot.admin.stats_title',
        'admin_stats_total' => 'bot.admin.stats_total',
        'admin_stats_active' => 'bot.admin.stats_active',
        'admin_stats_expired' => 'bot.admin.stats_expired',
        'admin_stats_expiring' => 'bot.admin.stats_expiring',
        'admin_stats_linked' => 'bot.admin.stats_linked',
        'admin_stats_servers' => 'bot.admin.stats_servers',
        'admin_stats_unpaid' => 'bot.admin.stats_unpaid',
        'admin_stats_low_stock' => 'bot.admin.stats_low_stock',
        'admin_stats_month' => 'bot.admin.stats_month',
        'admin_linked_title' => 'bot.admin.linked_title',
        'admin_linked_yes' => 'bot.admin.linked_yes',
        'admin_linked_no' => 'bot.admin.linked_no',
        'admin_linked_total' => 'bot.admin.linked_total',
        'admin_servers_list' => 'bot.admin.servers',
    ];

    /** @var array<string, string> */
    private const STATUS_KEY_MAP = [
        'running' => 'status_running',
        'off' => 'status_off',
        'starting' => 'status_starting',
        'stopping' => 'status_stopping',
        'rebuilding' => 'status_rebuilding',
        'migrating' => 'status_migrating',
        'initializing' => 'status_initializing',
    ];

    /** @var list<string> */
    public const CLIENT_BTN_KEYS = ['on', 'off', 'reboot', 'pass', 'serverinfo', 'info', 'rebuild', 'sub', 'renew', 'language'];

    /** @var list<string> */
    public const ADMIN_BTN_KEYS = ['stats', 'linked', 'servers', 'whoami', 'help'];

    /**
     * @return array<string, list<string>>
     */
    public static function textSections(): array
    {
        return [
            'registration' => ['unregistered', 'linked_ok', 'phone_not_own', 'phone_not_found', 'share_contact'],
            'account' => ['suspended', 'expired', 'no_server'],
            'subscription' => [
                'payment_ok', 'sub_line_none', 'sub_line_expired', 'sub_line_today', 'sub_line_active',
                'sub_view', 'renew_store', 'store_url_missing',
            ],
            'actions' => ['rebuild_warning', 'msg_from_admin', 'action_unknown', 'no_os_images'],
            'help_lang' => ['help_title', 'help_inline', 'lang_title', 'lang_current', 'lang_set', 'lang_disabled'],
            'server_info' => ['server_type', 'server_country', 'server_phone', 'status_running', 'status_off', 'status_unknown'],
            'bot_profile' => ['bot_desc', 'bot_short_desc'],
            'admin_replies' => [
                'admin_stats_title', 'admin_stats_total', 'admin_stats_active', 'admin_stats_expired',
                'admin_stats_expiring', 'admin_stats_linked', 'admin_stats_servers', 'admin_stats_unpaid',
                'admin_stats_low_stock', 'admin_stats_month', 'admin_linked_title', 'admin_linked_yes',
                'admin_linked_no', 'admin_linked_total', 'admin_servers_list',
            ],
        ];
    }

    /** @return list<string> */
    public static function allTextKeys(): array
    {
        $keys = [];
        foreach (self::textSections() as $sectionKeys) {
            foreach ($sectionKeys as $key) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    public static function label(string $key, string $loc): string
    {
        $t = I18n::t('bot.text.lbl.' . $key, $loc);
        if ($t !== 'bot.text.lbl.' . $key) {
            return $t;
        }
        return $key;
    }

    public static function defaultText(string $key, string $loc): string
    {
        $legacy = self::LEGACY_DEF_KEYS[$key] ?? null;
        if ($legacy !== null) {
            $text = I18n::t($legacy, $loc);
            if ($text !== $legacy) {
                return $text;
            }
        }
        $text = I18n::t('bot.text.def.' . $key, $loc);
        if ($text !== 'bot.text.def.' . $key) {
            return $text;
        }
        if ($legacy !== null) {
            $text = I18n::t($legacy, 'en');
            if ($text !== $legacy) {
                return $text;
            }
        }
        return I18n::t('bot.text.def.' . $key, 'en');
    }

    public static function text(string $key, string $loc, array $vars = []): string
    {
        $st = Database::getSettings();
        $custom = trim((string) ($st['bot_texts'][$key][$loc] ?? $st['bot_texts'][$key]['en'] ?? ''));
        if ($custom !== '') {
            $text = $custom;
        } else {
            $text = self::defaultText($key, $loc);
        }
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    public static function status(string $status, string $loc): string
    {
        $status = strtolower(trim($status));
        $key = self::STATUS_KEY_MAP[$status] ?? null;
        if ($key !== null) {
            return self::text($key, $loc);
        }
        if ($status === '') {
            return self::text('status_unknown', $loc, ['status' => '—']);
        }
        $legacy = I18n::t('bot.status.' . $status, $loc);
        if ($legacy !== 'bot.status.' . $status) {
            return $legacy;
        }
        return self::text('status_unknown', $loc, ['status' => $status]);
    }

    public static function btn(string $key, string $loc): string
    {
        $st = Database::getSettings();
        $buttons = $st['bot_buttons'] ?? [];
        if (isset($buttons[$key]) && is_array($buttons[$key])) {
            if (($buttons[$key]['enabled'] ?? true) === false) {
                return '';
            }
            $label = trim((string) ($buttons[$key][$loc] ?? ''));
            if ($label === '' && $loc !== 'en') {
                $label = trim((string) ($buttons[$key]['en'] ?? ''));
            }
            if ($label !== '') {
                return $label;
            }
        }
        if ($key === 'language') {
            return I18n::t('bot.btn.language', $loc);
        }
        return I18n::t('bot.btn.lbl.' . $key, $loc);
    }

    public static function adminBtn(string $key, string $loc): string
    {
        $st = Database::getSettings();
        $buttons = $st['bot_admin_buttons'] ?? [];
        if (isset($buttons[$key]) && is_array($buttons[$key])) {
            $label = trim((string) ($buttons[$key][$loc] ?? $buttons[$key]['en'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }
        return I18n::t('bot.admin.btn.' . $key, $loc);
    }

    public static function defaultAdminBtn(string $key, string $loc): string
    {
        return I18n::t('bot.admin.btn.' . $key, $loc);
    }

    public static function defaultClientBtn(string $key, string $loc): string
    {
        if ($key === 'language') {
            return I18n::t('bot.btn.language', $loc);
        }
        return I18n::t('bot.btn.lbl.' . $key, $loc);
    }

    /** @return list<string> */
    public static function allowedOsImages(): array
    {
        $st = Database::getSettings();
        $raw = $st['bot_os_images'] ?? '';
        if ($raw === '' || $raw === []) {
            return [];
        }
        if (is_array($raw)) {
            return array_values(array_map(
                static fn($v) => (string) $v,
                array_filter($raw, static fn($v) => $v !== '' && $v !== null)
            ));
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $raw) ?: [])));
    }

    /** @return list<string> */
    public static function enabledButtonKeys(): array
    {
        $st = Database::getSettings();
        $buttons = $st['bot_buttons'] ?? [];
        $keys = ['on', 'off', 'reboot', 'pass', 'serverinfo', 'info', 'rebuild', 'sub', 'renew'];
        $out = [];
        foreach ($keys as $key) {
            if ($key === 'renew' && !self::renewAvailable()) {
                continue;
            }
            if (($buttons[$key]['enabled'] ?? true) !== false) {
                $out[] = $key;
            }
        }
        return $out;
    }

    public static function renewAvailable(): bool
    {
        $st = Database::getSettings();
        $url = trim((string) ($st['store_url'] ?? ''));
        if ($url === '') {
            $url = trim((string) \App\Services\Config::get('WEBHOOK_DOMAIN', ''));
        }
        return $url !== '';
    }

    public static function callbackForKey(string $key): string
    {
        return 'c:' . $key;
    }

    /** Map a reply-keyboard label (EN/AR/custom) to action key e.g. on, off, info. */
    public static function actionFromLabel(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        foreach (self::enabledButtonKeys() as $key) {
            foreach (['en', 'ar'] as $loc) {
                if ($label === self::btn($key, $loc)) {
                    return $key;
                }
            }
        }
        foreach (['en', 'ar'] as $loc) {
            if ($label === self::btn('language', $loc)) {
                return 'language';
            }
        }
        return null;
    }

    public static function langAllowed(): bool
    {
        return Locale::storeSettings()['bot_allow_locale_switch'];
    }

    /** Map admin reply-keyboard label to action: stats, linked, servers, whoami, help. */
    public static function adminActionFromLabel(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        foreach (self::ADMIN_BTN_KEYS as $action) {
            foreach (['en', 'ar'] as $loc) {
                if ($label === self::adminBtn($action, $loc)) {
                    return $action;
                }
            }
        }
        return null;
    }
}
