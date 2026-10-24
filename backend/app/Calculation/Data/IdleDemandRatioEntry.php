<?php

namespace App\Calculation\Data;

final readonly class IdleDemandRatioEntry
{
    /** @param list<string> $warnings */
    public function __construct(
        public string $referenceMonth,
        public string $timeSlot,
        public ?string $ratio,
        public string $source,
        public bool $inherited,
        public ?int $idleItemIndex,
        public array $warnings = [],
    ) {}
}
