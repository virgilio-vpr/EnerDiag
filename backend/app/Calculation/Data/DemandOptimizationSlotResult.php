<?php

namespace App\Calculation\Data;

final readonly class DemandOptimizationSlotResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?string $optimalDemand,
        public ?string $totalCost,
        public array $warnings = [],
    ) {}
}
