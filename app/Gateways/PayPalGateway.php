<?php
declare(strict_types=1);

namespace App\Gateways;

use App\Contracts\PaymentGateway;
use App\Controllers\StoreController;
use App\Database\Database;
use App\Request;
use App\Services\IntegrityGuard;
use App\Services\PayPal;

final class PayPalGateway implements PaymentGateway
{
    public function slug(): string
    {
        return 'paypal';
    }

    public function name(): string
    {
        return 'PayPal';
    }

    public function isConfigured(): bool
    {
        return PayPal::configured();
    }

    public function createCheckout(CheckoutRequest $checkout): CheckoutResult
    {
        IntegrityGuard::assertFeaturesEnabled();
        $returnUrl = PayPal::publicUrl($checkout->http, $checkout->returnPath);
        $cancelUrl = PayPal::publicUrl($checkout->http, $checkout->cancelPath);
        $order = PayPal::createOrder($checkout->amount, $checkout->description, $returnUrl, $cancelUrl);
        if ($order['approveUrl'] === '') {
            throw new \RuntimeException('PayPal did not return an approval URL');
        }
        return new CheckoutResult($order['id'], $order['approveUrl']);
    }

    public function completeReturn(Request $req): ?string
    {
        $token = trim((string) ($req->query['token'] ?? ''));
        return $token !== '' ? $token : null;
    }

    public function captureIfNeeded(string $gatewayRef): bool
    {
        $cap = PayPal::captureOrder($gatewayRef);
        return $cap['completed'];
    }

    public function findInvoice(string $gatewayRef): ?array
    {
        return Database::getInvoiceByPayPalOrder($gatewayRef);
    }

    public function handleWebhook(Request $req): array
    {
        if (!IntegrityGuard::featuresEnabled()) {
            return ['status' => 503, 'body' => 'integrity'];
        }
        $raw = file_get_contents('php://input') ?: '{}';
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            return ['status' => 400, 'body' => 'bad request'];
        }
        if (!PayPal::verifyWebhookSignature($req->headers, $event)) {
            return ['status' => 400, 'body' => 'invalid signature'];
        }
        $type = $event['event_type'] ?? '';
        $orderId = '';
        if ($type === 'PAYMENT.CAPTURE.COMPLETED') {
            $orderId = (string) ($event['resource']['supplementary_data']['related_ids']['order_id'] ?? '');
        } elseif ($type === 'CHECKOUT.ORDER.APPROVED') {
            $orderId = (string) ($event['resource']['id'] ?? '');
        } else {
            return ['status' => 200, 'body' => 'ignored'];
        }
        if ($orderId === '') {
            return ['status' => 200, 'body' => 'no order'];
        }
        $inv = $this->findInvoice($orderId);
        if (!$inv) {
            return ['status' => 200, 'body' => 'unknown'];
        }
        if (empty($inv['paid']) && $type === 'CHECKOUT.ORDER.APPROVED') {
            try {
                $this->captureIfNeeded($orderId);
            } catch (\Throwable) {
            }
        }
        StoreController::activateInvoice($inv['id']);
        return ['status' => 200, 'body' => 'ok'];
    }

    public function webhookPath(): string
    {
        return '/paypal/webhook';
    }

    public function explain(\Throwable $e): string
    {
        return PayPal::explain($e);
    }

    public function checkoutButtonKey(): string
    {
        return 'store.checkout.paypal';
    }

    public function paymentCurrency(): ?string
    {
        $cur = strtoupper((string) (Database::getSettings()['paypal_currency'] ?? 'USD'));
        return $cur !== '' ? $cur : null;
    }
}
