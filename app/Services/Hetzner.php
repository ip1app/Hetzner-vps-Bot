<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

/**
 * Hetzner Cloud API client — https://docs.hetzner.cloud/reference/cloud
 * Base URL: https://api.hetzner.cloud/v1
 * Auth: Authorization: Bearer <project API token>
 */
final class Hetzner
{
    private const BASE = 'https://api.hetzner.cloud/v1';
    private const NO_CONN = 'No active Hetzner connection — add a project API token from Security → API tokens in Hetzner Console';

    /** @return list<array<string, mixed>> */
    public static function getAccounts(): array
    {
        $s = Database::getSettings();
        if (empty($s['hetzner_accounts']) || !is_array($s['hetzner_accounts'])) {
            $legacy = trim((string) ($s['hetzner_api_token'] ?? Config::get('HETZNER_API_TOKEN')));
            if ($legacy !== '') {
                return [['id' => 'legacy', 'name' => 'Primary', 'token' => $legacy, 'enabled' => true]];
            }
            return [];
        }
        return array_values(array_map(
            fn($a) => array_merge($a, ['enabled' => ($a['enabled'] ?? true) !== false]),
            $s['hetzner_accounts']
        ));
    }

    /** @return list<array<string, mixed>> */
    public static function enabledAccounts(): array
    {
        return array_values(array_filter(
            self::getAccounts(),
            fn($a) => ($a['enabled'] ?? true) && trim((string) ($a['token'] ?? '')) !== ''
        ));
    }

    public static function tokenFor(string $accountId): string
    {
        foreach (self::getAccounts() as $a) {
            if (($a['id'] ?? '') === $accountId && ($a['enabled'] ?? true)) {
                return trim((string) ($a['token'] ?? ''));
            }
        }
        return '';
    }

    /** @param array<string, scalar|null> $query */
    private static function buildUrl(string $path, array $query = []): string
    {
        $url = self::BASE . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    /** @param array<string, scalar|null> $query */
    /** @return array{code: int, body: mixed, raw: string} */
    private static function api(string $token, string $method, string $path, array $query = [], ?array $data = null): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new \RuntimeException(self::NO_CONN);
        }
        if (str_starts_with($token, 'enc:v1:')) {
            throw new \RuntimeException('Hetzner API token could not be decrypted — check SECRET_KEY in .env');
        }

        $url = self::buildUrl($path, $query);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: Hetzner-vps-Bot/1.2.0',
        ];
        $body = null;
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($data ?? [], JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                throw new \RuntimeException('Cannot encode request body');
            }
        }

        $res = HttpClient::request($method, $url, $body, $headers);
        if ($res['code'] >= 400) {
            throw new \RuntimeException(self::formatApiError($res));
        }
        return $res;
    }

    /** @param array{code: int, body: mixed, raw: string} $res */
    private static function formatApiError(array $res): string
    {
        $code = (int) $res['code'];
        if (is_array($res['body']) && isset($res['body']['error']) && is_array($res['body']['error'])) {
            $err = $res['body']['error'];
            $msg = trim((string) ($err['message'] ?? 'API error'));
            $apiCode = trim((string) ($err['code'] ?? ''));
            if ($code === 401 || $apiCode === 'unauthorized') {
                return 'Invalid Hetzner API token — create a Read & Write token in Hetzner Console → Project → Security → API tokens';
            }
            if ($code === 403 || $apiCode === 'forbidden') {
                return 'Token lacks permission for this action — use a Read & Write API token';
            }
            if ($apiCode !== '') {
                return $msg . ' (' . $apiCode . ', HTTP ' . $code . ')';
            }
            return $msg . ' (HTTP ' . $code . ')';
        }
        if ($code === 0) {
            return 'Network error — could not reach api.hetzner.cloud';
        }
        return 'Hetzner API error (HTTP ' . $code . ')';
    }

    /**
     * Fetch all pages from a list endpoint.
     *
     * @param array<string, scalar|null> $query
     * @return list<array<string, mixed>>
     */
    private static function paginate(string $token, string $path, string $resourceKey, array $query = []): array
    {
        $query['per_page'] = min(50, max(1, (int) ($query['per_page'] ?? 50)));
        $page = 1;
        $all = [];
        do {
            $query['page'] = $page;
            $res = self::api($token, 'GET', $path, $query);
            $chunk = $res['body'][$resourceKey] ?? [];
            if (!is_array($chunk)) {
                break;
            }
            foreach ($chunk as $item) {
                if (is_array($item)) {
                    $all[] = $item;
                }
            }
            $pagination = $res['body']['meta']['pagination'] ?? null;
            $next = is_array($pagination) ? ($pagination['next_page'] ?? null) : null;
            $page = $next !== null ? (int) $next : 0;
        } while ($page > 0);

        return $all;
    }

    public static function explain(\Throwable $e): string
    {
        return $e->getMessage();
    }

    /**
     * Validate token against GET /servers (official quick check).
     *
     * @return array{servers: int}
     */
    public static function validateToken(string $token): array
    {
        $token = trim($token);
        if (strlen($token) < 20) {
            throw new \RuntimeException('API token is too short');
        }
        $res = self::api($token, 'GET', '/servers', ['per_page' => 1]);
        $total = (int) ($res['body']['meta']['pagination']['total_entries'] ?? count($res['body']['servers'] ?? []));
        return ['servers' => $total];
    }

    /** @return list<array<string, mixed>> */
    public static function listServers(?string $accountId = null): array
    {
        $all = [];
        $accounts = $accountId !== null && $accountId !== ''
            ? array_filter(self::getAccounts(), fn($a) => ($a['id'] ?? '') === $accountId)
            : self::enabledAccounts();
        if ($accounts === []) {
            throw new \RuntimeException(self::NO_CONN);
        }
        foreach ($accounts as $acc) {
            if (($acc['enabled'] ?? true) === false) {
                continue;
            }
            $token = trim((string) ($acc['token'] ?? ''));
            if ($token === '') {
                continue;
            }
            foreach (self::paginate($token, '/servers', 'servers') as $s) {
                $s['_account_id'] = $acc['id'] ?? '';
                $s['_account_name'] = $acc['name'] ?? '';
                $all[] = $s;
            }
        }
        return $all;
    }

    /** @return array<string, mixed> */
    public static function getServer(int $id, string $accountId = ''): array
    {
        $accId = self::resolveAccountId($id, $accountId);
        $token = self::tokenFor($accId);
        $res = self::api($token, 'GET', '/servers/' . $id);
        $s = $res['body']['server'] ?? null;
        if (!is_array($s)) {
            throw new \RuntimeException('Server not found');
        }
        $s['_account_id'] = $accId;
        return $s;
    }

    public static function resolveAccountId(int $serverId, string $accountId = ''): string
    {
        if ($accountId !== '' && self::tokenFor($accountId) !== '') {
            return $accountId;
        }
        $fromDb = Database::findAccountForServer($serverId);
        if ($fromDb !== '' && self::tokenFor($fromDb) !== '') {
            return $fromDb;
        }
        foreach (self::enabledAccounts() as $acc) {
            try {
                self::api((string) $acc['token'], 'GET', '/servers/' . $serverId);
                return (string) ($acc['id'] ?? '');
            } catch (\Throwable) {
            }
        }
        throw new \RuntimeException('Could not find Hetzner project for server #' . $serverId);
    }

    public static function powerOn(int $id, string $accountId = ''): void
    {
        $token = self::tokenFor(self::resolveAccountId($id, $accountId));
        self::api($token, 'POST', '/servers/' . $id . '/actions/poweron');
    }

    public static function powerOff(int $id, string $accountId = ''): void
    {
        $token = self::tokenFor(self::resolveAccountId($id, $accountId));
        self::api($token, 'POST', '/servers/' . $id . '/actions/shutdown');
    }

    public static function powerOffForce(int $id, string $accountId = ''): void
    {
        $token = self::tokenFor(self::resolveAccountId($id, $accountId));
        self::api($token, 'POST', '/servers/' . $id . '/actions/poweroff');
    }

    public static function reboot(int $id, string $accountId = ''): void
    {
        $token = self::tokenFor(self::resolveAccountId($id, $accountId));
        self::api($token, 'POST', '/servers/' . $id . '/actions/reboot');
    }

    public static function resetPassword(int $id, string $accountId = ''): string
    {
        $token = self::tokenFor(self::resolveAccountId($id, $accountId));
        $res = self::api($token, 'POST', '/servers/' . $id . '/actions/reset_password');
        $pw = (string) ($res['body']['root_password'] ?? '');
        if ($pw === '') {
            throw new \RuntimeException('Hetzner did not return a root password');
        }
        return $pw;
    }

    /**
     * Hetzner returns the same image name for x86 and arm — keep one entry per name (prefer x86).
     *
     * @param list<array<string, mixed>> $images
     * @return list<array<string, mixed>>
     */
    private static function dedupeImagesByName(array $images): array
    {
        $byName = [];
        foreach ($images as $img) {
            $name = (string) ($img['name'] ?? '');
            if ($name === '') {
                $byName['_id_' . (string) ($img['id'] ?? count($byName))] = $img;
                continue;
            }
            if (!isset($byName[$name])) {
                $byName[$name] = $img;
                continue;
            }
            $arch = (string) ($img['architecture'] ?? 'x86');
            $existingArch = (string) ($byName[$name]['architecture'] ?? 'x86');
            if ($existingArch !== 'x86' && $arch === 'x86') {
                $byName[$name] = $img;
            }
        }
        return array_values($byName);
    }

    /** @return list<array<string, mixed>> */
    public static function listImages(string $accountId = ''): array
    {
        $accId = $accountId;
        if ($accId === '') {
            $enabled = self::enabledAccounts();
            $accId = (string) ($enabled[0]['id'] ?? '');
        }
        $token = self::tokenFor($accId);
        if ($token === '' && self::enabledAccounts() !== []) {
            $token = trim((string) (self::enabledAccounts()[0]['token'] ?? ''));
        }
        $images = self::paginate($token, '/images', 'images', [
            'type' => 'system',
            'architecture' => 'x86',
            'sort' => 'name',
        ]);
        $images = array_values(array_filter($images, static function (array $img): bool {
            if (!empty($img['deprecated'])) {
                return false;
            }
            $type = (string) ($img['type'] ?? '');
            return $type === '' || $type === 'system';
        }));
        return self::dedupeImagesByName($images);
    }

    public static function rebuild(int $id, int $imageId, string $accountId = ''): string
    {
        $token = self::tokenFor(self::resolveAccountId($id, $accountId));
        $res = self::api($token, 'POST', '/servers/' . $id . '/actions/rebuild', [], ['image' => $imageId]);
        return (string) ($res['body']['root_password'] ?? '');
    }
}
