<?php

namespace App\Calculation\Data;

final readonly class DemandMonthData
{
    /**
     * @param  array<string, string>  $contractedDemandByTimeSlot
     * @param  array<string, string>  $measuredDemandByTimeSlot
     * @param  array<string, ExtractedInvoiceItemData>  $normalDemandItemsByTimeSlot
     * @param  list<ExtractedInvoiceItemData>  $idleDemandItems
     */
    public function __construct(
        public string $referenceMonth,
        public string $tariffPeriod,
        public array $contractedDemandByTimeSlot,
        public array $measuredDemandByTimeSlot,
        public array $normalDemandItemsByTimeSlot,
        public array $idleDemandItems,
    ) {}

    public static function fromInvoice(ExtractedInvoiceData $invoice, string $tariffPeriod): self
    {
        $contracted = [];
        $measured = [];
        $normalItems = [];
        $idleItems = [];

        foreach ($invoice->items as $item) {
            if ($item->itemType === 'contracted_demand' && $item->quantity !== null) {
                $contracted[$item->timeSlot] = $item->quantity;
            }

            if ($item->itemType === 'measured_demand' && $item->quantity !== null) {
                $measured[$item->timeSlot] = $item->quantity;
                $normalItems[$item->timeSlot] = $item;
            }

            if ($item->itemType === 'idle_demand') {
                $idleItems[] = $item;
            }
        }

        return new self(
            referenceMonth: $invoice->referenceMonth,
            tariffPeriod: $tariffPeriod,
            contractedDemandByTimeSlot: $contracted,
            measuredDemandByTimeSlot: $measured,
            normalDemandItemsByTimeSlot: $normalItems,
            idleDemandItems: $idleItems,
        );
    }
}
