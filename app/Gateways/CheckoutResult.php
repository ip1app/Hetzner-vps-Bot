<?php
declare(strict_types=1);

namespace App\Gateways;

final class CheckoutResult
{
    public function __construct(
        public readonly string $gatewayRef,
        public readonly string $paymentUrl,
    ) {}
}
