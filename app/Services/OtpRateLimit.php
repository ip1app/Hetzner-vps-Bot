<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Limits OTP login code sends per phone (and per IP) to reduce abuse.
 */
final class OtpRateLimit
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SEC = 900;
    private const COOLDOWN_SEC = 1800;
    private const PRUNE_AGE_SEC = 86400;

    /** @return array{blocked: bool, minutes?: int, warn?: bool, left?: int} */
    public static function checkPhone(string $phone): array
    {
        return self::check(self::phoneKey($phone));
    }

    /** @return array{blocked: bool, minutes?: int} */
    public static function checkIp(string $ip): array
    {
        if ($ip === '') {
            return ['blocked' => false];
        }
        return self::check(self::ipKey($ip), self::MAX_ATTEMPTS * 3);
    }

    /**
     * Record a successful OTP send attempt.
     *
     * @return array{warn: bool, left: int}
     */
    public static function recordPhone(string $phone): array
    {
        return self::record(self::phoneKey($phone));
    }

    public static function recordIp(string $ip): void
    {
        if ($ip === '') {
            return;
        }
        self::record(self::ipKey($ip), self::MAX_ATTEMPTS * 3);
    }

    private const MAX_VERIFY = 8;
    private const VERIFY_WINDOW_SEC = 900;
    private const VERIFY_COOLDOWN_SEC = 1800;

    /** @return array{blocked: bool, minutes?: int} */
    public static function checkVerifyPhone(string $phone): array
    {
        return self::checkVerify(self::verifyPhoneKey($phone));
    }

    /** @return array{blocked: bool, minutes?: int} */
    public static function checkVerifyIp(string $ip): array
    {
        if ($ip === '') {
            return ['blocked' => false];
        }
        return self::checkVerify(self::verifyIpKey($ip), self::MAX_VERIFY * 3);
    }

    public static function recordVerifyFail(string $phone, string $ip): void
    {
        self::recordVerify(self::verifyPhoneKey($phone));
        if ($ip !== '') {
            self::recordVerify(self::verifyIpKey($ip), self::MAX_VERIFY * 3);
        }
    }

    public static function clearVerifySuccess(string $phone): void
    {
        $data = self::read();
        unset($data[self::verifyPhoneKey($phone)]);
        self::write($data);
    }

    /** @return array{blocked: bool, minutes?: int} */
    private static function checkVerify(string $key, int $max = self::MAX_VERIFY): array
    {
        $now = time();
        $entry = self::read()[$key] ?? null;
        if (!is_array($entry)) {
            return ['blocked' => false];
        }
        $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return ['blocked' => true, 'minutes' => self::minutesLeft($blockedUntil, $now)];
        }
        $window = (int) ($entry['window'] ?? 0);
        if ($window > 0 && ($now - $window) > self::VERIFY_WINDOW_SEC) {
            return ['blocked' => false];
        }
        if ($blockedUntil > 0 && $blockedUntil <= $now) {
            return ['blocked' => false];
        }
        $count = (int) ($entry['count'] ?? 0);
        if ($count >= $max) {
            return ['blocked' => true, 'minutes' => self::minutesLeft($now + self::VERIFY_COOLDOWN_SEC, $now)];
        }
        return ['blocked' => false];
    }

    private static function recordVerify(string $key, int $max = self::MAX_VERIFY): void
    {
        $now = time();
        $data = self::read();
        $entry = $data[$key] ?? ['count' => 0, 'window' => $now, 'blocked_until' => 0];
        $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return;
        }
        $window = (int) ($entry['window'] ?? $now);
        if (($now - $window) > self::VERIFY_WINDOW_SEC || ($blockedUntil > 0 && $blockedUntil <= $now)) {
            $entry = ['count' => 0, 'window' => $now, 'blocked_until' => 0];
        }
        $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
        if ($entry['count'] >= $max) {
            $entry['blocked_until'] = $now + self::VERIFY_COOLDOWN_SEC;
        }
        $data[$key] = $entry;
        self::write($data);
    }

    private static function verifyPhoneKey(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        return 'vp:' . ($phone !== '' ? $phone : 'unknown');
    }

    private static function verifyIpKey(string $ip): string
    {
        return 'vi:' . $ip;
    }

    /** @return array{blocked: bool, minutes?: int, warn?: bool, left?: int} */
    private static function check(string $key, int $max = self::MAX_ATTEMPTS): array
    {
        $now = time();
        $data = self::read();
        $entry = $data[$key] ?? null;
        if (!is_array($entry)) {
            return ['blocked' => false];
        }
        $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return ['blocked' => true, 'minutes' => self::minutesLeft($blockedUntil, $now)];
        }
        $window = (int) ($entry['window'] ?? 0);
        if ($window > 0 && ($now - $window) > self::WINDOW_SEC) {
            return ['blocked' => false];
        }
        if ($blockedUntil > 0 && $blockedUntil <= $now) {
            return ['blocked' => false];
        }
        $count = (int) ($entry['count'] ?? 0);
        if ($count >= $max) {
            return ['blocked' => true, 'minutes' => self::minutesLeft($now + self::COOLDOWN_SEC, $now)];
        }
        return ['blocked' => false, 'left' => max(0, $max - $count)];
    }

    /** @return array{warn: bool, left: int} */
    private static function record(string $key, int $max = self::MAX_ATTEMPTS): array
    {
        $now = time();
        $data = self::read();
        $entry = $data[$key] ?? ['count' => 0, 'window' => $now, 'blocked_until' => 0];
        $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return ['warn' => false, 'left' => 0];
        }
        $window = (int) ($entry['window'] ?? $now);
        if (($now - $window) > self::WINDOW_SEC || ($blockedUntil > 0 && $blockedUntil <= $now)) {
            $entry = ['count' => 0, 'window' => $now, 'blocked_until' => 0];
        }
        $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
        if ($entry['count'] >= $max) {
            $entry['blocked_until'] = $now + self::COOLDOWN_SEC;
        }
        $data[$key] = $entry;
        self::write($data);
        $left = max(0, $max - (int) $entry['count']);
        return ['warn' => $left <= 1 && $left > 0, 'left' => $left];
    }

    private static function phoneKey(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';
        return 'p:' . ($phone !== '' ? $phone : 'unknown');
    }

    private static function ipKey(string $ip): string
    {
        return 'i:' . $ip;
    }

    private static function minutesLeft(int $until, int $now): int
    {
        return max(1, (int) ceil(($until - $now) / 60));
    }

    private static function path(): string
    {
        return DATA_DIR . '/otp-rate.json';
    }

    /** @return array<string, array<string, int>> */
    private static function read(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, array<string, int>> $data */
    private static function write(array $data): void
    {
        $now = time();
        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {
                unset($data[$key]);
                continue;
            }
            $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
            $window = (int) ($entry['window'] ?? 0);
            if ($blockedUntil < $now && ($now - $window) > self::PRUNE_AGE_SEC) {
                unset($data[$key]);
            }
        }
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        file_put_contents(self::path(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
