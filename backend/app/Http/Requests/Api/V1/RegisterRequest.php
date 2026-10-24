<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'distributor_id' => ['nullable', 'integer', 'exists:distributors,id'],
            'transformer_power_kva' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'address_details' => ['nullable', 'string', 'max:5000'],
            'substation_same_address' => ['required', 'boolean'],
            'substation_address' => ['nullable', 'required_if:substation_same_address,false', 'string', 'max:5000'],
            'terms_accepted' => ['accepted'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()->uncompromised()],
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
            'email.unique' => 'Este e-mail já está cadastrado.',
            'email.email' => 'Informe um endereço de e-mail válido.',
            'password.confirmed' => 'A confirmação da senha não corresponde.',
            'terms_accepted.accepted' => 'É necessário aceitar os Termos de Uso e a Política de Privacidade.',
            'substation_address.required_if' => 'Informe o endereço da subestação quando ele for diferente do endereço da fatura.',
        ];
    }
}
