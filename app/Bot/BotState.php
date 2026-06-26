<?php
declare(strict_types=1);

namespace App\Bot;

final class BotState
{
    private static function path(): string
    {
        return DATA_DIR . '/bot_state.json';
    }

    /** @return array<string, array<string, mixed>> */
    private static function loadAll(): array
    {
        $legacy = DATA_DIR . '/bot_rebuild.json';
        $path = self::path();
        if (!is_file($path) && is_file($legacy)) {
            $data = json_decode((string) file_get_contents($legacy), true);
            if (is_array($data)) {
                self::saveAll($data);
            }
        }
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /** @param array<string, array<string, mixed>> $data */
    private static function saveAll(array $data): void
    {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        file_put_contents(self::path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private static function forUser(string $telegramId): array
    {
        return self::loadAll()[$telegramId] ?? [];
    }

    /** @param array<string, mixed> $patch */
    private static function patchUser(string $telegramId, array $patch): void
    {
        $all = self::loadAll();
        $cur = $all[$telegramId] ?? [];
        $all[$telegramId] = array_merge($cur, $patch);
        self::saveAll($all);
    }

    public static function setSelectedClient(string $telegramId, string $clientId): void
    {
        self::patchUser($telegramId, ['selected_client_id' => $clientId]);
    }

    public static function getSelectedClient(string $telegramId): ?string
    {
        $id = (string) (self::forUser($telegramId)['selected_client_id'] ?? '');
        return $id !== '' ? $id : null;
    }

    /** @param array<string, mixed> $state */
    public static function setRebuild(string $telegramId, array $state): void
    {
        self::patchUser($telegramId, ['rebuild' => $state]);
    }

    /** @return array<string, mixed>|null */
    public static function getRebuild(string $telegramId): ?array
    {
        $rb = self::forUser($telegramId)['rebuild'] ?? null;
        return is_array($rb) ? $rb : null;
    }

    public static function clearRebuild(string $telegramId): void
    {
        $all = self::loadAll();
        if (!isset($all[$telegramId])) {
            return;
        }
        unset($all[$telegramId]['rebuild']);
        if ($all[$telegramId] === []) {
            unset($all[$telegramId]);
        }
        self::saveAll($all);
    }

    /** @param array<string, mixed> $state */
    public static function setPurchase(string $telegramId, array $state): void
    {
        self::patchUser($telegramId, ['purchase' => $state]);
    }

    /** @return array<string, mixed>|null */
    public static function getPurchase(string $telegramId): ?array
    {
        $p = self::forUser($telegramId)['purchase'] ?? null;
        return is_array($p) ? $p : null;
    }

    public static function clearPurchase(string $telegramId): void
    {
        $all = self::loadAll();
        if (!isset($all[$telegramId])) {
            return;
        }
        unset($all[$telegramId]['purchase']);
        if ($all[$telegramId] === []) {
            unset($all[$telegramId]);
        }
        self::saveAll($all);
    }
}

