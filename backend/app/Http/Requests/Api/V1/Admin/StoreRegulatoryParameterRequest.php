<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegulatoryParameterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'key' => ['required', Rule::in([
                'overrun_tolerance', 'overrun_multiplier', 'reference_power_factor', 'idle_demand_discount_fallback',
            ])],
            'value' => ['required', 'numeric', 'decimal:0,5'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }
}
