<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'technical_responsible_name' => ['required', 'string', 'max:255'],
            'crea_registration' => ['required', 'string', 'max:100', 'regex:/^CREA-SP\s+\S.+$/i'],
            'art_number' => ['required', 'string', 'max:100'],
        ];
    }
}
