<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandOptimizationMonthData;
use App\Calculation\Data\DemandOptimizationResult;
use App\Calculation\Data\DemandOptimizationSlotResult;
use InvalidArgumentException;

final class DemandOptimizationService
{
    public const string SERIES_UNAVAILABLE_WARNING = 'demand_optimization_series_unavailable';

    public function __construct(private DemandBillingService $billingService) {}

    /** @param list<DemandOptimizationMonthData> $peakMonths
     * @param  list<DemandOptimizationMonthData>  $offPeakMonths
     */
    public function optimizeBlue(array $peakMonths, array $offPeakMonths, int $minimumDemand, int $maximumDemand): DemandOptimizationResult
    {
        $this->validateRange($minimumDemand, $maximumDemand);

        return new DemandOptimizationResult([
            'peak' => $this->optimizeSlot($peakMonths, $minimumDemand, $maximumDemand),
            'off_peak' => $this->optimizeSlot($offPeakMonths, $minimumDemand, $maximumDemand),
        ]);
    }

    /** @param list<DemandOptimizationMonthData> $months */
    public function optimizeGreen(array $months, int $minimumDemand, int $maximumDemand): DemandOptimizationResult
    {
        $this->validateRange($minimumDemand, $maximumDemand);

        return new DemandOptimizationResult([
            'unique' => $this->optimizeSlot($months, $minimumDemand, $maximumDemand),
        ]);
    }

    /** @param list<DemandOptimizationMonthData> $months */
    private function optimizeSlot(array $months, int $minimumDemand, int $maximumDemand): DemandOptimizationSlotResult
    {
        if ($months === []) {
            return new DemandOptimizationSlotResult(null, null, [self::SERIES_UNAVAILABLE_WARNING]);
        }

        $optimalDemand = null;
        $optimalCost = null;
        $warnings = [];

        for ($candidate = $minimumDemand; $candidate <= $maximumDemand; $candidate++) {
            $candidateDemand = (string) $candidate.'.000';
            $candidateCost = '0.00';

            foreach ($months as $month) {
                $monthResult = $this->billingService->calculate(
                    referenceMonth: $month->referenceMonth,
                    contractedDemand: $candidateDemand,
                    measuredDemand: $month->measuredDemand,
                    unitRate: $month->unitRate,
                    idleDemandRatio: $month->idleDemandRatio,
                );
                $warnings = array_merge($warnings, $monthResult->warnings);

                if ($monthResult->total === null) {
                    return new DemandOptimizationSlotResult(null, null, array_values(array_unique($warnings)));
                }

                $candidateCost = bcadd($candidateCost, $monthResult->total, 2);
            }

            if ($optimalCost === null || bccomp($candidateCost, $optimalCost, 2) < 0) {
                $optimalDemand = $candidateDemand;
                $optimalCost = $candidateCost;
            }
        }

        return new DemandOptimizationSlotResult(
            optimalDemand: $optimalDemand,
            totalCost: $optimalCost,
            warnings: array_values(array_unique($warnings)),
        );
    }

    private function validateRange(int $minimumDemand, int $maximumDemand): void
    {
        if ($minimumDemand < 0 || $maximumDemand < $minimumDemand) {
            throw new InvalidArgumentException('Demand optimization requires a non-negative, ascending integer range.');
        }
    }
}
