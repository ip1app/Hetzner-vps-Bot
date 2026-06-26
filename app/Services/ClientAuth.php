<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Request;
use App\Services\Telegram;

final class ClientAuth
{
    /** @return array{blocked: bool, minutes?: int, warn?: bool, left?: int, error?: string, expires_in?: int} */
    public static function requestOtp(string $phone, string $ip): array
    {
        $rateIp = OtpRateLimit::checkIp($ip);
        if ($rateIp['blocked']) {
            return ['blocked' => true, 'minutes' => (int) ($rateIp['minutes'] ?? 30)];
        }
        $ratePhone = OtpRateLimit::checkPhone($phone);
        if ($ratePhone['blocked']) {
            return ['blocked' => true, 'minutes' => (int) ($ratePhone['minutes'] ?? 30)];
        }
        if (ClientAccounts::byPhone($phone) === []) {
            return ['blocked' => false, 'error' => 'no_sub'];
        }
        $tg = ClientAccounts::telegramForPhone($phone);
        if ($tg === '') {
            return ['blocked' => false, 'error' => 'no_telegram'];
        }
        $code = (string) random_int(100000, 999999);
        ClientAccounts::setOtpForPhone($phone, $code, time() + 300);
        try {
            Telegram::sendMessage($tg, '🔐 Login code: <code>' . $code . '</code>');
        } catch (\Throwable) {
            return ['blocked' => false, 'error' => 'bot_offline'];
        }
        $after = OtpRateLimit::recordPhone($phone);
        OtpRateLimit::recordIp($ip);
        return [
            'blocked' => false,
            'warn' => (bool) ($after['warn'] ?? false),
            'left' => (int) ($after['left'] ?? 0),
            'expires_in' => 300,
        ];
    }

    /** @return array{ok: bool, client?: array<string, mixed>, blocked?: bool, minutes?: int} */
    public static function verifyOtp(string $phone, string $code, string $ip): array
    {
        $ratePhone = OtpRateLimit::checkVerifyPhone($phone);
        if ($ratePhone['blocked']) {
            return ['ok' => false, 'blocked' => true, 'minutes' => (int) ($ratePhone['minutes'] ?? 30)];
        }
        $rateIp = OtpRateLimit::checkVerifyIp($ip);
        if ($rateIp['blocked']) {
            return ['ok' => false, 'blocked' => true, 'minutes' => (int) ($rateIp['minutes'] ?? 30)];
        }
        if (!ClientAccounts::verifyOtpForPhone($phone, $code)) {
            OtpRateLimit::recordVerifyFail($phone, $ip);
            $after = OtpRateLimit::checkVerifyPhone($phone);
            if ($after['blocked']) {
                return ['ok' => false, 'blocked' => true, 'minutes' => (int) ($after['minutes'] ?? 30)];
            }
            return ['ok' => false];
        }
        OtpRateLimit::clearVerifySuccess($phone);
        $client = Database::getClientByPhone($phone);
        return ['ok' => true, 'client' => $client ?? ClientAccounts::byPhone($phone)[0] ?? null];
    }

    public static function bearerToken(Request $req): string
    {
        $auth = $req->header('Authorization');
        if ($auth === '' || !preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return '';
        }
        return $m[1];
    }

    /** @return array<string, mixed>|null */
    public static function clientFromRequest(Request $req): ?array
    {
        $id = ApiJwt::clientIdFromAccess(self::bearerToken($req));
        if ($id === null) {
            return null;
        }
        return Database::getClient($id);
    }
}
