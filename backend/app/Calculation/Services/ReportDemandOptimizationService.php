<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandMonthData;
use App\Calculation\Data\DemandOptimizationMonthData;
use App\Calculation\Data\ExtractedInvoiceData;
use App\Calculation\Data\IdleDemandRatioResolution;

final class ReportDemandOptimizationService
{
    public const string UNAVAILABLE_WARNING = 'demand_optimization_unavailable';

    public function __construct(
        private readonly RegulatoryParameterService $parameters,
        private readonly DemandOptimizationService $optimization,
        private readonly DemandBillingService $billing,
    ) {}

    /**
     * @param  list<ExtractedInvoiceData>  $invoices
     * @param  list<DemandMonthData>  $months
     * @return array{optimal: array<string, string>, savings: array<string, string>, parameter_id: ?int, warnings: list<string>}
     */
    public function calculate(array $invoices, array $months, IdleDemandRatioResolution $ratios): array
    {
        $unavailable = ['optimal' => [], 'savings' => [], 'parameter_id' => null, 'warnings' => [self::UNAVAILABLE_WARNING]];
        if ($invoices === [] || count($invoices) !== count($months)) {
            return $unavailable;
        }

        $latest = $invoices[array_key_last($invoices)];
        $parameter = $this->parameters->resolve('minimum_contracted_demand', $latest->referenceMonth.'-01');
        if ($parameter === null || bccomp($parameter->value, '0', 5) <= 0 || bccomp($parameter->value, '1000000', 5) > 0
            || bccomp($parameter->value, bcadd($parameter->value, '0', 0), 5) !== 0) {
            return $unavailable;
        }
        $minimum = (int) $parameter->value;
        $slots = match ($latest->tariffModality) {
            'blue' => ['peak', 'off_peak'],
            'green' => ['unique'],
            default => [],
        };
        if ($slots === []) {
            return $unavailable;
        }

        $lastMonth = $months[array_key_last($months)];
        $series = array_fill_keys($slots, []);
        $maximum = $minimum;
        foreach ($months as $index => $month) {
            if ($invoices[$index]->tariffModality !== $latest->tariffModality) {
                return $unavailable;
            }
            foreach ($slots as $slot) {
                $measured = $month->measuredDemandByTimeSlot[$slot] ?? null;
                $contracted = $month->contractedDemandByTimeSlot[$slot] ?? null;
                $rate = $lastMonth->normalDemandItemsByTimeSlot[$slot]->unitPriceTaxed ?? null;
                $ratio = $ratios->forMonthAndTimeSlot($month->referenceMonth, $slot)?->ratio;
                if ($measured === null || $contracted === null || $rate === null || $ratio === null
                    || bccomp($measured, '0', 3) < 0 || bccomp($rate, '0', 5) <= 0
                    || bccomp($measured, '1000000', 3) > 0 || bccomp($contracted, '1000000', 3) > 0) {
                    return $unavailable;
                }
                $maximum = max($maximum, (int) bcadd($measured, '0.999', 0), (int) bcadd($contracted, '0.999', 0));
                $series[$slot][] = new DemandOptimizationMonthData($month->referenceMonth, $measured, $rate, $ratio);
            }
        }

        $result = $latest->tariffModality === 'blue'
            ? $this->optimization->optimizeBlue($series['peak'], $series['off_peak'], 0, $maximum, $minimum)
            : $this->optimization->optimizeGreen($series['unique'], $minimum, $maximum);
        $optimal = [];
        foreach ($result->slots as $slot => $resultSlot) {
            if ($resultSlot->optimalDemand === null) {
                return $unavailable;
            }
            $optimal[$slot] = $resultSlot->optimalDemand;
        }

        $savings = [];
        $total = '0.00';
        foreach ($invoices as $index => $invoice) {
            $actual = '0.00';
            foreach ($invoice->items as $item) {
                if (in_array($item->itemType, ['measured_demand', 'idle_demand', 'demand_overrun'], true)) {
                    $actual = bcadd($actual, $item->amount, 2);
                }
            }
            $simulated = '0.00';
            foreach ($slots as $slot) {
                $month = $series[$slot][$index];
                $cost = $this->billing->calculate($month->referenceMonth, $optimal[$slot], $month->measuredDemand, $month->unitRate, $month->idleDemandRatio);
                if ($cost->total === null) {
                    return $unavailable;
                }
                $simulated = bcadd($simulated, $cost->total, 2);
            }
            $savings[$invoice->referenceMonth] = bcsub($actual, $simulated, 2);
            $total = bcadd($total, $savings[$invoice->referenceMonth], 2);
        }
        if (bccomp($total, '0', 2) < 0) {
            $savings = array_fill_keys(array_keys($savings), '0.00');
        }

        return ['optimal' => $optimal, 'savings' => $savings, 'parameter_id' => $parameter->id, 'warnings' => []];
    }
}
