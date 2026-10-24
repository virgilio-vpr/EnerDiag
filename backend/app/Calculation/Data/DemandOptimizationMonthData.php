<?php

namespace App\Calculation\Data;

final readonly class DemandOptimizationMonthData
{
    public function __construct(
        public string $referenceMonth,
        public string $measuredDemand,
        public string $unitRate,
        public string $idleDemandRatio,
    ) {}
}
