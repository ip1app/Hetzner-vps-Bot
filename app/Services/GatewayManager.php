<?php
declare(strict_types=1);

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Database\Database;
use App\Gateways\PayPalGateway;

final class GatewayManager
{
    /** @var array<string, PaymentGateway>|null */
    private static ?array $registry = null;

    /** @return array<string, PaymentGateway> */
    public static function all(): array
    {
        if (self::$registry === null) {
            self::$registry = [
                'paypal' => new PayPalGateway(),
            ];
        }
        return self::$registry;
    }

    public static function get(string $slug): ?PaymentGateway
    {
        return self::all()[$slug] ?? null;
    }

    public static function activeSlug(): string
    {
        $slug = trim((string) (Database::getSettings()['payment_gateway'] ?? 'paypal'));
        return self::get($slug) !== null ? $slug : 'paypal';
    }

    public static function active(): PaymentGateway
    {
        $gw = self::get(self::activeSlug());
        return $gw ?? new PayPalGateway();
    }

    public static function isActiveConfigured(): bool
    {
        return IntegrityGuard::featuresEnabled() && self::active()->isConfigured();
    }
}
