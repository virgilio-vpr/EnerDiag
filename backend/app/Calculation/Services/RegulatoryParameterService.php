<?php

namespace App\Calculation\Services;

use App\Models\RegulatoryParameter;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class RegulatoryParameterService
{
    /** @var array<string, RegulatoryParameter|null> */
    private array $resolvedParameters = [];

    public function resolve(string $key, DateTimeInterface|string $date): ?RegulatoryParameter
    {
        $referenceDate = $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)->toDateString()
            : CarbonImmutable::parse($date)->toDateString();

        $cacheKey = $key.'|'.$referenceDate;
        if (array_key_exists($cacheKey, $this->resolvedParameters)) {
            return $this->resolvedParameters[$cacheKey];
        }

        $this->resolvedParameters[$cacheKey] = RegulatoryParameter::query()
            ->where('key', $key)
            ->whereNull('state')
            ->whereDate('valid_from', '<=', $referenceDate)
            ->where(function ($query) use ($referenceDate): void {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $referenceDate);
            })
            ->orderByDesc('valid_from')
            ->first();

        return $this->resolvedParameters[$cacheKey];
    }
}
