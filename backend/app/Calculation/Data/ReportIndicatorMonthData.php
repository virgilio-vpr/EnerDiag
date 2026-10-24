<?php

namespace App\Calculation\Data;

final readonly class ReportIndicatorMonthData
{
    /**
     * @param  array<string, string>  $monthlySavingsByComponent
     * @param  array<string, string>  $chartValuesByKey
     */
    public function __construct(
        public string $referenceMonth,
        public array $monthlySavingsByComponent,
        public string $totalPaid,
        public array $chartValuesByKey,
    ) {}
}
