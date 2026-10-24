<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'distributor_id' => ['required_without:distributor_name', 'nullable', 'integer', 'exists:distributors,id'],
            'distributor_name' => ['required_without:distributor_id', 'nullable', 'string', 'max:255'],
            'transformer_power_kva' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'address_details' => ['required', 'string', 'max:5000'],
            'substation_same_address' => ['required', 'boolean'],
            'substation_address' => ['nullable', 'required_if:substation_same_address,false', 'string', 'max:5000'],
            'invoices' => ['required', 'array'],
            'invoices.*' => ['required', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'substation_address.required_if' => 'Informe o endereço da subestação quando ele for diferente do endereço da fatura.',
            'invoices.required' => 'Envie de 6 a 12 faturas em PDF.',
            'invoices.*.file' => 'Cada fatura deve ser um arquivo válido.',
        ];
    }
}
