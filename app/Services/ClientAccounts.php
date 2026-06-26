<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

/** Multi-subscription helpers (same phone / telegram, one row per server). */
final class ClientAccounts
{
    private const OTP_HASH_PREFIX = 'h1:';

    public static function phonesMatch(string $a, string $b): bool
    {
        $n = Database::corePhone($a);
        $m = Database::corePhone($b);
        if (strlen($n) < 7 || strlen($m) < 7) {
            return false;
        }
        return $n === $m || str_ends_with($n, $m) || str_ends_with($m, $n);
    }

    /** @return list<array<string, mixed>> */
    public static function byPhone(string $phone): array
    {
        return Database::listClientsByPhone($phone);
    }

    /** @return list<array<string, mixed>> */
    public static function byTelegramId(string $tgId): array
    {
        return Database::listClientsByTelegramId($tgId);
    }

    public static function telegramForPhone(string $phone): string
    {
        foreach (self::byPhone($phone) as $c) {
            $tg = trim((string) ($c['telegram_id'] ?? ''));
            if ($tg !== '') {
                return $tg;
            }
        }
        return '';
    }

    public static function setOtpForPhone(string $phone, string $code, int $exp): void
    {
        $stored = self::hashOtp($code);
        foreach (self::byPhone($phone) as $c) {
            Database::updateClient($c['id'], ['otp_code' => $stored, 'otp_exp' => (string) $exp]);
        }
    }

    public static function verifyOtpForPhone(string $phone, string $code): bool
    {
        foreach (self::byPhone($phone) as $c) {
            $stored = (string) ($c['otp_code'] ?? '');
            if ($stored === '' || time() > (int) ($c['otp_exp'] ?? 0)) {
                continue;
            }
            if (!self::otpMatches($stored, $code)) {
                continue;
            }
            foreach (self::byPhone($phone) as $cc) {
                Database::updateClient($cc['id'], ['otp_code' => '', 'otp_exp' => '0']);
            }
            return true;
        }
        return false;
    }

    public static function linkTelegramToPhone(string $tgId, string $phone): void
    {
        foreach (Database::listClients() as $c) {
            if (($c['telegram_id'] ?? '') === $tgId) {
                Database::updateClient($c['id'], ['telegram_id' => '']);
            }
        }
        foreach (self::byPhone($phone) as $c) {
            Database::updateClient($c['id'], ['telegram_id' => $tgId]);
        }
    }

    /** @param array<string, mixed> $client */
    public static function belongsToPhone(array $client, string $phone): bool
    {
        return self::phonesMatch((string) ($client['phone'] ?? ''), $phone);
    }

    /** @param list<array<string, mixed>> $clients */
    public static function labelFor(array $client, string $loc): string
    {
        $pid = Database::productIdForClient($client);
        if ($pid !== null) {
            $p = Database::getProduct($pid);
            if ($p) {
                return (string) $p['name'];
            }
        }
        $sid = (int) ($client['server_id'] ?? 0);
        return $sid > 0 ? '#' . $sid : \App\Helpers\I18n::t('account.products.unnamed', $loc);
    }

    private static function otpKey(): string
    {
        $base = Config::get('JWT_SECRET');
        if ($base === '') {
            $base = Config::get('SECRET_KEY');
        }
        if ($base === '') {
            $base = Config::get('TELEGRAM_BOT_TOKEN');
        }
        return hash('sha256', $base . '|otp-v1', true);
    }

    private static function hashOtp(string $code): string
    {
        return self::OTP_HASH_PREFIX . hash_hmac('sha256', $code, self::otpKey());
    }

    private static function otpMatches(string $stored, string $code): bool
    {
        if (str_starts_with($stored, self::OTP_HASH_PREFIX)) {
            return hash_equals($stored, self::hashOtp($code));
        }
        return hash_equals($stored, $code);
    }
}
