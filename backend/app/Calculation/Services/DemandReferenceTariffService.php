<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandMonthData;
use App\Calculation\Data\ExtractedInvoiceData;

final class DemandReferenceTariffService
{
    public function __construct(
        private readonly TariffService $tariffs,
        private readonly TariffCalibrationService $calibration,
    ) {}

    /**
     * @param  list<ExtractedInvoiceData>  $invoices
     * @param  list<DemandMonthData>  $months
     * @return array<string, string>|null
     */
    public function resolve(?int $distributorId, array $invoices, array $months, string $slot): ?array
    {
        if ($invoices === [] || count($invoices) !== count($months)) {
            return null;
        }

        $latest = $invoices[array_key_last($invoices)];
        $latestMonth = $months[array_key_last($months)];
        if ($distributorId !== null) {
            $tariff = $this->tariffs->resolve($distributorId, $latest->subgroup, $latest->tariffModality, 'demand', $slot === 'unique' ? 'single' : $slot, today(), false, true)->tariff
                ?? $this->tariffs->resolve($distributorId, $latest->subgroup, $latest->tariffModality, 'demand', $slot === 'unique' ? 'single' : $slot, today(), true, true)->tariff;
            if ($tariff !== null && bccomp($tariff->rate, '0', 5) > 0) {
                $item = $latestMonth->normalDemandItemsByTimeSlot[$slot] ?? null;
                $canCalibrate = $item !== null && (($item->tariffRate !== null && bccomp($item->tariffRate, '0', 5) > 0)
                    || bccomp($item->icmsRate, '0', 4) > 0 || bccomp($item->pisCofinsAmount, '0', 2) > 0);
                $factor = $canCalibrate ? $this->calibration->factorFromInvoiceItem($item) : null;
                $rate = $tariff->includes_taxes ? $tariff->rate : $this->calibration->convertTariff($tariff, $factor)->rate;
                if ($rate !== null && bccomp($rate, '0', 5) > 0) {
                    return [
                        'tariff_reference_source' => 'registered_tariff',
                        'tariff_reference_month' => $tariff->valid_from->format('Y-m'),
                        'reference_tariff' => $rate,
                        'reference_tariff_id' => (string) $tariff->id,
                        'reference_tariff_untaxed' => $tariff->includes_taxes ? '' : $tariff->rate,
                        'reference_tax_factor' => $tariff->includes_taxes ? '1.000000' : $factor,
                        'tax_reference_month' => $latest->referenceMonth,
                        'tariff_valid_from' => $tariff->valid_from->toDateString(),
                        'tariff_valid_to' => $tariff->valid_to?->toDateString() ?? '',
                    ];
                }
            }
        }

        $reference = null;
        foreach ($months as $index => $month) {
            $invoice = $invoices[$index];
            if ($invoice->subgroup !== $latest->subgroup || $invoice->tariffModality !== $latest->tariffModality) {
                continue;
            }
            $rate = $month->normalDemandItemsByTimeSlot[$slot]->unitPriceTaxed ?? null;
            if ($rate === null || bccomp($rate, '0', 5) <= 0) {
                continue;
            }
            if ($reference === null || bccomp($rate, $reference['reference_tariff'], 5) > 0
                || (bccomp($rate, $reference['reference_tariff'], 5) === 0 && $month->referenceMonth > $reference['tariff_reference_month'])) {
                $reference = [
                    'tariff_reference_source' => 'series_maximum',
                    'tariff_reference_month' => $month->referenceMonth,
                    'reference_tariff' => $rate,
                ];
            }
        }

        return $reference;
    }
}
