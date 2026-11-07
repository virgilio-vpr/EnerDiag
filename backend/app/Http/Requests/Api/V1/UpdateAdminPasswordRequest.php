<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateAdminPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()->uncompromised()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Informe sua senha atual.',
            'current_password.current_password' => 'A senha atual está incorreta.',
            'password.required' => 'Informe a nova senha.',
            'password.string' => 'Informe uma senha válida.',
            'password.confirmed' => 'A confirmação da nova senha não corresponde.',
            'password.min' => 'A nova senha deve ter pelo menos 14 caracteres.',
            'password.password.mixed' => 'A nova senha deve conter letras maiúsculas e minúsculas.',
            'password.password.numbers' => 'A nova senha deve conter números.',
            'password.password.symbols' => 'A nova senha deve conter símbolos.',
            'password.password.uncompromised' => 'Escolha uma senha que não tenha sido exposta em vazamentos de dados.',
        ];
    }
}
