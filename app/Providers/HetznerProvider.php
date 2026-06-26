<?php
declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ServerProvider;
use App\Services\Hetzner;
use App\Services\IntegrityGuard;

final class HetznerProvider implements ServerProvider
{
    public function slug(): string
    {
        return 'hetzner';
    }

    public function name(): string
    {
        return 'Hetzner Cloud';
    }

    public function isConfigured(): bool
    {
        return Hetzner::enabledAccounts() !== [];
    }

    public function getServer(int $serverId, string $accountId = ''): array
    {
        return Hetzner::getServer($serverId, $accountId);
    }

    public function listImages(string $accountId = ''): array
    {
        return Hetzner::listImages($accountId);
    }

    public function listServers(?string $accountId = null): array
    {
        return Hetzner::listServers($accountId);
    }

    public function getAccounts(): array
    {
        return Hetzner::getAccounts();
    }

    public function enabledAccounts(): array
    {
        return Hetzner::enabledAccounts();
    }

    public function validateToken(string $token): array
    {
        return Hetzner::validateToken($token);
    }

    public function powerOn(int $serverId, string $accountId = ''): void
    {
        IntegrityGuard::assertFeaturesEnabled();
        Hetzner::powerOn($serverId, $accountId);
    }

    public function powerOff(int $serverId, string $accountId = ''): void
    {
        IntegrityGuard::assertFeaturesEnabled();
        Hetzner::powerOff($serverId, $accountId);
    }

    public function powerOffForce(int $serverId, string $accountId = ''): void
    {
        IntegrityGuard::assertFeaturesEnabled();
        Hetzner::powerOffForce($serverId, $accountId);
    }

    public function reboot(int $serverId, string $accountId = ''): void
    {
        IntegrityGuard::assertFeaturesEnabled();
        Hetzner::reboot($serverId, $accountId);
    }

    public function resetPassword(int $serverId, string $accountId = ''): string
    {
        IntegrityGuard::assertFeaturesEnabled();
        return Hetzner::resetPassword($serverId, $accountId);
    }

    public function rebuild(int $serverId, int $imageId, string $accountId = ''): string
    {
        IntegrityGuard::assertFeaturesEnabled();
        return Hetzner::rebuild($serverId, $imageId, $accountId);
    }

    public function explain(\Throwable $e): string
    {
        return Hetzner::explain($e);
    }
}
