<?php

namespace App\Calculation\Services;

final class IdleDemandTariffMatcher
{
    /**
     * @param  list<array<string, mixed>>  $idleRows
     * @param  array<string, array<string, mixed>>  $normalBySlot
     * @return list<string>|null
     */
    public function match(array $idleRows, array $normalBySlot): ?array
    {
        if ($idleRows === [] || count($idleRows) > count($normalBySlot)) {
            return null;
        }

        $assignment = [];
        foreach ($idleRows as $row) {
            $candidates = [];
            foreach ($normalBySlot as $slot => $normal) {
                if ($this->sameTariff($row, $normal)) {
                    $candidates[] = $slot;
                }
            }
            if (count($candidates) !== 1 || in_array($candidates[0], $assignment, true)) {
                $assignment = [];
                break;
            }
            $assignment[] = $candidates[0];
        }
        if (count($assignment) === count($idleRows)) {
            return $assignment;
        }

        if (count($idleRows) !== 2 || ! isset($normalBySlot['peak'], $normalBySlot['off_peak'])) {
            return null;
        }

        foreach (['tariff_rate', 'unit_price_taxed'] as $field) {
            $first = $idleRows[0][$field] ?? null;
            $second = $idleRows[1][$field] ?? null;
            if (! $this->positive($first) || ! $this->positive($second) || bccomp($first, $second, 5) === 0) {
                continue;
            }

            return bccomp($first, $second, 5) > 0 ? ['peak', 'off_peak'] : ['off_peak', 'peak'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $idle
     * @param  array<string, mixed>  $normal
     */
    private function sameTariff(array $idle, array $normal): bool
    {
        $idleTariff = $idle['tariff_rate'] ?? null;
        $normalTariff = $normal['tariff_rate'] ?? null;
        if ($this->positive($idleTariff) && $this->positive($normalTariff)) {
            return bccomp($idleTariff, $normalTariff, 5) === 0;
        }

        $idlePrice = $idle['unit_price_taxed'] ?? null;
        $normalPrice = $normal['unit_price_taxed'] ?? null;
        $icms = $normal['icms_rate'] ?? null;
        if (! $this->positive($idlePrice) || ! $this->positive($normalPrice) || ! is_string($icms)
            || bccomp($icms, '0', 4) < 0 || bccomp($icms, '1', 4) >= 0) {
            return false;
        }

        $expected = bcmul($normalPrice, bcsub('1', $icms, 6), 8);
        $difference = bcsub($idlePrice, $expected, 8);
        if (bccomp($difference, '0', 8) < 0) {
            $difference = bcmul($difference, '-1', 8);
        }

        return bccomp(bcdiv($difference, $expected, 8), '0.005', 8) <= 0;
    }

    private function positive(mixed $value): bool
    {
        return is_string($value) && is_numeric($value) && bccomp($value, '0', 5) > 0;
    }
}
