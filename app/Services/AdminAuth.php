<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class AdminAuth
{
    private const SESSION_MS = 12 * 3600;

    public static function adminUser(): string
    {
        return Config::get('ADMIN_USER', 'admin');
    }

    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function isHashed(string $stored): bool
    {
        return str_starts_with($stored, '$2y$')
            || str_starts_with($stored, '$2a$')
            || str_starts_with($stored, '$argon2');
    }

    public static function verifyLogin(string $user, string $pass): bool
    {
        if (!hash_equals(self::adminUser(), $user)) {
            return false;
        }
        $stored = Config::get('ADMIN_PASS');
        if ($stored === '') {
            return false;
        }
        if (self::isHashed($stored)) {
            return password_verify($pass, $stored);
        }
        return hash_equals($stored, $pass);
    }

    /** Upgrade legacy plain-text ADMIN_PASS in .env after a successful login. */
    public static function rehashIfLegacy(string $plain): void
    {
        $stored = Config::get('ADMIN_PASS');
        if ($stored === '' || self::isHashed($stored)) {
            return;
        }
        if (!hash_equals($stored, $plain)) {
            return;
        }
        EnvFile::update(['ADMIN_PASS' => self::hashPassword($plain)]);
    }

    private static function hashTok(string $tok): string
    {
        return hash('sha256', $tok);
    }

    public static function newSession(): string
    {
        $tok = bin2hex(random_bytes(24));
        $map = Database::getSettings()['admin_sessions'] ?? [];
        $now = time();
        foreach ($map as $h => $exp) {
            if ($exp < $now) {
                unset($map[$h]);
            }
        }
        $map[self::hashTok($tok)] = $now + self::SESSION_MS;
        Database::saveSettings(['admin_sessions' => $map]);
        return $tok;
    }

    /** Invalidate any existing session cookie and issue a fresh token. */
    public static function rotateSession(string $oldTok = ''): string
    {
        if ($oldTok !== '') {
            self::destroySession($oldTok);
        }
        return self::newSession();
    }

    public static function validSession(string $tok): bool
    {
        if ($tok === '') {
            return false;
        }
        $map = Database::getSettings()['admin_sessions'] ?? [];
        $exp = $map[self::hashTok($tok)] ?? 0;
        return $exp > time();
    }

    public static function destroySession(string $tok): void
    {
        if ($tok === '') {
            return;
        }
        $map = Database::getSettings()['admin_sessions'] ?? [];
        unset($map[self::hashTok($tok)]);
        Database::saveSettings(['admin_sessions' => $map]);
    }
}
