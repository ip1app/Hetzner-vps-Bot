<?php
declare(strict_types=1);

namespace App\Contracts;

use App\Gateways\CheckoutRequest;
use App\Gateways\CheckoutResult;
use App\Request;

interface PaymentGateway
{
    public function slug(): string;

    public function name(): string;

    public function isConfigured(): bool;

    public function createCheckout(CheckoutRequest $checkout): CheckoutResult;

    /** Gateway reference from return URL query (e.g. PayPal order token). */
    public function completeReturn(Request $req): ?string;

    public function captureIfNeeded(string $gatewayRef): bool;

    /** @return array<string, mixed>|null */
    public function findInvoice(string $gatewayRef): ?array;

    /** @return array{status: int, body: string} */
    public function handleWebhook(Request $req): array;

    public function webhookPath(): string;

    public function explain(\Throwable $e): string;

    /** i18n key for checkout button label. */
    public function checkoutButtonKey(): string;

    /** Payment currency shown at checkout (null if same as store). */
    public function paymentCurrency(): ?string;
}
