<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class Admin2fa
{
    private const PENDING_TTL = 300;

    public static function isEnabled(): bool
    {
        $st = Database::getSettings();
        return !empty($st['admin_2fa_enabled']) && self::secret() !== '';
    }

    public static function secret(): string
    {
        return trim((string) (Database::getSettings()['admin_totp_secret'] ?? ''));
    }

    public static function setupSecret(): string
    {
        return trim((string) (Database::getSettings()['admin_totp_setup_secret'] ?? ''));
    }

    public static function beginSetup(): string
    {
        $secret = Totp::randomSecret();
        Database::saveSettings(['admin_totp_setup_secret' => $secret]);
        return $secret;
    }

    public static function confirmSetup(string $code): bool
    {
        $secret = self::setupSecret();
        if ($secret === '' || !Totp::verify($secret, $code)) {
            return false;
        }
        Database::saveSettings([
            'admin_totp_secret' => $secret,
            'admin_totp_setup_secret' => '',
            'admin_2fa_enabled' => true,
        ]);
        return true;
    }

    public static function disable(string $code): bool
    {
        if (!self::isEnabled() || !Totp::verify(self::secret(), $code)) {
            return false;
        }
        Database::saveSettings([
            'admin_totp_secret' => '',
            'admin_totp_setup_secret' => '',
            'admin_2fa_enabled' => false,
        ]);
        return true;
    }

    public static function verifyLoginCode(string $code): bool
    {
        return self::isEnabled() && Totp::verify(self::secret(), $code);
    }

    public static function storePending(string $tok): void
    {
        if ($tok === '') {
            return;
        }
        $now = time();
        $map = Database::getSettings()['admin_2fa_pending'] ?? [];
        if (!is_array($map)) {
            $map = [];
        }
        foreach ($map as $h => $exp) {
            if ($exp < $now) {
                unset($map[$h]);
            }
        }
        $map[hash('sha256', $tok)] = $now + self::PENDING_TTL;
        Database::saveSettings(['admin_2fa_pending' => $map]);
    }

    public static function hasPending(string $tok): bool
    {
        if ($tok === '') {
            return false;
        }
        $map = Database::getSettings()['admin_2fa_pending'] ?? [];
        if (!is_array($map)) {
            return false;
        }
        $exp = (int) ($map[hash('sha256', $tok)] ?? 0);
        return $exp > time();
    }

    public static function consumePending(string $tok): void
    {
        if ($tok === '') {
            return;
        }
        $st = Database::getSettings();
        $map = $st['admin_2fa_pending'] ?? [];
        if (!is_array($map)) {
            return;
        }
        unset($map[hash('sha256', $tok)]);
        Database::saveSettings(['admin_2fa_pending' => $map]);
    }
}
