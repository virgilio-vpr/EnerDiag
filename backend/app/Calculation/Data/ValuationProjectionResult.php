<?php

namespace App\Calculation\Data;

final readonly class ValuationProjectionResult
{
    /**
     * @param  array<string, string>  $historicalTotalsByComponent
     * @param  array<string, string>  $projectedMonthlyAveragesByComponent
     * @param  array<string, string>  $projectedAnnualTotalsByComponent
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $monthsCount,
        public array $historicalTotalsByComponent,
        public array $projectedMonthlyAveragesByComponent,
        public array $projectedAnnualTotalsByComponent,
        public array $warnings = [],
    ) {}
}
