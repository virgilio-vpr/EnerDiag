<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'company_name' => ['sometimes', 'required', 'string', 'max:255'],
            'cnpj' => ['sometimes', 'nullable', 'string', 'max:18'],
            'distributor_id' => ['sometimes', 'nullable', 'integer', 'exists:distributors,id'],
            'transformer_power_kva' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'address_details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'substation_same_address' => ['sometimes', 'boolean'],
            'substation_address' => ['sometimes', 'nullable', 'required_if:substation_same_address,false', 'string', 'max:5000'],
        ];
    }

    /**
     * Get localized validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'substation_address.required_if' => 'Informe o endereço da subestação quando ele for diferente do endereço da fatura.',
        ];
    }
}
