<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'legal_name' => ['nullable', 'string', 'max:200'], 'timezone' => ['required', 'timezone:all', 'max:80'], 'default_currency' => ['prohibited'], 'id' => ['prohibited']];
    }
}
