<?php

namespace App\Calculation\Data;

final readonly class DemandCostResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?string $total,
        public ?string $condition,
        public ?string $measuredDemandCharge,
        public ?string $idleDemandCharge,
        public ?string $contractedDemandCharge,
        public ?string $overrunCharge,
        public array $warnings = [],
    ) {}
}
