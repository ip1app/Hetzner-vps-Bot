<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Bot\BotTexts;
use App\Database\Database;
use App\Services\ActivityLog;
use App\Services\AdminNotify;
use App\Helpers\Html;
use App\Helpers\I18n;
use App\Helpers\Locale;
use App\Helpers\WebLayout;
use App\Views\StoreUi;
use App\Request;
use App\Response;
use App\Services\Config;
use App\Services\Csrf;
use App\Services\GatewayManager;
use App\Gateways\CheckoutRequest;
use App\Services\Installed;
use App\Services\IntegrityGuard;
use App\Services\Renewal;
use App\Services\Telegram;

final class StoreController
{
    /** @var array<string, true> */
    private static array $activating = [];

    private function locale(Request $req): string
    {
        return Locale::resolveStoreLocale($req);
    }

    private function guard(): void
    {
        Installed::requireInstalledOrExit();
    }

    public function index(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $st = Database::getSettings();
        $products = array_values(array_filter(Database::listProducts(), fn($p) => ($p['active'] ?? true) !== false));
        $body = StoreUi::homePage($loc, $st, $products);
        Response::html(WebLayout::layout(I18n::t('store.title.plans', $loc), $body, ['active' => 'store', 'locale' => $loc]));
    }

    public function checkout(Request $req, array $params): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $p = Database::getProduct($params['id'] ?? '');
        if (!$p || ($p['active'] ?? true) === false) {
            Response::redirect('/');
        }
        $st = Database::getSettings();
        $stock = count(Database::availableStockForProduct($p['id']));
        $gw = GatewayManager::active();
        $gwReady = $gw->isConfigured();
        $e = Html::esc(...);
        $cur = $st['currency'] ?? 'KWD';
        $gwCur = $gw->paymentCurrency();
        $months = (int) ($p['months'] ?? 1);
        $period = $months === 1 ? I18n::t('store.per_month', $loc) : I18n::t('store.per_months', $loc, ['n' => $months]);
        $prefillName = trim((string) ($req->query['name'] ?? ''));
        $prefillPhone = trim((string) ($req->query['phone'] ?? ''));
        $isRenew = ($req->query['renew'] ?? '') === '1';
        $renewClientId = trim((string) ($req->query['client_id'] ?? ''));
        if ($isRenew) {
            $renewClient = Renewal::resolveRenewClient($prefillPhone, (string) $p['id'], $renewClientId);
            if ($renewClient === null) {
                Response::redirect('/account?err=' . rawurlencode(I18n::t('account.renew.no_plan', $loc)));
            }
            if ($prefillName === '') {
                $prefillName = (string) ($renewClient['name'] ?? '');
            }
            if ($prefillPhone === '') {
                $prefillPhone = (string) ($renewClient['phone'] ?? '');
            }
            $stockLine = I18n::t('store.checkout.stock_renew', $loc);
        } elseif ($stock === 0) {
            Response::redirect('/?err=' . rawurlencode(I18n::t('err.stock_empty', $loc)));
        } else {
            $stockLine = I18n::t('store.checkout.stock_ok', $loc, ['n' => $stock]);
        }
        $renewBanner = $isRenew
            ? '<div class="ok" style="margin-bottom:14px">' . I18n::t('store.checkout.renew_banner', $loc) . '</div>'
            : '';
        $priceLine = $e((string) $p['price']) . ' ' . $e($cur);
        if ($gwCur !== null && $cur !== $gwCur && $gwReady) {
            $priceLine .= ' <span class="sub">(' . I18n::t('store.checkout.paypal_currency', $loc, ['code' => $gwCur]) . ')</span>';
        }
        $renewFields = $isRenew
            ? '<input type="hidden" name="renew" value="1">'
                . '<input type="hidden" name="client_id" value="' . $e($renewClientId !== '' ? $renewClientId : (string) ($renewClient['id'] ?? '')) . '">'
            : '';
        $err = isset($req->query['err']) ? '<div class="err" id="checkout-err">✖ ' . $e($req->query['err']) . '</div>' : '';
        $cancel = isset($req->query['cancel']) ? '<div class="warn">' . I18n::t('store.checkout.cancel', $loc) . '</div>' : '';
        $payBtn = $gwReady
            ? '<button type="submit" id="checkout-pay-btn" class="btn block green checkout-pay" style="margin-top:14px">' . I18n::t($gw->checkoutButtonKey(), $loc) . '</button>'
            : '<div class="warn" style="margin-top:14px">' . I18n::t('store.checkout.manual', $loc) . '</div>';
        $busyOverlay = $gwReady
            ? '<div id="checkout-busy" class="checkout-busy" hidden aria-live="polite">' . I18n::t('store.checkout.processing', $loc) . '</div>'
            : '';
        $checkoutUrl = \App\Helpers\Url::to('/checkout/' . $p['id']);
        $csrf = Csrf::publicTokenFromCookie($req->cookie(Csrf::PUBLIC_COOKIE));
        Response::cookie(Csrf::PUBLIC_COOKIE, $csrf, \App\Helpers\Url::cookiePath('/'), 3600);
        $formScript = $gwReady ? WebLayout::checkoutFormScript($loc) : '';
        $body = $err . $cancel . $renewBanner
            . '<div class="card checkout-card" style="max-width:560px;margin:0 auto">'
            . '<h1>🛒 ' . $e($p['name']) . '</h1>'
            . '<div class="kv"><b>' . I18n::t('store.checkout.price', $loc) . '</b><span>' . $priceLine . '</span></div>'
            . '<div class="kv"><b>' . I18n::t('store.checkout.duration', $loc) . '</b><span>' . $e($period) . '</span></div>'
            . StoreUi::productSpecsHtml((string) ($p['desc'] ?? ''), $loc, true, 'checkout-specs')
            . '<div class="kv"><b>' . I18n::t('store.checkout.stock', $loc) . '</b><span>' . $e($stockLine) . '</span></div>'
            . '<div id="checkout-field-err" class="err checkout-field-err" role="alert"></div>'
            . '<form id="checkout-form" method="post" action="' . $e($checkoutUrl) . '" style="margin-top:18px">'
            . '<input type="hidden" name="' . Csrf::FIELD . '" value="' . $e($csrf) . '">'
            . $renewFields
            . '<label>' . $e(I18n::t('store.checkout.name', $loc)) . '</label><input name="name" required autocomplete="name" value="' . $e($prefillName) . '">'
            . '<label>' . $e(I18n::t('store.checkout.phone', $loc)) . '</label><input name="phone" dir="ltr" required autocomplete="tel" inputmode="tel" value="' . $e($prefillPhone) . '">'
            . '<p class="sub">' . I18n::t('store.checkout.phone_hint', $loc) . '</p>'
            . $payBtn
            . '</form>' . $busyOverlay . '</div>' . $formScript;
        Response::html(WebLayout::layout(I18n::t('store.checkout.title', $loc), $body, ['active' => 'store', 'locale' => $loc, 'returnPath' => '/checkout/' . $p['id'], 'csrf' => $csrf]));
    }

    public function checkoutPost(Request $req, array $params): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $wantJson = $req->header('X-Checkout-Mode') === 'json';
        $submitted = (string) ($req->body[Csrf::FIELD] ?? '');
        if (!Csrf::verifyPublicRequest($req->cookie(Csrf::PUBLIC_COOKIE), $submitted)) {
            $pid = $params['id'] ?? '';
            $msg = I18n::t('err.csrf', $loc);
            if ($wantJson) {
                Response::json(['ok' => false, 'error' => $msg], 403);
            }
            Response::redirect('/checkout/' . $pid . '?err=' . rawurlencode($msg));
        }
        $p = Database::getProduct($params['id'] ?? '');
        if (!$p) {
            if ($wantJson) {
                Response::json(['ok' => false, 'error' => I18n::t('err.invalid_order', $loc)], 404);
            }
            Response::redirect('/');
        }
        $name = trim((string) ($req->body['name'] ?? ''));
        $phone = trim((string) ($req->body['phone'] ?? ''));
        $checkoutPath = \App\Helpers\Url::to('/checkout/' . $p['id']);
        $back = function (string $msg) use ($checkoutPath, $wantJson): void {
            if ($wantJson) {
                Response::json(['ok' => false, 'error' => $msg], 400);
            }
            Response::redirect($checkoutPath . '?err=' . rawurlencode($msg));
        };
        if ($name === '') {
            $back(I18n::t('err.name_required', $loc));
        }
        if (strlen(Database::normPhone($phone)) < 8) {
            $back(I18n::t('err.phone_invalid', $loc));
        }
        if (!GatewayManager::isActiveConfigured()) {
            $back(I18n::t('err.gateway_off', $loc));
        }
        $gw = GatewayManager::active();
        $isRenew = ($req->body['renew'] ?? '') === '1';
        $renewClientId = trim((string) ($req->body['client_id'] ?? ''));
        if ($isRenew) {
            $renewClient = Renewal::resolveRenewClient($phone, (string) $p['id'], $renewClientId);
            if ($renewClient === null) {
                $back(I18n::t('account.renew.no_plan', $loc));
            }
            $invoiceClientId = (string) $renewClient['id'];
            $orderLog = 'act.store_order_renew';
        } else {
            if (count(Database::availableStockForProduct($p['id'])) === 0) {
                $back(I18n::t('err.stock_empty', $loc));
            }
            $invoiceClientId = '';
            $orderLog = 'act.store_order_new';
        }
        try {
            $result = $gw->createCheckout(new CheckoutRequest(
                $req,
                (float) $p['price'],
                $p['name'] . ' — ' . $phone,
                '/success',
                '/checkout/' . $p['id'] . '?cancel=1',
            ));
            Database::addInvoice([
                'client_id' => $invoiceClientId,
                'desc' => $p['name'],
                'amount' => $p['price'],
                'months' => $p['months'],
                'source' => 'store',
                'buyer_name' => $name,
                'phone' => $phone,
                'product_id' => $p['id'],
                'paypal_order_id' => $result->gatewayRef,
            ]);
            ActivityLog::log('store', $orderLog, ['name' => $name, 'phone' => $phone, 'plan' => $p['name']]);
            if ($wantJson) {
                Response::json(['ok' => true, 'redirect' => $result->paymentUrl]);
            }
            Response::redirect($result->paymentUrl);
        } catch (\Throwable $e) {
            $back($gw->name() . ': ' . $gw->explain($e));
        }
    }

    public function success(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $gw = GatewayManager::active();
        $orderId = $gw->completeReturn($req);
        if ($orderId === null || $orderId === '') {
            $this->failSuccess($loc, I18n::t('err.invalid_order', $loc));
        }
        $inv = $gw->findInvoice($orderId);
        if (!$inv) {
            $this->failSuccess($loc, I18n::t('err.order_missing', $loc));
        }
        if (empty($inv['paid'])) {
            try {
                $completed = $gw->captureIfNeeded($orderId);
                if (!$completed && empty(Database::getInvoice($inv['id'])['paid'])) {
                    $this->failSuccess($loc, I18n::t('err.payment_incomplete', $loc));
                }
            } catch (\Throwable $e) {
                if (empty(Database::getInvoice($inv['id'])['paid'])) {
                    $this->failSuccess($loc, I18n::t('err.capture_failed', $loc) . ': ' . $gw->explain($e));
                }
            }
        }
        $r = self::activateInvoice($inv['id']);
        $inv = Database::getInvoice($inv['id']);
        $client = Database::getClient($inv['client_id'] ?? '');
        $this->renderSuccess($loc, $inv, $client, $r['isNew'] ?? false);
    }

    /** @return array<string, mixed>|null */
    public static function activateInvoice(string $invId): ?array
    {
        if (!IntegrityGuard::featuresEnabled()) {
            return null;
        }
        if (isset(self::$activating[$invId])) {
            return null;
        }
        self::$activating[$invId] = true;
        try {
            $inv = Database::getInvoice($invId);
            if (!$inv) {
                return null;
            }
            if (!empty($inv['paid'])) {
                return ['inv' => $inv, 'client' => Database::getClient($inv['client_id']), 'isNew' => false, 'already' => true];
            }
            $phone = (string) ($inv['phone'] ?? '');
            $productId = (string) ($inv['product_id'] ?? '');
            $renewClient = null;
            if (Renewal::isRenewalInvoice($inv)) {
                $candidate = Database::getClient((string) $inv['client_id']);
                if ($candidate !== null) {
                    $pid = Database::productIdForClient($candidate);
                    if ($productId === '' || $pid === null || $pid === $productId) {
                        $renewClient = $candidate;
                    }
                }
            }
            $isNew = false;
            if ($renewClient) {
                $fulfilled = Database::fulfillRenewalInvoice(
                    $inv['id'],
                    $renewClient['id'],
                    (int) ($inv['months'] ?? 1)
                );
                if (!empty($fulfilled['already'])) {
                    return [
                        'inv' => $fulfilled['inv'],
                        'client' => $fulfilled['client'],
                        'isNew' => false,
                        'already' => true,
                    ];
                }
                $client = $fulfilled['client'];
                $inv = $fulfilled['inv'];
                if ($phone !== '' && trim((string) ($client['telegram_id'] ?? '')) === '') {
                    $pendingTg = \App\Bot\BotStore::takeTelegramForPhone($phone);
                    if ($pendingTg !== '') {
                        Database::updateClient($client['id'], ['telegram_id' => $pendingTg]);
                        $client = Database::getClient($client['id']) ?? $client;
                    }
                }
            } else {
                $isNew = true;
                $base = new \DateTime();
                $base->modify('+' . (int) ($inv['months'] ?? 1) . ' months');
                $peer = $phone !== '' ? Database::getClientByPhone($phone) : null;
                $telegramId = $peer ? (string) ($peer['telegram_id'] ?? '') : '';
                if ($telegramId === '' && $phone !== '') {
                    $telegramId = \App\Bot\BotStore::takeTelegramForPhone($phone);
                }
                $fulfilled = Database::fulfillNewStoreInvoice($inv['id'], [
                    'name' => (string) ($inv['buyer_name'] ?? 'Store client'),
                    'phone' => $phone,
                    'telegram_id' => $telegramId,
                    'expires' => $base->format('Y-m-d'),
                ], $productId);
                if (!empty($fulfilled['already'])) {
                    return [
                        'inv' => $fulfilled['inv'],
                        'client' => $fulfilled['client'],
                        'isNew' => false,
                        'already' => true,
                    ];
                }
                $client = $fulfilled['client'];
                $inv = $fulfilled['inv'];
            }
            ActivityLog::log('payment', $isNew ? 'act.store_payment_new' : 'act.store_payment_renew', [
                'number' => $inv['number'],
                'name' => $client['name'],
                'expires' => $client['expires'],
            ]);
            if ($isNew) {
                AdminNotify::newClient($client, 'store', (string) ($inv['number'] ?? ''));
            }
            $tgClient = trim((string) ($client['telegram_id'] ?? ''));
            if ($tgClient !== '' && Config::get('TELEGRAM_BOT_TOKEN') !== '') {
                $loc = Locale::resolveBotLocale($tgClient, $client);
                $msg = BotTexts::text('payment_ok', $loc, [
                    'number' => $inv['number'] ?? '',
                    'expires' => $client['expires'] ?? '',
                ]);
                try {
                    Telegram::sendMessage($tgClient, $msg);
                } catch (\Throwable) {
                }
            }
            return ['inv' => Database::getInvoice($inv['id']), 'client' => $client, 'isNew' => $isNew, 'already' => false];
        } finally {
            unset(self::$activating[$invId]);
        }
    }

    public function subscription(Request $req, array $params = []): void
    {
        $this->guard();
        $loc = $this->locale($req);
        $phone = trim($req->query['phone'] ?? '');
        $result = '';
        if ($phone !== '') {
            $list = Database::listClientsByPhone($phone);
            if ($list === []) {
                $result = '<div class="err">✖ ' . I18n::t('sub.none', $loc) . '</div>';
            } else {
                $e = Html::esc(...);
                $result = '<div class="card sub-results">';
                foreach ($list as $c) {
                    $label = \App\Services\ClientAccounts::labelFor($c, $loc);
                    $renewUrl = Renewal::checkoutUrl($c);
                    $renewBtn = $renewUrl !== null
                        ? '<a class="btn sm" href="' . $e($renewUrl) . '">' . $e(I18n::t('sub.renew', $loc)) . '</a>'
                        : '';
                    $result .= '<div class="sub-result-row">'
                        . '<div class="sub-result-info">'
                        . '<b>' . $e($label) . '</b>'
                        . '<span class="mono">' . $e($c['expires'] ?? '') . '</span>'
                        . '</div>'
                        . $renewBtn
                        . '</div>';
                }
                $result .= '</div>';
            }
        }
        $body = '<div class="card" style="max-width:480px;margin:0 auto"><h1>🔍 ' . I18n::t('sub.h1', $loc) . '</h1>
<form method="get" action="' . \App\Helpers\Url::to('/subscription') . '"><label>' . I18n::t('sub.phone', $loc) . '</label><input name="phone" dir="ltr" required value="' . Html::esc($phone) . '">
<button class="btn block" style="margin-top:14px">' . I18n::t('sub.btn', $loc) . '</button></form>' . $result . '</div>';
        Response::html(WebLayout::layout(I18n::t('sub.title', $loc), $body, ['active' => 'sub', 'locale' => $loc]));
    }

    private function failSuccess(string $loc, string $msg): never
    {
        $body = '<div class="card"><div class="err">✖ ' . Html::esc($msg) . '</div><a class="btn" href="' . \App\Helpers\Url::to('/') . '">' . I18n::t('success.back_store', $loc) . '</a></div>';
        Response::html(WebLayout::layout(I18n::t('store.checkout.title', $loc), $body, ['locale' => $loc]));
    }

    /** @param array<string, mixed>|null $client */
    private function renderSuccess(string $loc, array $inv, ?array $client, bool $isNew): void
    {
        $st = Database::getSettings();
        $e = Html::esc(...);
        $body = '<div class="card" style="text-align:center"><div style="font-size:48px">🎉</div><h1>' . I18n::t('success.h1', $loc) . '</h1>
<div class="ok">' . ($isNew ? I18n::t('success.new', $loc) : I18n::t('success.renew', $loc)) . '</div>
<a class="btn block green" href="' . \App\Helpers\Url::to('/') . '">' . I18n::t('success.back_store', $loc) . '</a></div>';
        Response::html(WebLayout::layout(I18n::t('success.title', $loc), $body, ['locale' => $loc]));
    }
}
