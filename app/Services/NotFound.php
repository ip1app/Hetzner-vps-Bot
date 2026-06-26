<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Helpers\Url;
use App\Helpers\WebLayout;
use App\Request;
use App\Response;
use App\Services\BrandTheme;
use App\Services\Csrf;
use App\Services\IntegrityGuard;
use App\Views\AdminUi;
use App\Views\ErrorUi;

final class NotFound
{
    public static function store(?Request $req = null): never
    {
        $req ??= Request::capture();
        $loc = Locale::resolveStoreLocale($req);
        $path = self::requestedPath();
        $title = I18n::t('err404.title', $loc);
        $body = ErrorUi::wrap(ErrorUi::block($loc, $path, 'store'));
        $html = WebLayout::layout($title, $body, [
            'locale' => $loc,
            'returnPath' => Url::strip($path),
        ]);
        Response::html($html, 404);
    }

    public static function admin(string $loc, ?Request $req = null): never
    {
        $req ??= Request::capture();
        $path = self::requestedPath();
        $st = Database::getSettings();
        $body = ErrorUi::wrap(ErrorUi::block($loc, $path, 'admin'));
        $opts = [
            'locale' => $loc,
            'title' => I18n::t('err404.title', $loc),
            'body' => $body,
            'active' => '',
            'storeName' => (string) ($st['store_name'] ?? ''),
            'brandCss' => BrandTheme::cssOverrides($st),
            'brandBg' => BrandTheme::bg($st),
            'brandLogoUrl' => BrandTheme::logoUrl($st),
            'csrf' => Csrf::token($req->cookie('q8admin')),
            'header' => AdminUi::installSecurityBanner($loc) . IntegrityGuard::adminBannerHtml($loc),
        ];
        Response::html(AdminUi::page($opts), 404);
    }

    private static function requestedPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return parse_url($uri, PHP_URL_PATH) ?: '/';
    }
}
