<?php

namespace App\Calculation\Services;

use App\Calculation\Data\ValuationMonthData;
use App\Calculation\Data\ValuationProjectionResult;
use InvalidArgumentException;

final class ValuationProjectionService
{
    public const string INCOMPLETE_SERIES_WARNING = 'incomplete_invoice_series';

    public const string TARIFF_UNAVAILABLE_WARNING = 'tariff_unavailable_using_historical_value';

    /** @param list<ValuationMonthData> $months */
    public function project(array $months): ValuationProjectionResult
    {
        if ($months === [] || count($months) > 12) {
            throw new InvalidArgumentException('Valuation requires between 1 and 12 monthly records.');
        }

        $components = [];
        foreach ($months as $month) {
            $components = array_unique(array_merge(
                $components,
                array_keys($month->historicalSavingsByComponent),
                array_keys($month->currentTariffSavingsByComponent),
            ));
        }

        $historicalTotals = array_fill_keys($components, '0.00');
        $projectedTotals = array_fill_keys($components, '0.00');
        $warnings = count($months) < 12 ? [self::INCOMPLETE_SERIES_WARNING] : [];

        foreach ($months as $month) {
            foreach ($components as $component) {
                $historical = $month->historicalSavingsByComponent[$component] ?? '0.00';
                $historicalTotals[$component] = bcadd($historicalTotals[$component], $historical, 2);

                $currentTariffValue = $month->currentTariffSavingsByComponent[$component] ?? null;
                if ($currentTariffValue === null) {
                    $currentTariffValue = $historical;
                    $warnings[] = self::TARIFF_UNAVAILABLE_WARNING;
                }
                $projectedTotals[$component] = bcadd($projectedTotals[$component], $currentTariffValue, 2);
            }
        }

        $monthlyAverages = [];
        $annualTotals = [];
        foreach ($components as $component) {
            $average = $this->roundHalfUp(bcdiv($projectedTotals[$component], (string) count($months), 8), 2);
            $monthlyAverages[$component] = $average;
            $annualTotals[$component] = $this->roundHalfUp(bcmul($average, '12', 4), 2);
        }

        return new ValuationProjectionResult(
            monthsCount: count($months),
            historicalTotalsByComponent: $historicalTotals,
            projectedMonthlyAveragesByComponent: $monthlyAverages,
            projectedAnnualTotalsByComponent: $annualTotals,
            warnings: array_values(array_unique($warnings)),
        );
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }
}
