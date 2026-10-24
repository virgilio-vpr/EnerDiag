<?php

namespace App\Calculation\Data;

final readonly class DemandOptimizationResult
{
    /** @param array<string, DemandOptimizationSlotResult> $slots */
    public function __construct(public array $slots) {}
}
