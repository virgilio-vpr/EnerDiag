<?php

namespace App\Calculation\Services;

use App\Models\RegulatoryParameter;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class RegulatoryParameterService
{
    public function resolve(string $key, DateTimeInterface|string $date): ?RegulatoryParameter
    {
        $referenceDate = $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)->toDateString()
            : CarbonImmutable::parse($date)->toDateString();

        return RegulatoryParameter::query()
            ->where('key', $key)
            ->whereNull('state')
            ->whereDate('valid_from', '<=', $referenceDate)
            ->where(function ($query) use ($referenceDate): void {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $referenceDate);
            })
            ->orderByDesc('valid_from')
            ->first();
    }
}
