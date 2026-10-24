<?php

namespace App\Calculation\Services;

use App\Calculation\Data\TariffResolution;
use App\Models\Tariff;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class TariffService
{
    public const string MISSING_TARIFF_WARNING = 'tariff_unavailable';

    public function resolve(
        int $distributorId,
        string $subgroup,
        string $tariffModality,
        string $component,
        string $timeSlot,
        DateTimeInterface|string $date,
    ): TariffResolution {
        $referenceDate = $date instanceof DateTimeInterface
            ? CarbonImmutable::instance($date)->toDateString()
            : CarbonImmutable::parse($date)->toDateString();

        $tariff = Tariff::query()
            ->where('distributor_id', $distributorId)
            ->where('subgroup', $subgroup)
            ->where('tariff_modality', $tariffModality)
            ->where('component', $component)
            ->where('time_slot', $timeSlot)
            ->whereDate('valid_from', '<=', $referenceDate)
            ->where(function ($query) use ($referenceDate): void {
                $query->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $referenceDate);
            })
            ->orderByDesc('valid_from')
            ->first();

        if ($tariff === null) {
            return new TariffResolution(null, [self::MISSING_TARIFF_WARNING]);
        }

        return new TariffResolution($tariff);
    }
}
