<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Services\AdminPath;
use App\Services\Config;

/** Auto-detect base path for any subdirectory install (no hardcoded folder name). */
final class Url
{
    private static string $base = '';
    private static bool $ready = false;

    /** @var list<string> */
    private const ROUTE_MARKERS = [
        '/install',
        '/setup.php',
        '/account',
        '/checkout',
        '/subscription',
        '/success',
        '/set-lang',
        '/paypal',
        '/tg/',
    ];

    /** @return list<string> */
    private static function routeMarkers(): array
    {
        return array_merge([AdminPath::prefix()], self::ROUTE_MARKERS);
    }

    public static function init(): void
    {
        if (self::$ready) {
            return;
        }
        self::$ready = true;

        $uri = self::requestPath();

        foreach ([
            fn() => self::baseFromFilesystem(),
            fn() => self::pickMatchingBase(self::scriptDirs(), $uri),
            fn() => self::baseFromUri($uri),
        ] as $detect) {
            $base = $detect();
            if ($base !== null) {
                self::$base = $base;
                return;
            }
        }

        $configured = trim(Config::get('BASE_PATH', ''));
        if ($configured !== '') {
            self::$base = $configured === '/' ? '' : rtrim(str_replace('\\', '/', $configured), '/');
            return;
        }

        self::$base = '';
    }

    /** Project folder relative to document root (e.g. /shop, /client-1 — any name). */
    private static function baseFromFilesystem(): ?string
    {
        $docRoot = self::normPath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $project = self::normPath(ROOT);
        $public = self::normPath(ROOT . '/public');
        if ($docRoot === '' || $project === '') {
            return null;
        }

        if (str_starts_with($project, $docRoot)) {
            $rel = substr($project, strlen($docRoot));
            if ($rel === '' || $rel === '/') {
                return '';
            }
            return rtrim($rel, '/');
        }

        // Document root points at public/ — URLs have no extra prefix
        if ($public !== '' && ($docRoot === $public || str_starts_with($docRoot, $public . '/'))) {
            return '';
        }

        return null;
    }

    /** @return list<string> */
    private static function scriptDirs(): array
    {
        $dirs = [];
        foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $dir = str_replace('\\', '/', dirname((string) $_SERVER[$key]));
            if ($dir === '/' || $dir === '.') {
                continue;
            }
            $dirs[] = $dir;
            $base = basename($dir);
            if ($base === 'public' || $base === 'install') {
                $parent = dirname($dir);
                if ($parent !== '/' && $parent !== '.') {
                    $dirs[] = $parent;
                }
            }
        }
        return array_values(array_unique($dirs));
    }

    /** @param list<string> $candidates */
    private static function pickMatchingBase(array $candidates, string $uri): ?string
    {
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn($a, $b) => strlen($a) <=> strlen($b));
        foreach ($candidates as $c) {
            if ($uri === $c || str_starts_with($uri, $c . '/')) {
                return $c;
            }
        }
        return null;
    }

    private static function requestPath(): string
    {
        foreach (['REQUEST_URI', 'REDIRECT_URL', 'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL'] as $key) {
            if (!empty($_SERVER[$key])) {
                $uri = (string) $_SERVER[$key];
                return rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';
            }
        }
        return '/';
    }

    private static function baseFromUri(string $uri): ?string
    {
        $best = null;
        foreach (self::routeMarkers() as $mark) {
            $pos = strpos($uri, $mark);
            if ($pos > 0) {
                $prefix = rtrim(substr($uri, 0, $pos), '/');
                if ($prefix !== '' && ($best === null || strlen($prefix) < strlen($best))) {
                    $best = $prefix;
                }
            }
        }
        return $best;
    }

    private static function normPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $real = realpath($path);
        if ($real !== false) {
            $path = str_replace('\\', '/', $real);
        }
        return rtrim($path, '/');
    }

    public static function base(): string
    {
        self::init();
        return self::$base;
    }

    public static function strip(string $path): string
    {
        self::init();
        $path = rtrim($path, '/') ?: '/';
        if (self::$base !== '' && ($path === self::$base || str_starts_with($path, self::$base . '/'))) {
            $path = substr($path, strlen(self::$base)) ?: '/';
        }
        return $path ?: '/';
    }

    public static function to(string $path = '/'): string
    {
        self::init();
        if ($path === '' || $path === '/') {
            return self::$base ?: '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if (self::$base !== '' && (str_starts_with($path, self::$base . '/') || $path === self::$base)) {
            return $path;
        }
        return self::$base . $path;
    }

    public static function cookiePath(string $path = '/'): string
    {
        return self::to($path);
    }

    /** @internal Test helper */
    public static function resetForTests(): void
    {
        self::$base = '';
        self::$ready = false;
    }
}
