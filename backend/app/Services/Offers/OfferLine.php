<?php

namespace App\Services\Offers;

class OfferLine
{
    public function __construct(
        public readonly string $catalogNumber,
        public readonly string $name,
        public readonly int $quantity,
        public readonly ?float $unitPrice,
    ) {}
}
