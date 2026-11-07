<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandOptimizationMonthData;
use App\Calculation\Data\TariffMigrationMonthData;
use App\Calculation\Data\TariffMigrationResult;
use App\Calculation\Data\TariffResolution;
use InvalidArgumentException;

final class TariffMigrationService
{
    public const string MIGRATION_UNAVAILABLE_WARNING = 'migration_unavailable';

    public function __construct(
        private TariffService $tariffService,
        private TariffCalibrationService $calibrationService,
        private DemandOptimizationService $demandOptimizationService,
    ) {}

    /** @param list<TariffMigrationMonthData> $months */
    public function compare(
        int $distributorId,
        string $subgroup,
        string $currentModality,
        array $months,
        int $minimumDemand,
        int $maximumDemand,
        bool $minimumEitherBlueSlot = false,
        ?string $tariffReferenceDate = null,
    ): TariffMigrationResult {
        if (! in_array($currentModality, ['blue', 'green'], true)) {
            throw new InvalidArgumentException('Tariff migration modality must be blue or green.');
        }

        if ($months === []) {
            return $this->unavailable([self::MIGRATION_UNAVAILABLE_WARNING]);
        }

        $otherModality = $currentModality === 'blue' ? 'green' : 'blue';
        $demandMonths = ['blue' => ['peak' => [], 'off_peak' => []], 'green' => ['unique' => []]];
        $currentEnergyCost = '0.00';
        $otherEnergyCost = '0.00';
        $warnings = [];
        $tariffVersionIds = [];
        $tariffCache = [];
        $resolveTariff = function (string $modality, string $component, string $slot, string $date) use ($distributorId, $subgroup, $tariffReferenceDate, &$tariffCache): TariffResolution {
            $key = implode('|', [$modality, $component, $slot, $date]);

            return $tariffCache[$key] ??= $this->tariffService->resolve($distributorId, $subgroup, $modality, $component, $slot, $date, false, $tariffReferenceDate !== null);
        };

        foreach ($months as $month) {
            $date = $tariffReferenceDate ?? $month->referenceMonth.'-01';
            $slots = $otherModality === 'green' ? ['unique'] : ['peak', 'off_peak'];

            foreach (['blue', 'green'] as $modality) {
                $modalitySlots = $modality === 'green' ? ['unique'] : ['peak', 'off_peak'];
                foreach ($modalitySlots as $slot) {
                    $measured = $month->measuredDemandByModality[$modality][$slot] ?? null;
                    $ratio = $month->idleDemandRatioByModality[$modality][$slot] ?? null;
                    if ($measured === null || $ratio === null) {
                        return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                    }

                    if ($modality === $currentModality && $tariffReferenceDate === null) {
                        $rate = $month->currentDemandRatesByTimeSlot[$slot] ?? null;
                    } else {
                        $rateResolution = $resolveTariff($modality, 'demand', $slot === 'unique' ? 'single' : $slot, $date);
                        $warnings = array_merge($warnings, $rateResolution->warnings);
                        if ($rateResolution->tariff !== null) {
                            $tariffVersionIds[] = $rateResolution->tariff->id;
                        }
                        $calibrated = $this->calibrationService->convertTariff($rateResolution->tariff, $month->calibrationFactor);
                        $warnings = array_merge($warnings, $calibrated->warnings);
                        $rate = $calibrated->rate;
                    }

                    if ($rate === null) {
                        return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                    }
                    $demandMonths[$modality][$slot][] = new DemandOptimizationMonthData($month->referenceMonth, $measured, $rate, $ratio);
                }
            }

            foreach (['peak', 'off_peak'] as $slot) {
                $quantity = $month->consumptionKwhByTimeSlot[$slot] ?? null;
                if ($quantity === null) {
                    return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                }
                foreach (['energy_te', 'energy_tusd'] as $component) {
                    if ($tariffReferenceDate !== null) {
                        foreach ([$currentModality, $otherModality] as $modality) {
                            $resolution = $resolveTariff($modality, $component, $slot, $date);
                            $warnings = array_merge($warnings, $resolution->warnings);
                            $calibrated = $this->calibrationService->convertTariff($resolution->tariff, $month->calibrationFactor);
                            $warnings = array_merge($warnings, $calibrated->warnings);
                            if ($calibrated->rate === null) {
                                return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                            }
                            $tariffVersionIds[] = $resolution->tariff->id;
                            $amount = $this->money(bcmul($quantity, $calibrated->rate, 10));
                            if ($modality === $currentModality) {
                                $currentEnergyCost = bcadd($currentEnergyCost, $amount, 2);
                            } else {
                                $otherEnergyCost = bcadd($otherEnergyCost, $amount, 2);
                            }
                        }

                        continue;
                    }
                    $invoiceItem = $month->currentEnergyItemsByComponentAndTimeSlot[$component][$slot] ?? null;
                    if ($invoiceItem === null || $invoiceItem->unitPriceTaxed === null) {
                        return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                    }
                    $currentEnergyCost = bcadd($currentEnergyCost, $invoiceItem->amount, 2);

                    if ($component === 'energy_te') {
                        $teResolution = $resolveTariff($otherModality, $component, $slot, $date);
                        // TE is taken from the invoice when the ANEEL table has no row or the rates match.
                        $otherTe = $teResolution->tariff;
                        if ($otherTe !== null) {
                            $tariffVersionIds[] = $otherTe->id;
                        }
                        if ($otherTe === null || ($invoiceItem->tariffRate !== null && bccomp($invoiceItem->tariffRate, $otherTe->rate, 5) === 0)) {
                            $otherRate = $invoiceItem->unitPriceTaxed;
                        } else {
                            $calibratedTe = $this->calibrationService->alternativeEnergyTe($invoiceItem, $otherTe, $month->calibrationFactor);
                            $warnings = array_merge($warnings, $calibratedTe->warnings);
                            $otherRate = $calibratedTe->rate;
                        }
                    } else {
                        $resolution = $resolveTariff($otherModality, $component, $slot, $date);
                        $warnings = array_merge($warnings, $resolution->warnings);
                        if ($resolution->tariff !== null) {
                            $tariffVersionIds[] = $resolution->tariff->id;
                        }
                        $calibrated = $this->calibrationService->convertTariff($resolution->tariff, $month->calibrationFactor);
                        $warnings = array_merge($warnings, $calibrated->warnings);
                        $otherRate = $calibrated->rate;
                    }

                    if ($otherRate === null) {
                        return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                    }
                    $otherEnergyCost = bcadd($otherEnergyCost, $this->money(bcmul($quantity, $otherRate, 10)), 2);
                }
            }
        }

        $blueDemand = $this->demandOptimizationService->optimizeBlue(
            $demandMonths['blue']['peak'], $demandMonths['blue']['off_peak'], $minimumEitherBlueSlot ? 0 : $minimumDemand, $maximumDemand,
            $minimumEitherBlueSlot ? $minimumDemand : null,
        );
        $greenDemand = $this->demandOptimizationService->optimizeGreen(
            $demandMonths['green']['unique'], $minimumDemand, $maximumDemand,
        );
        foreach ([$blueDemand, $greenDemand] as $result) {
            foreach ($result->slots as $slot) {
                $warnings = array_merge($warnings, $slot->warnings);
                if ($slot->totalCost === null) {
                    return $this->unavailable(array_merge($warnings, [self::MIGRATION_UNAVAILABLE_WARNING]));
                }
            }
        }

        $blueCost = bcadd(bcadd($blueDemand->slots['peak']->totalCost, $blueDemand->slots['off_peak']->totalCost, 2),
            $currentModality === 'blue' ? $currentEnergyCost : $otherEnergyCost, 2);
        $greenCost = bcadd($greenDemand->slots['unique']->totalCost,
            $currentModality === 'green' ? $currentEnergyCost : $otherEnergyCost, 2);

        if (bccomp($blueCost, $greenCost, 2) <= 0) {
            $savings = bccomp($greenCost, $blueCost, 2) > 0 ? bcsub($greenCost, $blueCost, 2) : '0.00';
            $selected = 'blue';
        } else {
            $savings = bcsub($blueCost, $greenCost, 2);
            $selected = 'green';
        }
        if ($selected === $currentModality) {
            $savings = '0.00';
        }
        $message = bccomp($savings, '0.00', 2) === 0 ? 'Sem ganho financeiro na migração' : null;

        return new TariffMigrationResult(true, $selected, $blueCost, $greenCost, $savings, $message, array_values(array_unique($warnings)), array_values(array_unique($tariffVersionIds)));
    }

    /** @param list<string> $warnings */
    private function unavailable(array $warnings): TariffMigrationResult
    {
        return new TariffMigrationResult(false, null, null, null, null, 'Migração indisponível por falta de tarifa ou dados necessários.', array_values(array_unique($warnings)));
    }

    private function money(string $amount): string
    {
        return bcadd($amount, '0.005', 2);
    }
}
