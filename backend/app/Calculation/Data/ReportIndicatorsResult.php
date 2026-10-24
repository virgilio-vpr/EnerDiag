<?php

namespace App\Calculation\Data;

final readonly class ReportIndicatorsResult
{
    /**
     * @param  array<string, string>  $monthlySavingsByComponent
     * @param  array<string, string>  $annualSavingsByComponent
     * @param  array<string, list<array{reference_month: string, value: string}>>  $seriesByKey
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $totalMonthlySavings,
        public string $totalAnnualSavings,
        public array $monthlySavingsByComponent,
        public array $annualSavingsByComponent,
        public string $averageTotalPaid,
        public ?string $savingsPercentage,
        public string $percentageLabel,
        public string $percentageObservation,
        public array $seriesByKey,
        public array $warnings = [],
    ) {}
}
