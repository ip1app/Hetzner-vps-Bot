<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\MysqlDatabase;

final class ApiJwt
{
    private const ACCESS_TTL = 3600;
    private const REFRESH_TTL = 30 * 86400;

    public static function accessTtl(): int
    {
        return self::ACCESS_TTL;
    }

    public static function isConfigured(): bool
    {
        return self::rawSecret() !== '';
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
     */
    public static function issuePair(string $clientId, bool $revokeExisting = false): array
    {
        self::ensureConfigured();
        if ($revokeExisting && MysqlDatabase::isConfigured()) {
            MysqlDatabase::revokeClientRefreshTokens($clientId);
        }
        $now = time();
        $jti = bin2hex(random_bytes(16));
        $refreshExp = $now + self::REFRESH_TTL;
        if (MysqlDatabase::isConfigured()) {
            MysqlDatabase::storeRefreshToken($clientId, $jti, $refreshExp);
        }
        return [
            'access_token' => self::encode([
                'sub' => $clientId,
                'typ' => 'access',
                'iat' => $now,
                'exp' => $now + self::ACCESS_TTL,
            ]),
            'refresh_token' => self::encode([
                'sub' => $clientId,
                'typ' => 'refresh',
                'jti' => $jti,
                'iat' => $now,
                'exp' => $refreshExp,
            ]),
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
        ];
    }

    /**
     * Validate refresh token, revoke it, and issue a rotated pair.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}|null
     */
    public static function rotateRefresh(string $token): ?array
    {
        self::ensureConfigured();
        $payload = self::decode($token);
        if ($payload === null || ($payload['typ'] ?? '') !== 'refresh') {
            return null;
        }
        $clientId = (string) ($payload['sub'] ?? '');
        $jti = (string) ($payload['jti'] ?? '');
        if ($clientId === '' || $jti === '') {
            return null;
        }
        if (!MysqlDatabase::isConfigured()) {
            return null;
        }
        if (!MysqlDatabase::consumeRefreshToken($clientId, $jti)) {
            return null;
        }
        if (\App\Database\Database::getClient($clientId) === null) {
            return null;
        }
        return self::issuePair($clientId);
    }

    public static function clientIdFromAccess(string $token): ?string
    {
        $payload = self::decode($token);
        if ($payload === null || ($payload['typ'] ?? '') !== 'access') {
            return null;
        }
        $sub = (string) ($payload['sub'] ?? '');
        return $sub !== '' ? $sub : null;
    }

    public static function clientIdFromRefresh(string $token): ?string
    {
        $payload = self::decode($token);
        if ($payload === null || ($payload['typ'] ?? '') !== 'refresh') {
            return null;
        }
        $sub = (string) ($payload['sub'] ?? '');
        return $sub !== '' ? $sub : null;
    }

    public static function ensureConfigured(): void
    {
        if (!self::isConfigured()) {
            throw new JwtNotConfiguredException('JWT_SECRET or SECRET_KEY must be set.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        $header = self::b64Raw(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_UNICODE) ?: '{}');
        $body = self::b64Raw(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        $sig = self::b64Raw(hash_hmac('sha256', $header . '.' . $body, self::secret(), true));
        return $header . '.' . $body . '.' . $sig;
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;
        $expected = self::b64Raw(hash_hmac('sha256', $h . '.' . $p, self::secret(), true));
        if (!hash_equals($expected, $s)) {
            return null;
        }
        $json = self::b64Decode($p);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $exp = (int) ($data['exp'] ?? 0);
        if ($exp > 0 && time() > $exp) {
            return null;
        }
        return $data;
    }

    private static function rawSecret(): string
    {
        $jwt = Config::get('JWT_SECRET');
        if ($jwt !== '') {
            return $jwt;
        }
        return Config::get('SECRET_KEY');
    }

    private static function secret(): string
    {
        $base = self::rawSecret();
        if ($base === '') {
            throw new JwtNotConfiguredException('JWT_SECRET or SECRET_KEY must be set.');
        }
        return hash('sha256', $base . '|api-jwt-v1');
    }

    private static function b64Raw(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64Decode(string $data): ?string
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($data, '-_', '+/'), true);
        return $raw !== false ? $raw : null;
    }
}
