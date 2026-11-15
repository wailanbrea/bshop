<?php

namespace App\Http\Requests;

use App\Domain\Business\StoreContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $context = app(StoreContext::class);
        $unique = Rule::unique('stores', 'code');
        if ($this->isMethod('patch')) {
            $unique->ignore($context->store()->id);
        }

        return [
            'business_id' => ['prohibited'],
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', $unique],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
