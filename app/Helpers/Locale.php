<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Database\Database;
use App\Request;
use App\Response;

final class Locale
{
    public const DEFAULT = 'en';
    private const COOKIE_STORE = 'ulang';
    private const COOKIE_ADMIN = 'alang';

    public static function norm(string $code): string
    {
        $c = strtolower(substr($code, 0, 2));
        return in_array($c, ['en', 'ar'], true) ? $c : self::DEFAULT;
    }

    /** @return array{store_locale: string, allow_locale_switch: bool, bot_default_locale: string, bot_allow_locale_switch: bool} */
    public static function storeSettings(): array
    {
        $st = Database::getSettings();
        return [
            'store_locale' => self::norm($st['store_locale'] ?? self::DEFAULT),
            'allow_locale_switch' => ($st['allow_locale_switch'] ?? true) !== false,
            'bot_default_locale' => self::norm($st['bot_default_locale'] ?? self::DEFAULT),
            'bot_allow_locale_switch' => ($st['bot_allow_locale_switch'] ?? true) !== false,
        ];
    }

    public static function resolveStoreLocale(Request $req): string
    {
        $s = self::storeSettings();
        if (!$s['allow_locale_switch']) {
            return $s['store_locale'];
        }
        $fromCookie = $req->cookie(self::COOKIE_STORE);
        return $fromCookie !== '' ? self::norm($fromCookie) : $s['store_locale'];
    }

    public static function resolveAdminLocale(Request $req): string
    {
        $fromCookie = $req->cookie(self::COOKIE_ADMIN);
        if ($fromCookie !== '') {
            return self::norm($fromCookie);
        }
        $st = Database::getSettings();
        return self::norm($st['admin_locale'] ?? $st['store_locale'] ?? self::DEFAULT);
    }

    public static function resolveBotLocale(string $telegramId, ?array $client): string
    {
        $s = self::storeSettings();
        $map = Database::getSettings()['bot_user_locales'] ?? [];
        if ($telegramId !== '' && !empty($map[$telegramId])) {
            return self::norm((string) $map[$telegramId]);
        }
        if (!empty($client['locale'])) {
            return self::norm((string) $client['locale']);
        }
        return $s['bot_default_locale'];
    }

    public static function setStoreLocaleCookie(string $locale): void
    {
        Response::cookie(self::COOKIE_STORE, self::norm($locale), null, 365 * 86400);
    }

    public static function setAdminLocaleCookie(string $locale): void
    {
        Response::cookie(self::COOKIE_ADMIN, self::norm($locale), null, 365 * 86400);
    }

    public static function saveBotUserLocale(string $telegramId, string $locale): void
    {
        $st = Database::getSettings();
        $map = $st['bot_user_locales'] ?? [];
        $map[$telegramId] = self::norm($locale);
        Database::saveSettings(['bot_user_locales' => $map]);
        $norm = self::norm($locale);
        foreach (Database::listClientsByTelegramId($telegramId) as $c) {
            Database::updateClient($c['id'], ['locale' => $norm]);
        }
    }
}
