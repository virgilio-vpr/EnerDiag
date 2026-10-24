<?php

namespace App\Calculation\Data;

final readonly class TariffMigrationResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public bool $available,
        public ?string $selectedModality,
        public ?string $blueCost,
        public ?string $greenCost,
        public ?string $savings,
        public ?string $message = null,
        public array $warnings = [],
    ) {}
}
