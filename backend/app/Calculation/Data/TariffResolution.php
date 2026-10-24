<?php

namespace App\Calculation\Data;

use App\Models\Tariff;

final readonly class TariffResolution
{
    /** @param list<string> $warnings */
    public function __construct(
        public ?Tariff $tariff,
        public array $warnings = [],
    ) {}
}
