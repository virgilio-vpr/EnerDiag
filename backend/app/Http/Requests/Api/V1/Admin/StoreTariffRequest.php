<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'distributor_id' => ['required', 'integer', Rule::exists('distributors', 'id')->where('active', true)],
            'subgroup' => ['required', Rule::in(['A4', 'AS'])],
            'tariff_modality' => ['required', Rule::in(['blue', 'green'])],
            'time_slot' => ['required', Rule::in(['peak', 'off_peak', 'single'])],
            'component' => ['required', Rule::in(['demand', 'energy_te', 'energy_tusd'])],
            'rate' => ['required', 'numeric', 'decimal:0,5', 'gt:0'],
            'includes_taxes' => ['required', 'boolean'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
