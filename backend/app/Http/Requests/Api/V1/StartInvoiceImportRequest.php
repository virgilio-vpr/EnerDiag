<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StartInvoiceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'distributor_id' => ['required', 'integer', 'exists:distributors,id'],
            'transformer_power_kva' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'address_details' => ['required', 'string', 'max:5000'],
            'substation_same_address' => ['required', 'boolean'],
            'substation_address' => ['nullable', 'required_if:substation_same_address,false', 'string', 'max:5000'],
        ];
    }
}
