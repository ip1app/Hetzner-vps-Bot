<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Html;
use App\Helpers\Url;

final class BrandTheme
{
    public const DEFAULT_PRIMARY = '#8a63ff';
    public const DEFAULT_SECONDARY = '#4cc9f0';
    public const DEFAULT_BG = '#0b0e1a';

    private const LOGO_KEY = 'store_logo';
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;

    /** @var list<string> */
    private const LOGO_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'];

    public static function brandDir(): string
    {
        return dirname(__DIR__, 2) . '/public/brand';
    }

    public static function normalizeHex(string $raw, string $fallback): string
    {
        $raw = trim($raw);
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw, $m)) {
            return $fallback;
        }
        if (strlen($m[1]) === 3) {
            return strtolower('#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2]);
        }
        return strtolower($raw);
    }

    /** @param array<string, mixed> $st */
    public static function primary(array $st): string
    {
        return self::normalizeHex((string) ($st['brand_primary'] ?? ''), self::DEFAULT_PRIMARY);
    }

    /** @param array<string, mixed> $st */
    public static function secondary(array $st): string
    {
        return self::normalizeHex((string) ($st['brand_secondary'] ?? ''), self::DEFAULT_SECONDARY);
    }

    /** @param array<string, mixed> $st */
    public static function bg(array $st): string
    {
        return self::normalizeHex((string) ($st['brand_bg'] ?? ''), self::DEFAULT_BG);
    }

    /** @param array<string, mixed> $st */
    public static function relativeLogoPath(array $st): string
    {
        $rel = trim((string) ($st[self::LOGO_KEY] ?? ''));
        if ($rel === '' || str_contains($rel, '..') || !str_starts_with($rel, 'brand/')) {
            return '';
        }
        $full = dirname(__DIR__, 2) . '/public/' . $rel;
        return is_file($full) ? $rel : '';
    }

    public static function normalizeLogoUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!filter_var($raw, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('Invalid logo URL');
        }
        $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Invalid logo URL');
        }
        return $raw;
    }

    /** @param array<string, mixed> $st */
    public static function logoInputValue(array $st): string
    {
        $raw = trim((string) ($st[self::LOGO_KEY] ?? ''));
        if ($raw === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }
        $rel = self::relativeLogoPath($st);
        return $rel !== '' ? Url::to('/' . $rel) : '';
    }

    /** @param array<string, mixed> $st */
    public static function logoUrl(array $st): string
    {
        $raw = trim((string) ($st[self::LOGO_KEY] ?? ''));
        if ($raw !== '' && preg_match('#^https?://#i', $raw)) {
            return self::normalizeLogoUrl($raw);
        }
        $rel = self::relativeLogoPath($st);
        return $rel !== '' ? Url::to('/' . $rel) : '';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /** @param array<string, mixed> $st */
    public static function cssOverrides(array $st): string
    {
        $p = self::primary($st);
        $s = self::secondary($st);
        $bg = self::bg($st);
        [$r, $g, $b] = self::hexToRgb($p);
        return ':root{'
            . '--primary:' . $p . ';'
            . '--secondary:' . $s . ';'
            . '--bg:' . $bg . ';'
            . '--grad:linear-gradient(135deg,' . $p . ',' . $s . ');'
            . '--glow:0 10px 40px rgba(' . $r . ',' . $g . ',' . $b . ',.35);'
            . '--border-strong:rgba(' . $r . ',' . $g . ',' . $b . ',.4);'
            . '--mx-bg:' . $bg . ';'
            . '--mx-purple:' . $p . ';'
            . '--mx-cyan:' . $s . ';'
            . '--mx-grad:linear-gradient(135deg,' . $p . ',' . $s . ');'
            . '--mx-glow:0 10px 40px rgba(' . $r . ',' . $g . ',' . $b . ',.35);'
            . '--adm-bg:' . $bg . ';'
            . '--adm-purple:' . $p . ';'
            . '--adm-cyan:' . $s . ';'
            . '--adm-grad:linear-gradient(135deg,' . $p . ',' . $s . ');'
            . '--adm-glow:0 10px 40px rgba(' . $r . ',' . $g . ',' . $b . ',.35);'
            . '}';
    }

    /** @param array<string, mixed> $st */
    public static function brandMarkHtml(array $st, string $fallbackEmoji = '🖥️', string $class = 'brand-logo'): string
    {
        $url = self::logoUrl($st);
        if ($url === '') {
            return $fallbackEmoji;
        }
        return '<img class="' . Html::esc($class) . '" src="' . Html::esc($url) . '" alt="">';
    }

    /**
     * @param array<string, mixed> $st
     * @return array{
     *   store_name: string,
     *   store_desc: string,
     *   currency: string,
     *   logo_url: string,
     *   telegram_link: string,
     *   colors: array{primary: string, secondary: string, bg: string}
     * }
     */
    public static function publicInfo(array $st, string $locale): array
    {
        $name = trim((string) ($st['store_name'] ?? ''));
        if ($name === '') {
            $name = $locale === 'ar' ? 'متجر VPS' : 'VPS Store';
        }
        return [
            'store_name' => $name,
            'store_desc' => \App\Views\StoreUi::descForLocale($st, $locale),
            'currency' => (string) ($st['currency'] ?? 'KWD'),
            'logo_url' => self::logoUrl($st),
            'telegram_link' => trim((string) ($st['telegram_link'] ?? '')),
            'colors' => [
                'primary' => self::primary($st),
                'secondary' => self::secondary($st),
                'bg' => self::bg($st),
            ],
        ];
    }

    public static function removeLogo(): void
    {
        $dir = self::brandDir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/logo.*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int} $file */
    public static function saveLogoUpload(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_LOGO_BYTES) {
            throw new \RuntimeException('Logo file too large (max 2MB)');
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array($ext, self::LOGO_EXT, true)) {
            throw new \RuntimeException('Logo must be PNG, JPG, WebP, GIF, or SVG');
        }
        $mime = (string) ($file['type'] ?? '');
        $okMime = [
            'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml',
        ];
        if ($mime !== '' && !in_array($mime, $okMime, true)) {
            throw new \RuntimeException('Invalid logo file type');
        }
        $dir = self::brandDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create brand folder');
        }
        self::removeLogo();
        $rel = 'brand/logo.' . $ext;
        $dest = dirname(__DIR__, 2) . '/public/' . $rel;
        if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $dest)) {
            throw new \RuntimeException('Could not save logo');
        }
        return $rel;
    }
}
