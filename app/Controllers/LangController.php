<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Helpers\WebLayout;
use App\Request;
use App\Response;
use App\Services\AdminPath;
use App\Services\Installed;

final class LangController
{
    public function store(Request $req, array $params): void
    {
        $code = Locale::norm($params['code'] ?? 'en');
        $s = Locale::storeSettings();
        if (!$s['allow_locale_switch'] && $code !== $s['store_locale']) {
            Response::redirect($req->query['r'] ?? '/');
        }
        Locale::setStoreLocaleCookie($code);
        $back = $req->query['r'] ?? '/';
        if (!str_starts_with($back, '/')) {
            $back = '/';
        }
        Response::redirect($back);
    }

    public function admin(Request $req, array $params): void
    {
        Locale::setAdminLocaleCookie(Locale::norm($params['code'] ?? 'en'));
        $back = $req->query['r'] ?? AdminPath::to();
        $prefix = AdminPath::prefix();
        if (!str_starts_with($back, $prefix)) {
            $back = AdminPath::to();
        }
        Response::redirect($back);
    }
}
