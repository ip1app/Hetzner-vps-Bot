<?php
declare(strict_types=1);

namespace App\Database;

final class Shared
{
    public const SENSITIVE_KEYS = ['paypal_secret', 'hetzner_api_token', 'paypal_client_id'];

    /** @var array<string, mixed> */
    public const DEFAULTS = [
        'clients' => [],
        'products' => [],
        'inventory' => [],
        'invoices' => [],
        'activity' => [],
        'settings' => [],
    ];

    public static function genId(): string
    {
        return base_convert((string) (int) (microtime(true) * 1000), 10, 36)
            . substr(bin2hex(random_bytes(3)), 0, 4);
    }

    /** MySQL DATETIME from ISO/app timestamps (e.g. 2026-06-11T19:26:25+00:00). */
    public static function toMysqlDatetime(?string $value = null): string
    {
        if ($value === null || $value === '') {
            return gmdate('Y-m-d H:i:s');
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $ts = strtotime($value);
            return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        }
    }

    public static function normPhone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        return $d;
    }

    public static function corePhone(string $p): string
    {
        return ltrim(self::normPhone($p), '0');
    }

    /** @param array<string, mixed>|null $client */
    public static function isExpired(?array $client): bool
    {
        if (!$client || empty($client['expires'])) {
            return false;
        }
        return strtotime($client['expires'] . ' 23:59:59') < time();
    }

    /** @param array<string, mixed>|null $client */
    public static function daysLeft(?array $client): ?int
    {
        if (!$client || empty($client['expires'])) {
            return null;
        }
        $end = strtotime($client['expires'] . ' 23:59:59');
        return (int) floor(($end - time()) / 86400);
    }

    /** @param array<string, mixed> $settings */
    public static function encryptSettings(array $settings): array
    {
        foreach (self::SENSITIVE_KEYS as $k) {
            if (!empty($settings[$k])) {
                $settings[$k] = \App\Services\Secrets::encrypt((string) $settings[$k]);
            }
        }
        if (!empty($settings['hetzner_accounts']) && is_array($settings['hetzner_accounts'])) {
            foreach ($settings['hetzner_accounts'] as $i => $a) {
                if (is_array($a) && !empty($a['token'])) {
                    $settings['hetzner_accounts'][$i]['token'] = \App\Services\Secrets::encrypt((string) $a['token']);
                }
            }
        }
        return $settings;
    }

    /** @param array<string, mixed> $settings */
    public static function decryptSettings(array $settings): array
    {
        foreach (self::SENSITIVE_KEYS as $k) {
            if (!empty($settings[$k])) {
                $settings[$k] = \App\Services\Secrets::decrypt((string) $settings[$k]);
            }
        }
        if (!empty($settings['hetzner_accounts']) && is_array($settings['hetzner_accounts'])) {
            foreach ($settings['hetzner_accounts'] as $i => $a) {
                if (is_array($a) && !empty($a['token'])) {
                    $settings['hetzner_accounts'][$i]['token'] = \App\Services\Secrets::decrypt((string) $a['token']);
                }
            }
        }
        return $settings;
    }
}
