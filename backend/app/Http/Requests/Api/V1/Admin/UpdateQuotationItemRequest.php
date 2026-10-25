<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuotationItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'item_key' => ['required', 'string', 'alpha_dash', 'max:255', Rule::unique('quotation_base', 'item_key')->ignore($this->route('id'))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:30'],
            'unit_price' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ];
    }
}
