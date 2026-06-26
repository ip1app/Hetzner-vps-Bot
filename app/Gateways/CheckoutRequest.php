<?php
declare(strict_types=1);

namespace App\Gateways;

use App\Request;

final class CheckoutRequest
{
    public function __construct(
        public readonly Request $http,
        public readonly float $amount,
        public readonly string $description,
        public readonly string $returnPath,
        public readonly string $cancelPath,
    ) {}
}
