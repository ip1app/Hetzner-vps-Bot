<?php
declare(strict_types=1);

namespace App\Helpers;

use App\Services\AdminPath;

final class I18n
{
    /** @var array<string, array<string, string>>|null */
    private static ?array $catalogs = null;

    /** @return array<string, array<string, string>> */
    private static function catalogs(): array
    {
        if (self::$catalogs === null) {
            $path = dirname(__DIR__) . '/i18n/translations.json';
            self::$catalogs = json_decode((string) file_get_contents($path), true) ?: ['en' => [], 'ar' => []];
        }
        return self::$catalogs;
    }

    public static function t(string $key, string $locale = 'en', array $vars = []): string
    {
        $loc = in_array($locale, ['en', 'ar'], true) ? $locale : 'en';
        $cat = self::catalogs();
        $text = $cat[$loc][$key] ?? $cat['en'][$key] ?? $key;
        foreach ($vars as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return self::resolveUrls($text);
    }

    /** Prefix internal href="/…" links with app base path (e.g. /z/admin when in a subfolder). */
    public static function resolveUrls(string $html): string
    {
        return preg_replace_callback(
            '~\bhref="(/(?!/)[^"]*)"~',
            static fn(array $m): string => 'href="' . Url::to($m[1]) . '"',
            $html
        ) ?? $html;
    }

    public static function htmlLang(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }

    public static function htmlDir(string $locale): string
    {
        return $locale === 'ar' ? 'rtl' : 'ltr';
    }

    public static function langLabel(string $locale): string
    {
        return $locale === 'ar' ? self::t('lang.ar', 'ar') : self::t('lang.en', 'en');
    }

    public static function langSwitcher(string $scope, string $current, string $returnPath, bool $allowSwitch = true): string
    {
        if (!$allowSwitch) {
            return '';
        }
        $base = Url::to($scope === 'admin' ? AdminPath::to('/set-lang') : '/set-lang');
        $ret = rawurlencode($returnPath ?: '/');
        $mk = function (string $code, string $label) use ($base, $ret, $current): string {
            $cls = $current === $code ? ' class="active"' : '';
            return '<a href="' . $base . '/' . $code . '?r=' . $ret . '"' . $cls . '>' . $label . '</a>';
        };
        return $mk('en', 'EN') . '<span class="lang-sep">|</span>' . $mk('ar', 'AR');
    }
}
