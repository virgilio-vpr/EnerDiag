<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandCostResult;

final class DemandBillingService
{
    public const string PARAMETER_UNAVAILABLE_WARNING = 'demand_billing_parameter_unavailable';

    public const string RATIO_UNAVAILABLE_WARNING = 'idle_demand_ratio_unavailable';

    public function __construct(private RegulatoryParameterService $parameterService) {}

    public function calculate(
        string $referenceMonth,
        string $contractedDemand,
        string $measuredDemand,
        string $unitRate,
        ?string $idleDemandRatio,
    ): DemandCostResult {
        $date = $referenceMonth.'-01';
        $tolerance = $this->parameterService->resolve('overrun_tolerance', $date);
        $multiplier = $this->parameterService->resolve('overrun_multiplier', $date);

        if ($tolerance === null || $multiplier === null) {
            return $this->unavailable([self::PARAMETER_UNAVAILABLE_WARNING]);
        }

        if ($idleDemandRatio === null) {
            return $this->unavailable([self::RATIO_UNAVAILABLE_WARNING]);
        }

        $zero = '0.00';
        if (bccomp($measuredDemand, $contractedDemand, 3) <= 0) {
            $measuredCharge = $this->money(bcmul($measuredDemand, $unitRate, 10));
            $idleDifference = bcsub($contractedDemand, $measuredDemand, 3);
            $idleCharge = $this->money(bcmul(bcmul($idleDifference, $unitRate, 10), $idleDemandRatio, 10));

            return new DemandCostResult(
                total: bcadd($measuredCharge, $idleCharge, 2),
                condition: 'below_contracted',
                measuredDemandCharge: $measuredCharge,
                idleDemandCharge: $idleCharge,
                contractedDemandCharge: $zero,
                overrunCharge: $zero,
            );
        }

        $toleranceFactor = bcadd('1', $tolerance->value, 8);
        $maximumWithoutPenalty = bcmul($contractedDemand, $toleranceFactor, 8);
        if (bccomp($measuredDemand, $maximumWithoutPenalty, 8) <= 0) {
            $measuredCharge = $this->money(bcmul($measuredDemand, $unitRate, 10));

            return new DemandCostResult(
                total: $measuredCharge,
                condition: 'within_tolerance',
                measuredDemandCharge: $measuredCharge,
                idleDemandCharge: $zero,
                contractedDemandCharge: $zero,
                overrunCharge: $zero,
            );
        }

        $contractedCharge = $this->money(bcmul($contractedDemand, $unitRate, 10));
        $excessDemand = bcsub($measuredDemand, $contractedDemand, 3);
        $overrunCharge = $this->money(bcmul(bcmul($excessDemand, $multiplier->value, 10), $unitRate, 10));

        return new DemandCostResult(
            total: bcadd($contractedCharge, $overrunCharge, 2),
            condition: 'overrun',
            measuredDemandCharge: $zero,
            idleDemandCharge: $zero,
            contractedDemandCharge: $contractedCharge,
            overrunCharge: $overrunCharge,
        );
    }

    /** @param list<string> $warnings */
    private function unavailable(array $warnings): DemandCostResult
    {
        return new DemandCostResult(null, null, null, null, null, null, $warnings);
    }

    private function money(string $amount): string
    {
        return bcadd($amount, '0.005', 2);
    }
}
