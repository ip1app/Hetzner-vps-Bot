<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Api\ApiResponse;
use App\Bot\BotTexts;
use App\Database\Database;
use App\Helpers\Datacenter;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Request;
use App\Services\ActivityLog;
use App\Services\ApiJwt;
use App\Services\BrandTheme;
use App\Services\ClientAuth;
use App\Services\GatewayManager;
use App\Gateways\CheckoutRequest;
use App\Services\ProviderManager;
use App\Services\Installed;
use App\Services\Renewal;
use App\Views\StoreUi;

final class ApiController
{
    private function guardInstalled(): void
    {
        if (!Installed::isInstalled()) {
            ApiResponse::err('NOT_INSTALLED', 'Platform is not installed.', 503);
        }
        if (!ApiJwt::isConfigured()) {
            ApiResponse::err('ERR_MISCONFIGURED', 'JWT_SECRET is not configured.', 503);
        }
    }

    private function locale(Request $req): string
    {
        $h = $req->header('Accept-Language');
        if ($h !== '') {
            $part = trim(explode(',', $h)[0]);
            if ($part !== '') {
                return Locale::norm($part);
            }
        }
        return Locale::storeSettings()['store_locale'];
    }

    /** @return array<string, mixed> */
    private function json(Request $req): array
    {
        return $req->jsonBody();
    }

    /** @return array<string, mixed> */
    private function requireClient(Request $req): array
    {
        $client = ClientAuth::clientFromRequest($req);
        if (!$client) {
            ApiResponse::err('ERR_UNAUTHORIZED', I18n::t('api.err.unauthorized', $this->locale($req)), 401);
        }
        return $client;
    }

    /** @param array<string, mixed> $client */
    private function requireActiveServerClient(Request $req, array $client): array
    {
        $loc = $this->locale($req);
        if (Database::isExpired($client) || ($client['active'] ?? true) === false) {
            ApiResponse::err('ERR_SERVER_EXPIRED', I18n::t('account.server.expired', $loc), 403);
        }
        if ((int) ($client['server_id'] ?? 0) <= 0) {
            ApiResponse::err('ERR_NO_SERVER', I18n::t('account.server.no_server', $loc), 403);
        }
        return $client;
    }

    public function health(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $st = Database::getSettings();
        $gw = GatewayManager::active();
        $sp = ProviderManager::active();
        ApiResponse::ok([
            'status' => 'ok',
            'installed' => true,
            'gateway' => $gw->slug(),
            'gateway_configured' => $gw->isConfigured(),
            'server_provider' => $sp->slug(),
            'server_provider_configured' => $sp->isConfigured(),
            'currency' => (string) ($st['currency'] ?? 'KWD'),
            'locale' => (string) ($st['store_locale'] ?? 'en'),
        ], 200, ['locale' => $this->locale($req)]);
    }

    public function storeInfo(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $st = Database::getSettings();
        ApiResponse::ok(BrandTheme::publicInfo($st, $loc), 200, [
            'locale' => $loc,
            'currency' => (string) ($st['currency'] ?? 'KWD'),
        ]);
    }

    public function otpRequest(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $body = $this->json($req);
        $phone = trim((string) ($body['phone'] ?? ''));
        if (strlen(Database::normPhone($phone)) < 8) {
            ApiResponse::err('ERR_VALIDATION', I18n::t('err.phone_invalid', $loc));
        }
        $result = ClientAuth::requestOtp($phone, $req->ip);
        if (!empty($result['blocked'])) {
            ApiResponse::err(
                'ERR_RATE_LIMIT',
                I18n::t('account.err.otp_rate', $loc, ['min' => (string) max(1, (int) ($result['minutes'] ?? 30))]),
                429
            );
        }
        if (!empty($result['error'])) {
            $map = [
                'no_sub' => ['ERR_NO_SUBSCRIPTION', 'account.err.no_sub'],
                'no_telegram' => ['ERR_NO_TELEGRAM', 'account.err.no_telegram'],
                'bot_offline' => ['ERR_BOT_OFFLINE', 'account.err.bot_offline'],
            ];
            [$code, $key] = $map[$result['error']] ?? ['ERR_UNKNOWN', 'api.err.unknown'];
            ApiResponse::err($code, I18n::t($key, $loc), $code === 'ERR_NO_SUBSCRIPTION' ? 404 : 503);
        }
        $data = ['expires_in' => 300, 'phone' => Database::normPhone($phone)];
        if (!empty($result['warn'])) {
            $data['warn'] = I18n::t('account.warn.otp_left', $loc, ['n' => (string) ($result['left'] ?? 0)]);
        }
        ApiResponse::ok($data, 200, ['locale' => $loc]);
    }

    public function otpVerify(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $body = $this->json($req);
        $phone = trim((string) ($body['phone'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        if ($phone === '' || $code === '') {
            ApiResponse::err('ERR_VALIDATION', I18n::t('api.err.missing_fields', $loc));
        }
        $result = ClientAuth::verifyOtp($phone, $code, $req->ip);
        if (!empty($result['blocked'])) {
            ApiResponse::err(
                'ERR_RATE_LIMIT',
                I18n::t('account.err.verify_rate', $loc, ['min' => (string) max(1, (int) ($result['minutes'] ?? 30))]),
                429
            );
        }
        if (empty($result['ok']) || empty($result['client'])) {
            ApiResponse::err('ERR_OTP_INVALID', I18n::t('account.err.invalid_code', $loc), 401);
        }
        $tokens = ApiJwt::issuePair((string) $result['client']['id'], true);
        ApiResponse::ok(array_merge($tokens, [
            'client' => $this->clientProfile($result['client'], $loc),
        ]), 200, ['locale' => $loc]);
    }

    public function refresh(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $body = $this->json($req);
        $refresh = trim((string) ($body['refresh_token'] ?? ''));
        if ($refresh === '') {
            ApiResponse::err('ERR_VALIDATION', I18n::t('api.err.missing_refresh', $loc));
        }
        $rotated = ApiJwt::rotateRefresh($refresh);
        if ($rotated === null) {
            ApiResponse::err('ERR_UNAUTHORIZED', I18n::t('api.err.unauthorized', $loc), 401);
        }
        ApiResponse::ok($rotated, 200, ['locale' => $loc]);
    }

    public function profile(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        ApiResponse::ok($this->clientProfile($client, $loc), 200, [
            'locale' => $loc,
            'currency' => (string) (Database::getSettings()['currency'] ?? 'KWD'),
        ]);
    }

    public function subscription(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        ApiResponse::ok($this->subscriptionData($client), 200, ['locale' => $loc]);
    }

    public function summary(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        $cur = (string) (Database::getSettings()['currency'] ?? 'KWD');
        $server = null;
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid > 0) {
            try {
                $sp = ProviderManager::forClient($client);
                $raw = $sp->getServer($sid, ProviderManager::accountIdForClient($client));
                $server = $this->formatServer($raw, $loc);
            } catch (\Throwable) {
                $server = null;
            }
        }
        ApiResponse::ok([
            'profile' => $this->clientProfile($client, $loc),
            'subscription' => $this->subscriptionData($client),
            'server' => $server,
            'has_telegram' => trim((string) ($client['telegram_id'] ?? '')) !== '',
            'currency' => $cur,
        ], 200, ['locale' => $loc, 'currency' => $cur]);
    }

    public function server(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        $sid = (int) ($client['server_id'] ?? 0);
        if ($sid <= 0) {
            ApiResponse::ok(['server' => null], 200, ['locale' => $loc]);
        }
        try {
            $sp = ProviderManager::forClient($client);
            $raw = $sp->getServer($sid, ProviderManager::accountIdForClient($client));
            ApiResponse::ok(['server' => $this->formatServer($raw, $loc)], 200, ['locale' => $loc]);
        } catch (\Throwable $e) {
            ApiResponse::ok(['server' => null, 'error' => ProviderManager::forClient($client)->explain($e)], 200, ['locale' => $loc]);
        }
    }

    public function serverAction(Request $req, array $params): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireActiveServerClient($req, $this->requireClient($req));
        $action = (string) ($params['action'] ?? '');
        $sid = (int) ($client['server_id'] ?? 0);
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);
        try {
            if ($action === 'pass') {
                $pw = $sp->resetPassword($sid, $acc);
                ApiResponse::ok(['password' => $pw], 200, ['locale' => $loc]);
            }
            match ($action) {
                'on' => $sp->powerOn($sid, $acc),
                'off' => $sp->powerOff($sid, $acc),
                'reboot' => $sp->reboot($sid, $acc),
                default => ApiResponse::err('ERR_NOT_FOUND', I18n::t('api.err.unknown_action', $loc), 404),
            };
            ApiResponse::ok(['action' => $action, 'status' => 'ok'], 200, ['locale' => $loc]);
        } catch (\Throwable $e) {
            ApiResponse::err('ERR_SERVER_ACTION', $sp->explain($e), 502);
        }
    }

    public function serverImages(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireActiveServerClient($req, $this->requireClient($req));
        try {
            $images = ProviderManager::forClient($client)->listImages(ProviderManager::accountIdForClient($client));
            $allowed = BotTexts::allowedOsImages();
            $out = [];
            foreach ($images as $img) {
                $iid = (string) ($img['id'] ?? '');
                $name = (string) ($img['name'] ?? '');
                if ($allowed !== [] && !in_array($name, $allowed, true) && !in_array($iid, $allowed, true)) {
                    continue;
                }
                $out[] = [
                    'id' => (int) ($img['id'] ?? 0),
                    'name' => $name,
                    'description' => (string) ($img['description'] ?? ''),
                ];
            }
            ApiResponse::ok(['images' => $out], 200, ['locale' => $loc]);
        } catch (\Throwable $e) {
            ApiResponse::err('ERR_SERVER_PROVIDER', $sp->explain($e), 502);
        }
    }

    public function serverRebuild(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireActiveServerClient($req, $this->requireClient($req));
        $body = $this->json($req);
        $imageId = (int) ($body['image_id'] ?? 0);
        $image = $this->findAllowedImage($client, $imageId);
        if ($image === null) {
            ApiResponse::err('ERR_VALIDATION', I18n::t('account.rebuild.invalid_image', $loc));
        }
        $sid = (int) ($client['server_id'] ?? 0);
        $acc = ProviderManager::accountIdForClient($client);
        $sp = ProviderManager::forClient($client);
        try {
            $pw = $sp->rebuild($sid, $imageId, $acc);
            ActivityLog::log('server', 'act.api_rebuild', ['name' => $client['name'] ?? '']);
            ApiResponse::ok([
                'password' => $pw,
                'image' => (string) ($image['name'] ?? ''),
            ], 200, ['locale' => $loc]);
        } catch (\Throwable $e) {
            ApiResponse::err('ERR_SERVER_PROVIDER', $sp->explain($e), 502);
        }
    }

    public function invoices(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        $cur = (string) (Database::getSettings()['currency'] ?? 'KWD');
        $list = [];
        foreach (Database::listInvoicesByClient($client['id']) as $inv) {
            $list[] = $this->formatInvoice($inv, $cur);
        }
        ApiResponse::ok(['invoices' => $list, 'currency' => $cur], 200, ['locale' => $loc]);
    }

    public function invoice(Request $req, array $params): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        $inv = Database::getInvoice($params['id'] ?? '');
        if (!$inv || (string) ($inv['client_id'] ?? '') !== (string) ($client['id'] ?? '')) {
            ApiResponse::err('ERR_NOT_FOUND', I18n::t('api.err.invoice_not_found', $loc), 404);
        }
        $cur = (string) (Database::getSettings()['currency'] ?? 'KWD');
        ApiResponse::ok($this->formatInvoice($inv, $cur), 200, ['locale' => $loc, 'currency' => $cur]);
    }

    public function accountCheckout(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $client = $this->requireClient($req);
        $body = $this->json($req);
        $pid = trim((string) ($body['product_id'] ?? ''));
        if ($pid === '') {
            $pid = Renewal::productIdForClient($client) ?? '';
        }
        if ($pid === '') {
            ApiResponse::err('ERR_NO_RENEWAL_PRODUCT', I18n::t('api.err.no_renewal_product', $loc), 404);
        }
        $p = Database::getProduct($pid);
        if (!$p || ($p['active'] ?? true) === false) {
            ApiResponse::err('ERR_NOT_FOUND', I18n::t('api.err.product_not_found', $loc), 404);
        }
        if (!GatewayManager::isActiveConfigured()) {
            ApiResponse::err('ERR_GATEWAY_OFF', I18n::t('err.gateway_off', $loc), 503);
        }
        $name = (string) ($client['name'] ?? '');
        $phone = (string) ($client['phone'] ?? '');
        $gw = GatewayManager::active();
        try {
            $result = $gw->createCheckout(new CheckoutRequest(
                $req,
                (float) $p['price'],
                $p['name'] . ' — ' . $phone,
                '/success',
                '/checkout/' . $p['id'] . '?cancel=1',
            ));
            $inv = Database::addInvoice([
                'client_id' => $client['id'],
                'desc' => $p['name'],
                'amount' => $p['price'],
                'months' => $p['months'],
                'source' => 'store',
                'buyer_name' => $name,
                'phone' => $phone,
                'product_id' => $p['id'],
                'paypal_order_id' => $result->gatewayRef,
            ]);
            ActivityLog::log('store', 'act.store_order_renew', [
                'name' => $name,
                'phone' => $phone,
                'plan' => $p['name'],
            ]);
            ApiResponse::ok([
                'invoice_id' => $inv['id'],
                'invoice_number' => $inv['number'],
                'payment_url' => $result->paymentUrl,
                'gateway' => $gw->slug(),
                'product_id' => $p['id'],
                'is_renewal' => true,
            ], 200, ['locale' => $loc]);
        } catch (\Throwable $e) {
            ApiResponse::err('ERR_GATEWAY', $gw->name() . ': ' . $gw->explain($e), 502);
        }
    }

    public function storeFaq(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $st = Database::getSettings();
        $enabled = !empty($st['show_faq_home']);
        $items = $enabled ? StoreUi::parseFaq(StoreUi::faqRawForLocale($st, $loc)) : [];
        ApiResponse::ok([
            'enabled' => $enabled,
            'items' => $items,
        ], 200, ['locale' => $loc]);
    }

    public function products(Request $req, array $params = []): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $st = Database::getSettings();
        $cur = (string) ($st['currency'] ?? 'KWD');
        $out = [];
        foreach (Database::listProducts() as $p) {
            if (($p['active'] ?? true) === false) {
                continue;
            }
            $out[] = $this->formatProduct($p, $cur);
        }
        ApiResponse::ok(['products' => $out, 'currency' => $cur], 200, ['locale' => $loc]);
    }

    public function product(Request $req, array $params): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $p = Database::getProduct($params['id'] ?? '');
        if (!$p || ($p['active'] ?? true) === false) {
            ApiResponse::err('ERR_NOT_FOUND', I18n::t('api.err.product_not_found', $loc), 404);
        }
        $cur = (string) (Database::getSettings()['currency'] ?? 'KWD');
        ApiResponse::ok($this->formatProduct($p, $cur), 200, ['locale' => $loc, 'currency' => $cur]);
    }

    public function checkout(Request $req, array $params): void
    {
        $this->guardInstalled();
        $loc = $this->locale($req);
        $p = Database::getProduct($params['id'] ?? '');
        if (!$p || ($p['active'] ?? true) === false) {
            ApiResponse::err('ERR_NOT_FOUND', I18n::t('api.err.product_not_found', $loc), 404);
        }
        $body = $this->json($req);
        $name = trim((string) ($body['name'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));
        if ($name === '') {
            ApiResponse::err('ERR_VALIDATION', I18n::t('err.name_required', $loc));
        }
        if (strlen(Database::normPhone($phone)) < 8) {
            ApiResponse::err('ERR_VALIDATION', I18n::t('err.phone_invalid', $loc));
        }
        if (!GatewayManager::isActiveConfigured()) {
            ApiResponse::err('ERR_GATEWAY_OFF', I18n::t('err.gateway_off', $loc), 503);
        }
        $gw = GatewayManager::active();
        if (count(Database::availableStockForProduct($p['id'])) === 0) {
            ApiResponse::err('ERR_STOCK_EMPTY', I18n::t('err.stock_empty', $loc), 409);
        }
        try {
            $result = $gw->createCheckout(new CheckoutRequest(
                $req,
                (float) $p['price'],
                $p['name'] . ' — ' . $phone,
                '/success',
                '/checkout/' . $p['id'] . '?cancel=1',
            ));
            $inv = Database::addInvoice([
                'client_id' => '',
                'desc' => $p['name'],
                'amount' => $p['price'],
                'months' => $p['months'],
                'source' => 'store',
                'buyer_name' => $name,
                'phone' => $phone,
                'product_id' => $p['id'],
                'paypal_order_id' => $result->gatewayRef,
            ]);
            ActivityLog::log('store', 'act.store_order_new', [
                'name' => $name,
                'phone' => $phone,
                'plan' => $p['name'],
            ]);
            ApiResponse::ok([
                'invoice_id' => $inv['id'],
                'invoice_number' => $inv['number'],
                'payment_url' => $result->paymentUrl,
                'gateway' => $gw->slug(),
                'is_renewal' => false,
            ], 200, ['locale' => $loc]);
        } catch (\Throwable $e) {
            ApiResponse::err('ERR_GATEWAY', $gw->name() . ': ' . $gw->explain($e), 502);
        }
    }

    /** @param array<string, mixed> $client */
    private function subscriptionData(array $client): array
    {
        return [
            'expires' => (string) ($client['expires'] ?? ''),
            'days_left' => Database::daysLeft($client),
            'active' => !Database::isExpired($client) && ($client['active'] ?? true) !== false,
            'suspended' => ($client['active'] ?? true) === false,
            'renew_checkout_url' => Renewal::absoluteCheckoutUrl($client) ?? Renewal::checkoutUrl($client),
            'renew_product_id' => Renewal::productIdForClient($client),
        ];
    }

    /** @param array<string, mixed> $client */
    private function clientProfile(array $client, string $loc): array
    {
        return [
            'id' => (string) ($client['id'] ?? ''),
            'name' => (string) ($client['name'] ?? ''),
            'phone' => (string) ($client['phone'] ?? ''),
            'expires' => (string) ($client['expires'] ?? ''),
            'days_left' => Database::daysLeft($client),
            'active' => !Database::isExpired($client) && ($client['active'] ?? true) !== false,
            'has_server' => (int) ($client['server_id'] ?? 0) > 0,
            'server_id' => (int) ($client['server_id'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $server */
    private function formatServer(array $server, string $loc): array
    {
        return [
            'id' => (int) ($server['id'] ?? 0),
            'name' => (string) ($server['name'] ?? ''),
            'status' => (string) ($server['status'] ?? ''),
            'ipv4' => (string) ($server['public_net']['ipv4']['ip'] ?? ''),
            'ipv6' => (string) ($server['public_net']['ipv6']['ip'] ?? ''),
            'type' => (string) ($server['server_type']['name'] ?? ''),
            'datacenter' => Datacenter::countryLabel($server, $loc),
            'os' => (string) ($server['image']['description'] ?? $server['image']['name'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $inv */
    private function formatInvoice(array $inv, string $currency): array
    {
        return [
            'id' => (string) ($inv['id'] ?? ''),
            'number' => (string) ($inv['number'] ?? ''),
            'description' => (string) ($inv['desc'] ?? ''),
            'amount' => (float) ($inv['amount'] ?? 0),
            'currency' => $currency,
            'months' => (int) ($inv['months'] ?? 0),
            'paid' => !empty($inv['paid']),
            'paid_at' => (string) ($inv['paid_at'] ?? ''),
            'created_at' => (string) ($inv['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $p */
    private function formatProduct(array $p, string $currency): array
    {
        return [
            'id' => (string) ($p['id'] ?? ''),
            'name' => (string) ($p['name'] ?? ''),
            'price' => (float) ($p['price'] ?? 0),
            'currency' => $currency,
            'months' => (int) ($p['months'] ?? 1),
            'specs' => (string) ($p['specs'] ?? ''),
            'stock' => count(Database::availableStockForProduct($p['id'])),
        ];
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>|null
     */
    private function findAllowedImage(array $client, int $imageId): ?array
    {
        if ($imageId <= 0) {
            return null;
        }
        try {
            $images = ProviderManager::forClient($client)->listImages(ProviderManager::accountIdForClient($client));
        } catch (\Throwable) {
            return null;
        }
        $allowed = BotTexts::allowedOsImages();
        foreach ($images as $img) {
            if ((int) ($img['id'] ?? 0) !== $imageId) {
                continue;
            }
            $name = (string) ($img['name'] ?? '');
            $iid = (string) ($img['id'] ?? '');
            if ($allowed !== [] && !in_array($name, $allowed, true) && !in_array($iid, $allowed, true)) {
                return null;
            }
            return $img;
        }
        return null;
    }
}
