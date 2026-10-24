<?php

namespace App\Calculation\Data;

final readonly class IdleDemandRatioResolution
{
    /** @param list<IdleDemandRatioEntry> $entries */
    public function __construct(public array $entries) {}

    /** @return list<string> */
    public function warnings(): array
    {
        $warnings = [];

        foreach ($this->entries as $entry) {
            $warnings = array_merge($warnings, $entry->warnings);
        }

        return array_values(array_unique($warnings));
    }

    public function forMonthAndTimeSlot(string $referenceMonth, string $timeSlot): ?IdleDemandRatioEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->referenceMonth === $referenceMonth && $entry->timeSlot === $timeSlot) {
                return $entry;
            }
        }

        return null;
    }
}
