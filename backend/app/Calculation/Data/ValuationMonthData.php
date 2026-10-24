<?php

namespace App\Calculation\Data;

final readonly class ValuationMonthData
{
    /**
     * @param  array<string, string>  $historicalSavingsByComponent  Values calculated with the tariffs billed in this month.
     * @param  array<string, ?string>  $currentTariffSavingsByComponent  Values recalculated with the latest applicable tariffs; null means unavailable.
     */
    public function __construct(
        public string $referenceMonth,
        public array $historicalSavingsByComponent,
        public array $currentTariffSavingsByComponent,
    ) {}
}
