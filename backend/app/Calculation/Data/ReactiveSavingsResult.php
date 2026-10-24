<?php

namespace App\Calculation\Data;

final readonly class ReactiveSavingsResult
{
    /**
     * @param  array<string, array{reactive_energy: string, reactive_demand: string, total: string}>  $byMonth
     */
    public function __construct(
        public string $totalSavings,
        public string $reactiveEnergySavings,
        public string $reactiveDemandSavings,
        public bool $occurred,
        public ?string $message,
        public array $byMonth,
    ) {}
}
