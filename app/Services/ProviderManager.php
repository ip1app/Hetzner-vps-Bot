<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\ServerProvider;
use App\Database\Database;
use App\Providers\HetznerProvider;

final class ProviderManager
{
    /** @var array<string, ServerProvider>|null */
    private static ?array $registry = null;

    /** @return array<string, ServerProvider> */
    public static function all(): array
    {
        if (self::$registry === null) {
            self::$registry = [
                'hetzner' => new HetznerProvider(),
            ];
        }
        return self::$registry;
    }

    public static function get(string $slug): ?ServerProvider
    {
        return self::all()[$slug] ?? null;
    }

    public static function activeSlug(): string
    {
        $slug = trim((string) (Database::getSettings()['server_provider'] ?? 'hetzner'));
        return self::get($slug) !== null ? $slug : 'hetzner';
    }

    public static function active(): ServerProvider
    {
        $provider = self::get(self::activeSlug());
        return $provider ?? new HetznerProvider();
    }

    public static function isActiveConfigured(): bool
    {
        return self::active()->isConfigured();
    }

    /** @param array<string, mixed> $client */
    public static function forClient(array $client): ServerProvider
    {
        $slug = trim((string) ($client['server_provider'] ?? ''));
        if ($slug === '' || self::get($slug) === null) {
            return self::active();
        }
        return self::get($slug) ?? self::active();
    }

    /** @param array<string, mixed> $client */
    public static function accountIdForClient(array $client): string
    {
        return (string) ($client['hetzner_account_id'] ?? '');
    }
}
