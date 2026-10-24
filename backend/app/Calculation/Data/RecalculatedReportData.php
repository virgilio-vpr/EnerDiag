<?php

namespace App\Calculation\Data;

final readonly class RecalculatedReportData
{
    /**
     * @param  array<string, mixed>  $calculatedAttributes  Calculated report fields, excluding explanatory text and recommendations.
     * @param  list<int>  $tariffVersionIds
     * @param  list<int>  $regulatoryParameterVersionIds
     */
    public function __construct(
        public array $calculatedAttributes,
        public string $algorithmVersion,
        public array $tariffVersionIds,
        public array $regulatoryParameterVersionIds,
    ) {}
}
