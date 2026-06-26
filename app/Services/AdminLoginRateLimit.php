<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Limits failed admin login attempts per IP.
 */
final class AdminLoginRateLimit
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SEC = 900;
    private const COOLDOWN_SEC = 1800;
    private const PRUNE_AGE_SEC = 86400;

    /** @return array{blocked: bool, minutes?: int} */
    public static function checkIp(string $ip): array
    {
        if ($ip === '' || $ip === 'unknown') {
            return ['blocked' => false];
        }
        return self::check('i:' . $ip);
    }

    public static function recordFailure(string $ip): void
    {
        if ($ip === '' || $ip === 'unknown') {
            return;
        }
        self::record('i:' . $ip);
    }

    public static function clearIp(string $ip): void
    {
        if ($ip === '' || $ip === 'unknown') {
            return;
        }
        $data = self::read();
        unset($data['i:' . $ip]);
        self::write($data);
    }

    /** @return array{blocked: bool, minutes?: int} */
    private static function check(string $key): array
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
        if ($window > 0 && ($now - $window) > self::WINDOW_SEC) {
            return ['blocked' => false];
        }
        if ($blockedUntil > 0 && $blockedUntil <= $now) {
            return ['blocked' => false];
        }
        $count = (int) ($entry['count'] ?? 0);
        if ($count >= self::MAX_ATTEMPTS) {
            return ['blocked' => true, 'minutes' => self::minutesLeft($now + self::COOLDOWN_SEC, $now)];
        }
        return ['blocked' => false];
    }

    private static function record(string $key): void
    {
        $now = time();
        $data = self::read();
        $entry = $data[$key] ?? ['count' => 0, 'window' => $now, 'blocked_until' => 0];
        $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            return;
        }
        $window = (int) ($entry['window'] ?? $now);
        if (($now - $window) > self::WINDOW_SEC || ($blockedUntil > 0 && $blockedUntil <= $now)) {
            $entry = ['count' => 0, 'window' => $now, 'blocked_until' => 0];
        }
        $entry['count'] = (int) ($entry['count'] ?? 0) + 1;
        if ($entry['count'] >= self::MAX_ATTEMPTS) {
            $entry['blocked_until'] = $now + self::COOLDOWN_SEC;
        }
        $data[$key] = $entry;
        self::write($data);
    }

    private static function minutesLeft(int $until, int $now): int
    {
        return max(1, (int) ceil(($until - $now) / 60));
    }

    private static function path(): string
    {
        return DATA_DIR . '/admin-login-rate.json';
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
