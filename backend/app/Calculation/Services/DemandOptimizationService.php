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
    public function optimizeBlue(array $peakMonths, array $offPeakMonths, int $minimumDemand, int $maximumDemand, ?int $minimumEitherSlot = null): DemandOptimizationResult
    {
        $this->validateRange($minimumDemand, $maximumDemand);

        if ($minimumEitherSlot !== null && ($minimumEitherSlot < 0 || $minimumEitherSlot > $maximumDemand)) {
            throw new InvalidArgumentException('The minimum for either time slot must be within the optimization range.');
        }

        $peak = $this->optimizeSlot($peakMonths, $minimumDemand, $maximumDemand);
        $offPeak = $this->optimizeSlot($offPeakMonths, $minimumDemand, $maximumDemand);
        if ($minimumEitherSlot !== null && $peak->optimalDemand !== null && $offPeak->optimalDemand !== null
            && bccomp($peak->optimalDemand, (string) $minimumEitherSlot, 3) < 0
            && bccomp($offPeak->optimalDemand, (string) $minimumEitherSlot, 3) < 0) {
            $constrainedMinimum = max($minimumDemand, $minimumEitherSlot);
            $peakConstrained = $this->optimizeSlot($peakMonths, $constrainedMinimum, $maximumDemand);
            $offPeakConstrained = $this->optimizeSlot($offPeakMonths, $constrainedMinimum, $maximumDemand);
            if ($peakConstrained->totalCost !== null && $offPeakConstrained->totalCost !== null) {
                $peakConstrainedCost = bcadd($peakConstrained->totalCost, $offPeak->totalCost, 2);
                $offPeakConstrainedCost = bcadd($peak->totalCost, $offPeakConstrained->totalCost, 2);
                if (bccomp($peakConstrainedCost, $offPeakConstrainedCost, 2) < 0) {
                    $peak = $peakConstrained;
                } else {
                    $offPeak = $offPeakConstrained;
                }
            }
        }

        return new DemandOptimizationResult([
            'peak' => $peak,
            'off_peak' => $offPeak,
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
