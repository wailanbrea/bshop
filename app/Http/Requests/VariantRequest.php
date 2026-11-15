<?php

namespace App\Http\Requests;

use App\Rules\DecimalAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => Str::upper(trim($this->input('sku')))]);
        }
        if (is_string($this->input('barcode'))) {
            $this->merge(['barcode' => trim($this->input('barcode')) !== '' ? Str::upper(trim($this->input('barcode'))) : null]);
        }
    }

    public function rules(): array
    {
        $update = $this->isMethod('patch');
        $sku = Rule::unique('product_variants', 'sku');
        $barcode = Rule::unique('product_variants', 'barcode');
        if ($update) {
            $sku->ignore((int) $this->route('variant'));
            $barcode->ignore((int) $this->route('variant'));
        }

        return [
            'sku' => ['bail', 'required', 'string', 'max:90', $sku], 'barcode' => ['bail', 'nullable', 'string', 'max:100', $barcode],
            'sale_price' => ['required', new DecimalAmount], 'cost_price' => $this->user()?->permits('catalog.costs') ? ['nullable', new DecimalAmount] : ['missing'],
            'is_active' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:0'],
            'attribute_value_ids' => $update ? ['missing'] : ['present', 'array', 'max:12'],
            'attribute_value_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'product_id' => ['missing'], 'combination_key' => ['missing'],
        ];
    }
}
