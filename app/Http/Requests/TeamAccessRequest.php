<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeamAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'], 'permissions' => ['prohibited'],
            'is_active' => ['prohibited'], 'password' => ['prohibited'],
            'email' => $this->isMethod('post') ? ['required', 'email', 'max:190'] : ['prohibited'],
            'role_id' => ['required', 'integer', 'min:1'],
            'store_ids' => ['required', 'array', 'min:1', 'max:100'],
            'store_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'status' => $this->isMethod('patch') ? ['required', Rule::in(['active', 'suspended'])] : ['prohibited'],
        ];
    }
}
