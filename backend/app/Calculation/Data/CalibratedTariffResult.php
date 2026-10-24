<?php

namespace App\Calculation\Data;

final readonly class CalibratedTariffResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?string $rate,
        public array $warnings = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->rate !== null;
    }
}
