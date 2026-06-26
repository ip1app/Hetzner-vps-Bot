<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\Html;
use App\Helpers\I18n;

/** Developer credit — iP1 / ip1.app (not configurable). */
final class DevCredit
{
    public static function author(): string
    {
        return Installed::DEFAULT_AUTHOR;
    }

    public static function siteUrl(): string
    {
        return Installed::DEFAULT_SITE_URL;
    }

    public static function siteLabel(): string
    {
        $host = parse_url(self::siteUrl(), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'ip1.app';
    }

    public static function inlineHtml(string $locale, string $class = 'foot-credit'): string
    {
        $e = Html::esc(...);
        $credit = I18n::t('admin.footer.credit', $locale, ['author' => self::author()]);
        return '<div class="' . $e($class) . '">' . $e($credit)
            . ' · <a href="' . $e(self::siteUrl()) . '" target="_blank" rel="noopener">'
            . $e(self::siteLabel()) . '</a></div>';
    }

    public static function adminFooterHtml(string $locale): string
    {
        $e = Html::esc(...);
        $ver = Installed::VERSION;
        return '<div class="sidebar-foot login-foot">'
            . '<div class="sidebar-ver sidebar-foot-txt">' . $e(I18n::t('admin.footer.version', $locale, ['v' => $ver])) . '</div>'
            . '<div class="sidebar-foot-txt">' . $e(I18n::t('admin.footer.credit', $locale, ['author' => self::author()])) . '</div>'
            . '<div class="sidebar-foot-txt"><a href="' . $e(self::siteUrl()) . '" target="_blank" rel="noopener">'
            . $e(self::siteLabel()) . '</a></div>'
            . '</div>';
    }

    /** @param callable(string, array<string, string>): string $translate */
    public static function installFooterHtml(string $locale, callable $translate): string
    {
        $e = Html::esc(...);
        return '<div class="install-foot">'
            . '<p>' . $e($translate('footer_credit', ['author' => self::author()])) . '</p>'
            . '<p><a href="' . $e(self::siteUrl()) . '" target="_blank" rel="noopener">' . $e(self::siteLabel()) . '</a></p>'
            . '</div>';
    }
}
