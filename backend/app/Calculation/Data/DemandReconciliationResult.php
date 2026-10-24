<?php

namespace App\Calculation\Data;

final readonly class DemandReconciliationResult
{
    /** @param list<string> $warnings */
    public function __construct(
        public bool $available,
        public ?bool $matchesInvoice,
        public ?string $calculatedTotal,
        public ?string $invoicedTotal,
        public ?string $currentCost,
        public array $warnings = [],
    ) {}
}
