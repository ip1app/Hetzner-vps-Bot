<?php
declare(strict_types=1);

namespace App\Contracts;

interface ServerProvider
{
    public function slug(): string;

    public function name(): string;

    public function isConfigured(): bool;

    /** @return array<string, mixed> */
    public function getServer(int $serverId, string $accountId = ''): array;

    /** @return list<array<string, mixed>> */
    public function listImages(string $accountId = ''): array;

    /** @return list<array<string, mixed>> */
    public function listServers(?string $accountId = null): array;

    /** @return list<array<string, mixed>> */
    public function getAccounts(): array;

    /** @return list<array<string, mixed>> */
    public function enabledAccounts(): array;

    /** @return array{servers: int} */
    public function validateToken(string $token): array;

    public function powerOn(int $serverId, string $accountId = ''): void;

    public function powerOff(int $serverId, string $accountId = ''): void;

    public function powerOffForce(int $serverId, string $accountId = ''): void;

    public function reboot(int $serverId, string $accountId = ''): void;

    public function resetPassword(int $serverId, string $accountId = ''): string;

    public function rebuild(int $serverId, int $imageId, string $accountId = ''): string;

    public function explain(\Throwable $e): string;
}
