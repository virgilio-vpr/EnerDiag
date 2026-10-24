<?php

namespace App\Services;

use App\Models\Tariff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminTariffService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Tariff
    {
        return DB::transaction(function () use ($attributes): Tariff {
            $this->ensureNoOverlap($attributes);

            return Tariff::query()->create($attributes + ['source' => 'manual']);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createVersion(Tariff $tariff, array $attributes): Tariff
    {
        return DB::transaction(function () use ($tariff, $attributes): Tariff {
            $tariff = Tariff::query()->lockForUpdate()->findOrFail($tariff->id);
            $validFrom = Carbon::parse($attributes['valid_from']);

            if ($validFrom->lessThanOrEqualTo($tariff->valid_from)) {
                throw ValidationException::withMessages(['valid_from' => 'A nova vigência deve começar depois da versão existente.']);
            }

            if ($tariff->valid_to === null) {
                $tariff->update(['valid_to' => $validFrom->copy()->subDay()->toDateString()]);
            } elseif ($validFrom->lessThanOrEqualTo($tariff->valid_to)) {
                throw ValidationException::withMessages(['valid_from' => 'A nova vigência deve começar depois da vigência existente.']);
            }

            $this->ensureNoOverlap($attributes);

            return Tariff::query()->create($attributes + ['source' => 'manual']);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function ensureNoOverlap(array $attributes, ?int $exceptId = null): void
    {
        $overlap = Tariff::query()
            ->where('distributor_id', $attributes['distributor_id'])
            ->where('subgroup', $attributes['subgroup'])
            ->where('tariff_modality', $attributes['tariff_modality'])
            ->where('time_slot', $attributes['time_slot'])
            ->where('component', $attributes['component'])
            ->where('includes_taxes', $attributes['includes_taxes'])
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->whereDate('valid_from', '<=', $attributes['valid_to'] ?? '9999-12-31')
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $attributes['valid_from']))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['valid_from' => 'Já existe uma tarifa com essa base tributária em período sobreposto.']);
        }
    }
}
