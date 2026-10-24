<?php

namespace App\Calculation\Data;

final readonly class CalibrationFactorResult
{
    /**
     * @param  array<int, string>  $itemFactors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $factor,
        public array $itemFactors,
        public array $warnings = [],
    ) {}

    public function isAvailable(): bool
    {
        return $this->factor !== null;
    }
}
