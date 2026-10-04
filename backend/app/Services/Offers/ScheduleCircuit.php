<?php

namespace App\Services\Offers;

class ScheduleCircuit
{
    public function __construct(
        public readonly string $board,
        public readonly string $deviceType,
        public readonly int $poles,
        public readonly float $current,
        public readonly ?string $curve,
        public readonly string $rating,
        public readonly float $pageX = 0,
        public readonly float $pageY = 0,
    ) {}
}
