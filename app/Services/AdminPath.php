<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Url;

final class AdminPath
{
    public const DEFAULT = 'admin';
    public const ENV_KEY = 'ADMIN_PATH';
    private const LEGACY_PREFIX = '/admin';

    /** @var list<string> */
    private const RESERVED = [
        'install',
        'account',
        'checkout',
        'subscription',
        'success',
        'set-lang',
        'paypal',
        'cron',
        'public',
        'api',
        'webhook',
        'assets',
        'static',
        'vendor',
        'data',
        'login',
        'logout',
        'tg',
    ];

    public static function segment(): string
    {
        $raw = strtolower(trim(Config::get(self::ENV_KEY, self::DEFAULT)));
        $raw = trim($raw, '/');
        if ($raw === '' || !preg_match('/^[a-z][a-z0-9-]*$/', $raw)) {
            return self::DEFAULT;
        }
        return $raw;
    }

    public static function prefix(): string
    {
        return '/' . self::segment();
    }

    public static function to(string $suffix = ''): string
    {
        $base = self::prefix();
        if ($suffix === '' || $suffix === '/') {
            return $base;
        }
        if (!str_starts_with($suffix, '/')) {
            $suffix = '/' . $suffix;
        }
        return $base . $suffix;
    }

    public static function url(string $suffix = ''): string
    {
        return Url::to(self::to($suffix));
    }

    public static function cookiePath(): string
    {
        return Url::cookiePath(self::prefix());
    }

    public static function validateSegment(string $input): ?string
    {
        $seg = strtolower(trim($input, '/'));
        if ($seg === '') {
            return 'settings.admin_path_required';
        }
        if (strlen($seg) < 2 || strlen($seg) > 32) {
            return 'settings.admin_path_len';
        }
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $seg)) {
            return 'settings.admin_path_format';
        }
        if (in_array($seg, self::RESERVED, true)) {
            return 'settings.admin_path_reserved';
        }
        return null;
    }

    public static function usesLegacyDefault(): bool
    {
        return self::segment() === self::DEFAULT;
    }

    public static function isLegacyRequest(string $strippedPath): bool
    {
        return $strippedPath === self::LEGACY_PREFIX
            || str_starts_with($strippedPath, self::LEGACY_PREFIX . '/');
    }

    public static function fromLegacy(string $strippedPath): string
    {
        if ($strippedPath === self::LEGACY_PREFIX) {
            return self::prefix();
        }
        if (str_starts_with($strippedPath, self::LEGACY_PREFIX . '/')) {
            return self::prefix() . substr($strippedPath, strlen(self::LEGACY_PREFIX));
        }
        return $strippedPath;
    }

    public static function updateSegment(string $segment): void
    {
        EnvFile::update([self::ENV_KEY => $segment]);
    }
}
