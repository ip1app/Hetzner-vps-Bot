<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Helpers\Url;
use App\Router;
use App\Controllers\StoreController;
use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\WebhookController;
use App\Controllers\LangController;
use App\Controllers\ApiController;
use App\Database\Database;
use App\Response;
use App\Services\AdminPath;
use App\Services\Installed;

$path = Url::strip(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

Installed::ensureLegacyLock();

if (!Installed::isInstalled()) {
    ini_set('display_errors', '0');
    if (!Installed::isSetupPath($path) && !str_starts_with($path, '/api/')) {
        Installed::respondUnavailable();
    }
}

if (Installed::isInstalled()) {
    Database::init();
    if (!AdminPath::usesLegacyDefault() && AdminPath::isLegacyRequest($path)) {
        Response::redirect(AdminPath::fromLegacy($path), 301);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' && str_starts_with($path, '/api/')) {
    \App\Api\ApiResponse::preflight();
}

$router = new Router();
$ap = AdminPath::prefix();

$router->get('/set-lang/{code}', [LangController::class, 'store']);

$router->get('/api/v1/health', [ApiController::class, 'health']);
$router->get('/api/v1/store/info', [ApiController::class, 'storeInfo']);
$router->post('/api/v1/auth/otp/request', [ApiController::class, 'otpRequest']);
$router->post('/api/v1/auth/otp/verify', [ApiController::class, 'otpVerify']);
$router->post('/api/v1/auth/refresh', [ApiController::class, 'refresh']);
$router->get('/api/v1/account/profile', [ApiController::class, 'profile']);
$router->get('/api/v1/account/summary', [ApiController::class, 'summary']);
$router->get('/api/v1/account/subscription', [ApiController::class, 'subscription']);
$router->get('/api/v1/account/server', [ApiController::class, 'server']);
$router->post('/api/v1/account/server/rebuild', [ApiController::class, 'serverRebuild']);
$router->get('/api/v1/account/server/images', [ApiController::class, 'serverImages']);
$router->post('/api/v1/account/server/{action}', [ApiController::class, 'serverAction']);
$router->get('/api/v1/account/invoices', [ApiController::class, 'invoices']);
$router->get('/api/v1/account/invoices/{id}', [ApiController::class, 'invoice']);
$router->post('/api/v1/account/checkout', [ApiController::class, 'accountCheckout']);
$router->get('/api/v1/store/products', [ApiController::class, 'products']);
$router->get('/api/v1/store/faq', [ApiController::class, 'storeFaq']);
$router->get('/api/v1/store/products/{id}', [ApiController::class, 'product']);
$router->post('/api/v1/store/checkout/{id}', [ApiController::class, 'checkout']);

$router->get($ap . '/set-lang/{code}', [LangController::class, 'admin']);
$router->get($ap . '/login', [AdminController::class, 'loginForm']);
$router->post($ap . '/login', [AdminController::class, 'login']);
$router->get($ap . '/login/2fa', [AdminController::class, 'login2faForm']);
$router->post($ap . '/login/2fa', [AdminController::class, 'login2fa']);
$router->get($ap . '/logout', [AdminController::class, 'logout']);
$router->get($ap, [AdminController::class, 'dashboard']);
$router->get($ap . '/{path}', [AdminController::class, 'dispatch']);
$router->post($ap . '/{path}', [AdminController::class, 'dispatchPost']);

$router->get('/account/login', [AccountController::class, 'loginForm']);
$router->post('/account/login', [AccountController::class, 'login']);
$router->get('/account/verify', [AccountController::class, 'verifyForm']);
$router->post('/account/verify', [AccountController::class, 'verify']);
$router->get('/account/logout', [AccountController::class, 'logout']);
$router->get('/account', [AccountController::class, 'home']);
$router->get('/account/{path}', [AccountController::class, 'dispatch']);
$router->post('/account/{path}', [AccountController::class, 'dispatchPost']);

$router->get('/success', [StoreController::class, 'success']);
$router->post('/paypal/webhook', [WebhookController::class, 'paypal']);
$router->post('/tg/{secret}', [WebhookController::class, 'telegram']);
$router->get('/checkout/{id}', [StoreController::class, 'checkout']);
$router->post('/checkout/{id}', [StoreController::class, 'checkoutPost']);
$router->get('/subscription', [StoreController::class, 'subscription']);
$router->get('/', [StoreController::class, 'index']);

$router->dispatch();
