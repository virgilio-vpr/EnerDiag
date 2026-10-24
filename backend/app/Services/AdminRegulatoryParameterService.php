<?php

namespace App\Services;

use App\Models\RegulatoryParameter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminRegulatoryParameterService
{
    /** @param array{key: string, value: string|int|float, valid_from: string, valid_to?: ?string} $attributes */
    public function createVersion(array $attributes): RegulatoryParameter
    {
        $value = (string) $attributes['value'];
        $this->validateValue($attributes['key'], $value);

        return DB::transaction(function () use ($attributes, $value): RegulatoryParameter {
            $latest = RegulatoryParameter::query()
                ->where('key', $attributes['key'])
                ->whereNull('state')
                ->orderByDesc('valid_from')
                ->lockForUpdate()
                ->first();

            $validFrom = Carbon::parse($attributes['valid_from']);
            if ($latest !== null && $validFrom->lessThanOrEqualTo($latest->valid_from)) {
                throw ValidationException::withMessages(['valid_from' => 'A nova vigência deve começar depois da versão existente.']);
            }

            if ($latest?->valid_to !== null && $validFrom->lessThanOrEqualTo($latest->valid_to)) {
                throw ValidationException::withMessages(['valid_from' => 'A nova vigência deve começar depois da vigência existente.']);
            }

            if ($latest !== null && $latest->valid_to === null) {
                $latest->update(['valid_to' => $validFrom->copy()->subDay()->toDateString()]);
            }

            return RegulatoryParameter::query()->create([
                'key' => $attributes['key'],
                'value' => $value,
                'state' => null,
                'valid_from' => $validFrom->toDateString(),
                'valid_to' => $attributes['valid_to'] ?? null,
            ]);
        });
    }

    private function validateValue(string $key, string $value): void
    {
        $valid = match ($key) {
            'overrun_tolerance' => bccomp($value, '0', 5) >= 0 && bccomp($value, '1', 5) < 0,
            'overrun_multiplier' => bccomp($value, '0', 5) > 0,
            'reference_power_factor' => bccomp($value, '0', 5) > 0 && bccomp($value, '1', 5) <= 0,
            'idle_demand_discount_fallback' => bccomp($value, '0', 5) >= 0 && bccomp($value, '1', 5) <= 0,
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['value' => 'O valor está fora da faixa permitida para este parâmetro.']);
        }
    }
}
