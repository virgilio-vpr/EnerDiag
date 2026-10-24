<?php

namespace App\Calculation\Data;

final readonly class ExtractedInvoiceItemData
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public string $itemType,
        public string $tariffComponent,
        public string $timeSlot,
        public string $reactiveNature,
        public ?string $quantity,
        public ?string $unit,
        public ?string $unitPriceTaxed,
        public ?string $tariffRate,
        public string $amount,
        public string $pisCofinsAmount,
        public string $icmsBase,
        public string $icmsRate,
        public string $icmsAmount,
        public ?string $sourceLabel,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            itemType: $data['item_type'],
            tariffComponent: $data['tariff_component'],
            timeSlot: $data['time_slot'],
            reactiveNature: $data['reactive_nature'],
            quantity: self::nullableDecimal($data['quantity'] ?? null),
            unit: $data['unit'] ?? null,
            unitPriceTaxed: self::nullableDecimal($data['unit_price_taxed'] ?? null),
            tariffRate: self::nullableDecimal($data['tariff_rate'] ?? null),
            amount: (string) $data['amount'],
            pisCofinsAmount: (string) $data['pis_cofins_amount'],
            icmsBase: (string) $data['icms_base'],
            icmsRate: (string) $data['icms_rate'],
            icmsAmount: (string) $data['icms_amount'],
            sourceLabel: $data['source_label'] ?? null,
        );
    }

    private static function nullableDecimal(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
