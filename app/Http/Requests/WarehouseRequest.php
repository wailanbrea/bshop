<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_id' => ['prohibited'], 'store_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:160'],
            'is_default' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
