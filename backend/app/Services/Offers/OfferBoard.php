<?php

namespace App\Services\Offers;

class OfferBoard
{
    /**
     * @param  list<OfferLine>  $lines
     */
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly array $lines,
    ) {}
}
