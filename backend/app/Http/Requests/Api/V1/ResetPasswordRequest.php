<?php

namespace App\Http\Requests\Api\V1;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
        $password = Password::min(10)->letters()->numbers()->uncompromised();
        $user = is_string($this->input('email')) && is_string($this->input('token'))
            ? User::query()->where('email', $this->input('email'))->first()
            : null;
        if ($user?->role === 'admin' && PasswordBroker::broker()->tokenExists($user, $this->input('token'))) {
            $password = Password::min(14)->mixedCase()->numbers()->symbols()->uncompromised();
        }

        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', $password],
        ];
    }
}
