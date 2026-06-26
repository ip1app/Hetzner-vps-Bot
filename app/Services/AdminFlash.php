<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

/** One-time flash payloads tied to an admin session (not exposed in URLs). */
final class AdminFlash
{
    private const TTL_SEC = 120;

    private static function sessionKey(string $sessionTok): string
    {
        return hash('sha256', $sessionTok);
    }

    /** @param mixed $data */
    public static function put(string $sessionTok, string $key, mixed $data): void
    {
        if ($sessionTok === '') {
            return;
        }
        $now = time();
        $flashes = Database::getSettings()['admin_flashes'] ?? [];
        if (!is_array($flashes)) {
            $flashes = [];
        }
        foreach ($flashes as $sk => $entries) {
            if (!is_array($entries)) {
                unset($flashes[$sk]);
                continue;
            }
            foreach ($entries as $k => $entry) {
                if (!is_array($entry) || (int) ($entry['exp'] ?? 0) < $now) {
                    unset($flashes[$sk][$k]);
                }
            }
            if ($flashes[$sk] === []) {
                unset($flashes[$sk]);
            }
        }
        $sk = self::sessionKey($sessionTok);
        $flashes[$sk] ??= [];
        $flashes[$sk][$key] = ['data' => $data, 'exp' => $now + self::TTL_SEC];
        Database::saveSettings(['admin_flashes' => $flashes]);
    }

    /** @return mixed|null */
    public static function pull(string $sessionTok, string $key): mixed
    {
        if ($sessionTok === '') {
            return null;
        }
        $st = Database::getSettings();
        $flashes = $st['admin_flashes'] ?? [];
        if (!is_array($flashes)) {
            return null;
        }
        $sk = self::sessionKey($sessionTok);
        $entry = $flashes[$sk][$key] ?? null;
        unset($flashes[$sk][$key]);
        if (($flashes[$sk] ?? []) === []) {
            unset($flashes[$sk]);
        }
        Database::saveSettings(['admin_flashes' => $flashes]);
        if (!is_array($entry) || (int) ($entry['exp'] ?? 0) < time()) {
            return null;
        }
        return $entry['data'] ?? null;
    }
}
