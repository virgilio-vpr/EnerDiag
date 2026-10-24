<?php

namespace App\Calculation\Data;

final readonly class TariffMigrationMonthData
{
    /**
     * @param  array<string, array<string, string>>  $measuredDemandByModality
     * @param  array<string, array<string, string>>  $idleDemandRatioByModality
     * @param  array<string, string>  $currentDemandRatesByTimeSlot
     * @param  array<string, string>  $consumptionKwhByTimeSlot
     * @param  array<string, array<string, ExtractedInvoiceItemData>>  $currentEnergyItemsByComponentAndTimeSlot
     */
    public function __construct(
        public string $referenceMonth,
        public array $measuredDemandByModality,
        public array $idleDemandRatioByModality,
        public array $currentDemandRatesByTimeSlot,
        public array $consumptionKwhByTimeSlot,
        public array $currentEnergyItemsByComponentAndTimeSlot,
        public ?string $calibrationFactor,
    ) {}
}
