<?php

namespace App\Calculation\Services;

use App\Calculation\Data\ReportIndicatorMonthData;
use App\Calculation\Data\ReportIndicatorsResult;
use InvalidArgumentException;

final class ReportIndicatorsService
{
    public const string ZERO_PAID_WARNING = 'average_total_paid_zero';

    public const string PERCENTAGE_LABEL = '% do valor médio pago no período';

    public const string PERCENTAGE_OBSERVATION = 'A economia usa tarifas atuais e o valor pago usa as tarifas históricas.';

    /** @param list<ReportIndicatorMonthData> $months */
    public function calculate(array $months): ReportIndicatorsResult
    {
        if ($months === []) {
            throw new InvalidArgumentException('Report indicators require at least one month.');
        }

        usort($months, static fn (ReportIndicatorMonthData $left, ReportIndicatorMonthData $right): int => strcmp($left->referenceMonth, $right->referenceMonth));

        $components = [];
        $seriesKeys = [];
        foreach ($months as $month) {
            $components = array_unique(array_merge($components, array_keys($month->monthlySavingsByComponent)));
            $seriesKeys = array_unique(array_merge($seriesKeys, array_keys($month->chartValuesByKey)));
        }

        $savingsTotals = array_fill_keys($components, '0.00');
        $series = array_fill_keys($seriesKeys, []);
        $paidTotal = '0.00';
        foreach ($months as $month) {
            $paidTotal = bcadd($paidTotal, $month->totalPaid, 2);
            foreach ($components as $component) {
                $savingsTotals[$component] = bcadd(
                    $savingsTotals[$component],
                    $month->monthlySavingsByComponent[$component] ?? '0.00',
                    2,
                );
            }
            foreach ($seriesKeys as $key) {
                if (array_key_exists($key, $month->chartValuesByKey)) {
                    $series[$key][] = ['reference_month' => $month->referenceMonth, 'value' => $month->chartValuesByKey[$key]];
                }
            }
        }

        $monthlySavingsByComponent = [];
        $annualSavingsByComponent = [];
        $totalMonthlySavings = '0.00';
        $totalAnnualSavings = '0.00';
        foreach ($components as $component) {
            $monthlyAverage = $this->roundHalfUp(bcdiv($savingsTotals[$component], (string) count($months), 8), 2);
            $annualProjection = $this->roundHalfUp(bcmul($monthlyAverage, '12', 4), 2);
            $monthlySavingsByComponent[$component] = $monthlyAverage;
            $annualSavingsByComponent[$component] = $annualProjection;
            $totalMonthlySavings = bcadd($totalMonthlySavings, $monthlyAverage, 2);
            $totalAnnualSavings = bcadd($totalAnnualSavings, $annualProjection, 2);
        }

        $averageTotalPaid = $this->roundHalfUp(bcdiv($paidTotal, (string) count($months), 8), 2);
        $warnings = [];
        $percentage = null;
        if (bccomp($averageTotalPaid, '0.00', 2) === 0) {
            $warnings[] = self::ZERO_PAID_WARNING;
        } else {
            $percentage = $this->roundHalfUp(
                bcmul(bcdiv($totalMonthlySavings, $averageTotalPaid, 8), '100', 8),
                2,
            );
        }

        return new ReportIndicatorsResult(
            totalMonthlySavings: $totalMonthlySavings,
            totalAnnualSavings: $totalAnnualSavings,
            monthlySavingsByComponent: $monthlySavingsByComponent,
            annualSavingsByComponent: $annualSavingsByComponent,
            averageTotalPaid: $averageTotalPaid,
            savingsPercentage: $percentage,
            percentageLabel: self::PERCENTAGE_LABEL,
            percentageObservation: self::PERCENTAGE_OBSERVATION,
            seriesByKey: $series,
            warnings: $warnings,
        );
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }
}
