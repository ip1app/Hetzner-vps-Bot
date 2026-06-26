<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Helpers\Url;

final class Renewal
{
    /** @param array<string, mixed> $client */
    public static function productIdForClient(array $client): ?string
    {
        return Database::productIdForClient($client);
    }

    /** @param array<string, mixed> $client */
    public static function checkoutUrl(array $client): ?string
    {
        $parts = self::checkoutParts($client);
        if ($parts === null) {
            return null;
        }
        return Url::to('/checkout/' . $parts['pid']) . '?' . $parts['query'];
    }

    /** Full https URL for Telegram, API, and other off-site links. */
    public static function absoluteCheckoutUrl(array $client): ?string
    {
        $parts = self::checkoutParts($client);
        if ($parts === null) {
            return null;
        }
        $siteBase = self::publicSiteBase();
        if ($siteBase === '') {
            return null;
        }
        $parsed = parse_url($siteBase);
        if (!isset($parsed['host'])) {
            return null;
        }
        $origin = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }
        return $origin . Url::to('/checkout/' . $parts['pid']) . '?' . $parts['query'];
    }

    public static function publicSiteBase(): string
    {
        $st = Database::getSettings();
        $url = trim((string) ($st['store_url'] ?? ''));
        if ($url === '') {
            $url = trim(Config::get('WEBHOOK_DOMAIN', ''));
        }
        if ($url === '') {
            return '';
        }
        $base = rtrim($url, '/');
        $path = Url::base();
        if ($path !== '' && !str_ends_with($base, $path)) {
            $base .= $path;
        }
        return $base;
    }

    /** @param array<string, mixed> $client */
    /** @return array{pid: string, query: string}|null */
    private static function checkoutParts(array $client): ?array
    {
        $pid = self::productIdForClient($client);
        if ($pid === null) {
            return null;
        }
        return [
            'pid' => $pid,
            'query' => http_build_query([
                'name' => (string) ($client['name'] ?? ''),
                'phone' => (string) ($client['phone'] ?? ''),
                'renew' => '1',
                'client_id' => (string) ($client['id'] ?? ''),
            ]),
        ];
    }

    /** Resolve the subscription row to extend for a renewal checkout. */
    public static function resolveRenewClient(string $phone, string $productId, string $clientId = ''): ?array
    {
        if ($clientId !== '') {
            $c = Database::getClient($clientId);
            if ($c === null) {
                return null;
            }
            if ($phone !== '' && !ClientAccounts::phonesMatch($phone, (string) ($c['phone'] ?? ''))) {
                return null;
            }
            if ($productId !== '') {
                $pid = self::productIdForClient($c);
                if ($pid !== null && $pid !== $productId) {
                    return null;
                }
            }
            return $c;
        }
        if ($phone === '' || $productId === '') {
            return null;
        }
        return Database::findClientByPhoneAndProduct($phone, $productId);
    }

    /** @param array<string, mixed> $inv */
    public static function isRenewalInvoice(array $inv): bool
    {
        $cid = trim((string) ($inv['client_id'] ?? ''));
        return $cid !== '' && Database::getClient($cid) !== null;
    }
}
