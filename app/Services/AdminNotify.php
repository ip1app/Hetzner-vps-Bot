<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Helpers\I18n;

/** Sends Telegram alerts to configured admin chat IDs (ADMIN_IDS). */
final class AdminNotify
{
    public static function adminLogin(string $user, string $ip): void
    {
        self::send(I18n::t('notify.admin_login', self::locale(), [
            'user' => $user,
            'ip' => $ip !== '' ? $ip : '—',
        ]));
    }

    /** @param array<string, mixed> $client */
    public static function newClient(array $client, string $source = 'store', ?string $invoice = null): void
    {
        self::send(I18n::t('notify.new_client', self::locale(), [
            'name' => (string) ($client['name'] ?? '—'),
            'phone' => (string) ($client['phone'] ?? '—'),
            'expires' => (string) ($client['expires'] ?? '—'),
            'source' => self::sourceLabel($source),
            'invoice' => $invoice !== null && $invoice !== '' ? $invoice : '—',
        ]));
    }

    /** @param array<string, mixed> $client */
    public static function passwordRequest(array $client, string $via): void
    {
        self::send(I18n::t('notify.password', self::locale(), [
            'name' => (string) ($client['name'] ?? '—'),
            'phone' => (string) ($client['phone'] ?? '—'),
            'server' => (string) ((int) ($client['server_id'] ?? 0) ?: '—'),
            'via' => self::viaLabel($via),
        ]));
    }

    /** @param array<string, mixed> $product */
    public static function lowStock(array $product, int $available, int $threshold): void
    {
        self::send(I18n::t('notify.low_stock', self::locale(), [
            'plan' => (string) ($product['name'] ?? '—'),
            'n' => (string) $available,
            'threshold' => (string) $threshold,
        ]));
    }

    /** @param array<string, mixed> $client */
    public static function rebuildRequest(array $client, string $via, ?string $image = null): void
    {
        self::send(I18n::t('notify.rebuild', self::locale(), [
            'name' => (string) ($client['name'] ?? '—'),
            'phone' => (string) ($client['phone'] ?? '—'),
            'server' => (string) ((int) ($client['server_id'] ?? 0) ?: '—'),
            'via' => self::viaLabel($via),
            'image' => $image !== null && $image !== '' ? $image : '—',
        ]));
    }

    private static function locale(): string
    {
        $st = Database::getSettings();
        $loc = (string) ($st['admin_locale'] ?? 'en');
        return $loc === 'ar' ? 'ar' : 'en';
    }

    private static function sourceLabel(string $source): string
    {
        $loc = self::locale();
        return match ($source) {
            'admin' => I18n::t('notify.source.admin', $loc),
            default => I18n::t('notify.source.store', $loc),
        };
    }

    private static function viaLabel(string $via): string
    {
        $loc = self::locale();
        return match ($via) {
            'bot' => I18n::t('notify.via.bot', $loc),
            'admin' => I18n::t('notify.via.admin', $loc),
            default => I18n::t('notify.via.web', $loc),
        };
    }

    public static function send(string $text): void
    {
        if (trim($text) === '' || Config::get('TELEGRAM_BOT_TOKEN') === '') {
            return;
        }
        $ids = array_values(array_filter(array_map('trim', explode(',', Config::get('ADMIN_IDS')))));
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            try {
                Telegram::sendMessage($id, $text);
            } catch (\Throwable $e) {
                error_log('AdminNotify: ' . $e->getMessage());
            }
        }
    }
}
