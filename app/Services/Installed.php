<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Request;
use App\Response;

final class Installed
{
    public const VERSION = '1.2.2';
    public const DEFAULT_AUTHOR = 'iP1';
    public const DEFAULT_SITE_URL = 'https://ip1.app';

    private static string $lockPath = DATA_DIR . '/installed.lock';

    /** Public site URL for admin footer (WEBHOOK_DOMAIN or default). */
    public static function siteUrl(): string
    {
        $url = rtrim(Config::get('WEBHOOK_DOMAIN'), '/');
        return $url !== '' ? $url : self::DEFAULT_SITE_URL;
    }

    /** Developer credit name (fixed). */
    public static function author(): string
    {
        return self::DEFAULT_AUTHOR;
    }

    public static function isInstalled(): bool
    {
        return is_file(self::$lockPath);
    }

    /** @param array<string, mixed> $meta */
    public static function markInstalled(array $meta = []): void
    {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        $payload = array_merge([
            'installed_at' => date('c'),
            'version' => self::VERSION,
            'runtime' => 'php',
        ], $meta);
        if (file_put_contents(self::$lockPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            throw new \RuntimeException('Cannot write data/installed.lock — check data/ permissions');
        }
    }

    public static function ensureLegacyLock(): void
    {
        if (self::isInstalled()) {
            return;
        }
        if (Config::get('ADMIN_PASS') !== '') {
            self::markInstalled(['legacy' => true]);
        }
    }

    public static function isSetupPath(string $path): bool
    {
        return $path === '/setup.php'
            || $path === '/install'
            || $path === '/setup'
            || str_starts_with($path, '/install/');
    }

    /** Block public routes until installation — no redirect to the setup wizard. */
    public static function requireInstalledOrExit(): void
    {
        if (self::isInstalled()) {
            return;
        }
        self::respondUnavailable();
    }

    public static function respondUnavailable(?Request $req = null): never
    {
        $req ??= Request::capture();
        $loc = self::guessLocale($req);
        $e = Html::esc(...);
        $body = '<!DOCTYPE html><html lang="' . $e($loc) . '" dir="' . ($loc === 'ar' ? 'rtl' : 'ltr') . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e(I18n::t('pending.title', $loc)) . '</title>'
            . '<style>body{font-family:Segoe UI,Tahoma,sans-serif;background:#0b1626;color:#e8eef7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;margin:0}'
            . '.card{background:rgba(19,35,58,.92);border:1px solid #25405f;border-radius:20px;padding:36px 28px;max-width:520px;width:100%;text-align:center}'
            . 'h1{font-size:22px;margin:0 0 12px}p{color:#9fb3cc;line-height:1.6;margin:0}</style></head><body>'
            . '<div class="card"><div style="font-size:40px;margin-bottom:12px" aria-hidden="true">⏳</div>'
            . '<h1>' . $e(I18n::t('pending.heading', $loc)) . '</h1>'
            . '<p>' . $e(I18n::t('pending.desc', $loc)) . '</p></div></body></html>';
        Response::html($body, 503);
    }

    private static function guessLocale(Request $req): string
    {
        $fromCookie = $req->cookie('ulang');
        if ($fromCookie !== '') {
            return Locale::norm($fromCookie);
        }
        $h = $req->header('Accept-Language');
        if ($h !== '') {
            $part = trim(explode(',', $h)[0]);
            if ($part !== '') {
                return Locale::norm($part);
            }
        }
        return Locale::DEFAULT;
    }
}
