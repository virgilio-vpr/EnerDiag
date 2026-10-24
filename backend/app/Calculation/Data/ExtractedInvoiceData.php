<?php

namespace App\Calculation\Data;

use Illuminate\Support\Facades\Validator;

final readonly class ExtractedInvoiceData
{
    /**
     * @param  list<ExtractedInvoiceItemData>  $items
     * @param  list<string>  $warnings
     */
    private function __construct(
        public string $referenceMonth,
        public string $consumerUnit,
        public string $subgroup,
        public string $tariffModality,
        public string $totalPaid,
        public array $items,
        public array $warnings,
    ) {}

    /**
     * Validate and normalize data returned by an invoice reader.
     * Decimal values must arrive as strings or integers to avoid binary floats.
     * An omitted item list represents no extracted items; downstream totals are zero.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $data['items'] ??= [];

        $validator = Validator::make($data, [
            'reference_month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'consumer_unit' => ['required', 'string', 'max:50'],
            'subgroup' => ['required', 'string', 'max:10'],
            'tariff_modality' => ['required', 'string', 'max:20'],
            'total_paid' => ['required', 'numeric'],
            'items' => ['present', 'array'],
            'warnings' => ['sometimes', 'array'],
            'warnings.*' => ['string'],
            'items.*.item_type' => ['required', 'string', 'max:50'],
            'items.*.tariff_component' => ['required', 'string', 'max:30'],
            'items.*.time_slot' => ['required', 'string', 'max:30'],
            'items.*.reactive_nature' => ['required', 'string', 'max:30'],
            'items.*.quantity' => ['nullable', 'numeric'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price_taxed' => ['nullable', 'numeric'],
            'items.*.tariff_rate' => ['nullable', 'numeric'],
            'items.*.amount' => ['required', 'numeric'],
            'items.*.pis_cofins_amount' => ['required', 'numeric'],
            'items.*.icms_base' => ['required', 'numeric'],
            'items.*.icms_rate' => ['required', 'numeric'],
            'items.*.icms_amount' => ['required', 'numeric'],
            'items.*.source_label' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($data): void {
            $precisions = [
                'total_paid' => [2, 12],
                'quantity' => [3, 11],
                'unit_price_taxed' => [5, 9],
                'tariff_rate' => [5, 9],
                'amount' => [2, 12],
                'pis_cofins_amount' => [2, 12],
                'icms_base' => [2, 12],
                'icms_rate' => [4, 2],
                'icms_amount' => [2, 12],
            ];

            foreach ($precisions as $field => [$scale, $wholeDigits]) {
                if ($field === 'total_paid') {
                    self::validateDecimal($validator, 'total_paid', $data['total_paid'] ?? null, $scale, $wholeDigits);

                    continue;
                }

                foreach (($data['items'] ?? []) as $index => $item) {
                    self::validateDecimal(
                        $validator,
                        "items.{$index}.{$field}",
                        is_array($item) ? ($item[$field] ?? null) : null,
                        $scale,
                        $wholeDigits,
                    );
                }
            }

            foreach (($data['items'] ?? []) as $index => $item) {
                if (! is_array($item) || ($item['item_type'] ?? null) === 'idle_demand') {
                    continue;
                }

                foreach (['quantity', 'unit_price_taxed'] as $field) {
                    if (($item['item_type'] ?? null) === 'contracted_demand' && $field === 'unit_price_taxed') {
                        continue;
                    }

                    if (! array_key_exists($field, $item) || $item[$field] === null) {
                        $validator->errors()->add("items.{$index}.{$field}", 'Este campo é obrigatório para itens que não sejam demanda ociosa.');
                    }
                }
            }
        });

        $validated = $validator->validate();

        return new self(
            referenceMonth: $validated['reference_month'],
            consumerUnit: $validated['consumer_unit'],
            subgroup: $validated['subgroup'],
            tariffModality: $validated['tariff_modality'],
            totalPaid: (string) $validated['total_paid'],
            items: array_map(
                static fn (array $item): ExtractedInvoiceItemData => ExtractedInvoiceItemData::fromArray($item),
                $validated['items'],
            ),
            warnings: $validated['warnings'] ?? [],
        );
    }

    private static function validateDecimal(mixed $validator, string $key, mixed $value, int $scale, int $wholeDigits): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) && ! is_int($value)) {
            $validator->errors()->add($key, 'O valor deve ser decimal em texto, sem ponto flutuante.');

            return;
        }

        $decimal = (string) $value;
        if (preg_match('/^-?(\d+)(?:\.(\d+))?$/D', $decimal, $matches) !== 1
            || (isset($matches[2]) && strlen($matches[2]) > $scale)
            || strlen(ltrim($matches[1] ?? '', '0')) > $wholeDigits) {
            $validator->errors()->add($key, "O valor excede a precisão decimal permitida ({$wholeDigits} dígitos inteiros e {$scale} decimais).");
        }
    }
}
