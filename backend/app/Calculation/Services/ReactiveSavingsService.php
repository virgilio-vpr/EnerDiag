<?php

namespace App\Calculation\Services;

use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\ReactiveSavingsResult;

final class ReactiveSavingsService
{
    public const string REACTIVE_ENERGY_ITEM_TYPE = 'reactive_energy_excess';

    public const string REACTIVE_DEMAND_ITEM_TYPE = 'reactive_demand_excess';

    public const string NO_OCCURRENCE_MESSAGE = 'Sem ocorrência no período';

    /** @param list<ExtractedInvoiceData> $invoices */
    public function calculate(array $invoices): ReactiveSavingsResult
    {
        usort($invoices, static fn (ExtractedInvoiceData $left, ExtractedInvoiceData $right): int => $left->referenceMonth <=> $right->referenceMonth);

        $byMonth = [];
        $reactiveEnergyTotal = '0.00';
        $reactiveDemandTotal = '0.00';
        $occurred = false;

        foreach ($invoices as $invoice) {
            $reactiveEnergy = '0.00';
            $reactiveDemand = '0.00';

            foreach ($invoice->items as $item) {
                if ($item->itemType === 'dmcr' || str_contains(strtoupper($item->sourceLabel ?? ''), 'DMCR')) {
                    continue;
                }

                if ($item->itemType === self::REACTIVE_ENERGY_ITEM_TYPE) {
                    $reactiveEnergy = bcadd($reactiveEnergy, $item->amount, 2);
                }

                if ($item->itemType === self::REACTIVE_DEMAND_ITEM_TYPE) {
                    $reactiveDemand = bcadd($reactiveDemand, $item->amount, 2);
                }
            }

            if (bccomp($reactiveEnergy, '0', 2) !== 0 || bccomp($reactiveDemand, '0', 2) !== 0) {
                $occurred = true;
            }

            $monthTotal = bcadd($reactiveEnergy, $reactiveDemand, 2);
            $byMonth[$invoice->referenceMonth] = [
                'reactive_energy' => $reactiveEnergy,
                'reactive_demand' => $reactiveDemand,
                'total' => $monthTotal,
            ];
            $reactiveEnergyTotal = bcadd($reactiveEnergyTotal, $reactiveEnergy, 2);
            $reactiveDemandTotal = bcadd($reactiveDemandTotal, $reactiveDemand, 2);
        }

        $total = bcadd($reactiveEnergyTotal, $reactiveDemandTotal, 2);

        return new ReactiveSavingsResult(
            totalSavings: $total,
            reactiveEnergySavings: $reactiveEnergyTotal,
            reactiveDemandSavings: $reactiveDemandTotal,
            occurred: $occurred,
            message: $occurred ? null : self::NO_OCCURRENCE_MESSAGE,
            byMonth: $byMonth,
        );
    }
}
