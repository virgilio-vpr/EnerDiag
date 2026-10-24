<?php

namespace App\Calculation\Services;

use App\Calculation\Data\DemandMonthData;
use App\Calculation\Data\ExtractedInvoiceItemData;
use App\Calculation\Data\IdleDemandRatioEntry;
use App\Calculation\Data\IdleDemandRatioResolution;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class IdleDemandRatioService
{
    public const string TAXATION_SOURCE = 'taxation';

    public const string UNIT_PRICE_SOURCE = 'unit_price';

    public const string FALLBACK_SOURCE = 'fallback';

    public const string FULL_CHARGE_SOURCE = 'full_charge';

    public const string FALLBACK_WARNING = 'idle_demand_discount_fallback';

    public const string FALLBACK_PARAMETER_WARNING = 'idle_demand_fallback_parameter_unavailable';

    public const string PIS_COFINS_WARNING = 'idle_demand_pis_cofins_inconsistent';

    public const string MATCHING_WARNING = 'idle_demand_post_matching_unresolved';

    public const string PIS_COFINS_TOLERANCE = '0.0002';

    public function __construct(private RegulatoryParameterService $parameterService) {}

    /**
     * Resolve the idle demand proportion for each month and time slot.
     * Months must be assigned a tariff-period key so a known ratio does not cross a tariff revision.
     *
     * @param  list<DemandMonthData>  $months
     */
    public function resolve(array $months, bool $readerRecognizesIdleDemand): IdleDemandRatioResolution
    {
        usort($months, static fn (DemandMonthData $left, DemandMonthData $right): int => $left->referenceMonth <=> $right->referenceMonth);

        $direct = [];
        $slotsByMonth = [];
        foreach ($months as $monthIndex => $month) {
            $slots = array_unique(array_merge(
                array_keys($month->contractedDemandByTimeSlot),
                array_keys($month->measuredDemandByTimeSlot),
                array_keys($month->normalDemandItemsByTimeSlot),
            ));
            sort($slots);
            $slotsByMonth[$monthIndex] = $slots;

            foreach ($slots as $timeSlot) {
                $observation = $this->observeMonthAndSlot($month, $timeSlot, $readerRecognizesIdleDemand);
                if ($observation !== null) {
                    $direct[$monthIndex][$timeSlot] = $observation;
                }
            }
        }

        $resolved = [];
        foreach ($months as $monthIndex => $month) {
            foreach ($slotsByMonth[$monthIndex] as $timeSlot) {
                if (isset($direct[$monthIndex][$timeSlot])) {
                    $resolved[$monthIndex][$timeSlot] = $direct[$monthIndex][$timeSlot] + ['inherited' => false];

                    continue;
                }

                $candidate = $this->nearestObservation($months, $monthIndex, $timeSlot, $direct);
                if ($candidate !== null) {
                    $resolved[$monthIndex][$timeSlot] = $candidate + ['inherited' => true];

                    continue;
                }

                $parameter = $this->parameterService->resolve('idle_demand_discount_fallback', $month->referenceMonth.'-01');
                if ($parameter === null) {
                    $resolved[$monthIndex][$timeSlot] = [
                        'ratio' => null,
                        'source' => self::FALLBACK_SOURCE,
                        'warnings' => [self::FALLBACK_PARAMETER_WARNING],
                        'inherited' => false,
                    ];

                    continue;
                }

                $discount = $parameter->value;
                if (bccomp($discount, '0', 5) < 0 || bccomp($discount, '1', 5) > 0) {
                    throw new InvalidArgumentException('idle_demand_discount_fallback must be between 0 and 1.');
                }

                $resolved[$monthIndex][$timeSlot] = [
                    'ratio' => bcsub('1', $discount, 6),
                    'source' => self::FALLBACK_SOURCE,
                    'warnings' => [self::FALLBACK_WARNING],
                    'inherited' => false,
                ];
            }
        }

        $entries = [];
        foreach ($months as $monthIndex => $month) {
            [$assignments, $matchingWarnings] = $this->matchIdleRows(
                $month,
                $slotsByMonth[$monthIndex],
                $resolved[$monthIndex] ?? [],
            );

            foreach ($slotsByMonth[$monthIndex] as $timeSlot) {
                $item = $resolved[$monthIndex][$timeSlot];
                $entries[] = new IdleDemandRatioEntry(
                    referenceMonth: $month->referenceMonth,
                    timeSlot: $timeSlot,
                    ratio: $item['ratio'],
                    source: $item['source'],
                    inherited: $item['inherited'],
                    idleItemIndex: $assignments[$timeSlot] ?? null,
                    warnings: array_values(array_unique(array_merge($item['warnings'] ?? [], $matchingWarnings))),
                );
            }
        }

        return new IdleDemandRatioResolution($entries);
    }

    /** @return array{ratio: string, source: string, warnings: list<string>}|null */
    private function observeMonthAndSlot(DemandMonthData $month, string $timeSlot, bool $readerRecognizesIdleDemand): ?array
    {
        $normalItem = $month->normalDemandItemsByTimeSlot[$timeSlot] ?? null;
        if ($normalItem !== null) {
            $idleTaxRows = array_filter(
                $month->idleDemandItems,
                static fn (ExtractedInvoiceItemData $item): bool => bccomp($item->icmsBase, '0', 2) > 0
                    && bccomp($item->icmsRate, '0', 4) === 0,
            );

            if ($idleTaxRows !== [] && bccomp($normalItem->icmsRate, '0', 4) > 0) {
                $ratio = bcsub('1', $normalItem->icmsRate, 6);
                if ($this->isValidRatio($ratio)) {
                    $warnings = $this->pisCofinsIsConsistent($month->idleDemandItems, $normalItem, $ratio)
                        ? []
                        : [self::PIS_COFINS_WARNING];

                    return ['ratio' => $ratio, 'source' => self::TAXATION_SOURCE, 'warnings' => $warnings];
                }
            }

            foreach ($month->idleDemandItems as $idleItem) {
                if ($idleItem->unitPriceTaxed === null || $normalItem->unitPriceTaxed === null
                    || bccomp($normalItem->unitPriceTaxed, '0', 5) <= 0) {
                    continue;
                }

                $ratio = bcdiv($idleItem->unitPriceTaxed, $normalItem->unitPriceTaxed, 7);
                $ratio = bcadd($ratio, '0.0000005', 6);
                if ($this->isValidRatio($ratio)) {
                    return ['ratio' => $ratio, 'source' => self::UNIT_PRICE_SOURCE, 'warnings' => []];
                }
            }
        }

        if ($month->idleDemandItems === [] && $readerRecognizesIdleDemand
            && isset($month->contractedDemandByTimeSlot[$timeSlot], $month->measuredDemandByTimeSlot[$timeSlot])
            && bccomp($month->measuredDemandByTimeSlot[$timeSlot], $month->contractedDemandByTimeSlot[$timeSlot], 3) < 0) {
            return ['ratio' => '1.000000', 'source' => self::FULL_CHARGE_SOURCE, 'warnings' => []];
        }

        return null;
    }

    private function isValidRatio(string $ratio): bool
    {
        return bccomp($ratio, '0', 6) > 0 && bccomp($ratio, '1', 6) <= 0;
    }

    private function pisCofinsIsConsistent(array $idleItems, ExtractedInvoiceItemData $normalItem, string $ratio): bool
    {
        if (bccomp($normalItem->amount, '0', 2) <= 0) {
            return false;
        }

        $normalPercent = bcdiv($normalItem->pisCofinsAmount, $normalItem->amount, 8);
        $expectedIdlePercent = bcdiv($normalPercent, $ratio, 8);
        $hasTaxLine = false;
        foreach ($idleItems as $idleItem) {
            if (bccomp($idleItem->icmsBase, '0', 2) <= 0 || bccomp($idleItem->icmsRate, '0', 4) !== 0) {
                continue;
            }

            if (bccomp($idleItem->amount, '0', 2) <= 0) {
                return false;
            }

            $idlePercent = bcdiv($idleItem->pisCofinsAmount, $idleItem->amount, 8);
            $difference = $this->absolute(bcsub($idlePercent, $expectedIdlePercent, 8));
            if (bccomp($difference, self::PIS_COFINS_TOLERANCE, 8) > 0) {
                return false;
            }

            $hasTaxLine = true;
        }

        return $hasTaxLine;
    }

    /**
     * @param  array<int, array<string, array{ratio: string, source: string, warnings: list<string>}>>  $direct
     * @param  array<int, list<string>>  $slotsByMonth
     * @return array{ratio: string, source: string, warnings: list<string>}|null
     */
    private function nearestObservation(array $months, int $monthIndex, string $timeSlot, array $direct): ?array
    {
        $month = $months[$monthIndex];
        $candidates = [];

        foreach ($months as $candidateMonthIndex => $candidateMonth) {
            foreach ($direct[$candidateMonthIndex] ?? [] as $candidateSlot => $observation) {
                $candidates[] = [
                    'date' => $candidateMonth->referenceMonth,
                    'period' => $candidateMonth->tariffPeriod,
                    'slot' => $candidateSlot,
                    'observation' => $observation,
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        $samePeriod = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['period'] === $month->tariffPeriod));
        $candidate = $this->closestCandidate($samePeriod, $month->referenceMonth, $timeSlot);
        if ($candidate !== null) {
            return $candidate;
        }

        $sameSlot = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['slot'] === $timeSlot));
        $candidate = $this->closestCandidate($sameSlot, $month->referenceMonth, $timeSlot);

        return $candidate ?? $this->closestCandidate($candidates, $month->referenceMonth, $timeSlot);
    }

    /**
     * @param  list<array{date: string, period: string, slot: string, observation: array{ratio: string, source: string, warnings: list<string>}}>  $candidates
     * @return array{ratio: string, source: string, warnings: list<string>}|null
     */
    private function closestCandidate(array $candidates, string $referenceMonth, string $timeSlot): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $left, array $right) use ($referenceMonth, $timeSlot): int {
            $leftDistance = abs($this->monthNumber($left['date']) - $this->monthNumber($referenceMonth));
            $rightDistance = abs($this->monthNumber($right['date']) - $this->monthNumber($referenceMonth));

            return [$leftDistance, $left['slot'] === $timeSlot ? 0 : 1, $left['date'], $left['slot']]
                <=> [$rightDistance, $right['slot'] === $timeSlot ? 0 : 1, $right['date'], $right['slot']];
        });

        return $candidates[0]['observation'];
    }

    /**
     * @param  list<string>  $timeSlots
     * @param  array<string, array{ratio: ?string, source: string, inherited: bool, warnings: list<string>}>  $resolved
     * @return array{array<string, int>, list<string>}
     */
    private function matchIdleRows(DemandMonthData $month, array $timeSlots, array $resolved): array
    {
        $assignments = [];
        $unmatched = [];
        foreach ($month->idleDemandItems as $index => $item) {
            if ($item->timeSlot !== 'not_applicable' && in_array($item->timeSlot, $timeSlots, true)) {
                $assignments[$item->timeSlot] = $index;
            } else {
                $unmatched[] = $index;
            }
        }

        if ($unmatched === []) {
            return [$assignments, []];
        }

        $eligibleSlots = array_values(array_filter($timeSlots, function (string $timeSlot) use ($month, $resolved): bool {
            if (! isset($month->contractedDemandByTimeSlot[$timeSlot], $month->measuredDemandByTimeSlot[$timeSlot])
                || ! isset($month->normalDemandItemsByTimeSlot[$timeSlot])
                || $month->normalDemandItemsByTimeSlot[$timeSlot]->unitPriceTaxed === null
                || ($resolved[$timeSlot]['ratio'] ?? null) === null) {
                return false;
            }

            return bccomp($month->contractedDemandByTimeSlot[$timeSlot], $month->measuredDemandByTimeSlot[$timeSlot], 3) > 0;
        }));

        if (count($unmatched) > count($eligibleSlots)) {
            return [$assignments, [self::MATCHING_WARNING]];
        }

        $solutions = $this->matchingSolutions($month, $unmatched, $eligibleSlots, $resolved);
        if ($solutions === []) {
            return [$assignments, [self::MATCHING_WARNING]];
        }

        usort($solutions, static fn (array $left, array $right): int => bccomp($left['score'], $right['score'], 8));
        $best = $solutions[0];
        $isTie = isset($solutions[1]) && bccomp($best['score'], $solutions[1]['score'], 8) === 0;
        $withinTolerance = true;
        foreach ($best['errors'] as $error) {
            if (bccomp($error, '0.02', 2) > 0) {
                $withinTolerance = false;
                break;
            }
        }

        if ($isTie || ! $withinTolerance) {
            return [$assignments, [self::MATCHING_WARNING]];
        }

        return [$assignments + $best['assignments'], []];
    }

    /**
     * @param  list<int>  $idleIndexes
     * @param  list<string>  $timeSlots
     * @param  array<string, array{ratio: ?string, source: string, inherited: bool, warnings: list<string>}>  $resolved
     * @return list<array{score: string, assignments: array<string, int>, errors: list<string>}>
     */
    private function matchingSolutions(DemandMonthData $month, array $idleIndexes, array $timeSlots, array $resolved): array
    {
        if ($idleIndexes === []) {
            return [['score' => '0', 'assignments' => [], 'errors' => []]];
        }

        $solutions = [];
        $firstIndex = array_shift($idleIndexes);
        foreach ($timeSlots as $slotIndex => $timeSlot) {
            $normal = $month->normalDemandItemsByTimeSlot[$timeSlot];
            $difference = bcsub(
                $month->contractedDemandByTimeSlot[$timeSlot],
                $month->measuredDemandByTimeSlot[$timeSlot],
                3,
            );
            $expectedValue = bcmul(
                bcmul($difference, $normal->unitPriceTaxed ?? '0', 8),
                $resolved[$timeSlot]['ratio'],
                8,
            );
            $expectedValue = bcadd($expectedValue, '0.005', 2);
            $error = $this->absolute(bcsub($month->idleDemandItems[$firstIndex]->amount, $expectedValue, 2));
            $remainingSlots = $timeSlots;
            array_splice($remainingSlots, $slotIndex, 1);

            foreach ($this->matchingSolutions($month, $idleIndexes, $remainingSlots, $resolved) as $tail) {
                $assignments = $tail['assignments'] + [$timeSlot => $firstIndex];
                $errors = array_merge($tail['errors'], [$error]);
                $score = bcadd($tail['score'], bcmul($error, $error, 8), 8);
                $solutions[] = ['score' => $score, 'assignments' => $assignments, 'errors' => $errors];
            }
        }

        return $solutions;
    }

    private function absolute(string $number): string
    {
        return bccomp($number, '0', 8) < 0 ? bcmul($number, '-1', 8) : $number;
    }

    private function monthNumber(string $month): int
    {
        $date = CarbonImmutable::parse($month.'-01');

        return ($date->year * 12) + $date->month;
    }
}
