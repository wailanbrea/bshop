<?php

namespace App\Http\Requests;

use App\Rules\DecimalAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('variants'))) {
            $this->merge(['variants' => array_map(function ($variant) {
                if (! is_array($variant)) {
                    return $variant;
                }
                if (is_string($variant['sku'] ?? null)) {
                    $variant['sku'] = Str::upper(trim($variant['sku']));
                }
                if (is_string($variant['barcode'] ?? null)) {
                    $variant['barcode'] = trim($variant['barcode']) !== '' ? Str::upper(trim($variant['barcode'])) : null;
                }

                return $variant;
            }, $this->input('variants'))]);
        }
    }

    public function rules(): array
    {
        $create = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'product_type' => ['required', Rule::in(['physical', 'service'])], 'unit_code' => ['required', 'string', 'max:20'],
            'track_stock' => ['required', 'boolean', Rule::prohibitedIf($this->input('product_type') === 'service' && $this->boolean('track_stock'))],
            'status' => ['required', Rule::in(['active', 'archived'])],
            'version' => $create ? ['prohibited'] : ['required', 'integer', 'min:0'],
            'variants' => $create ? ['required', 'array', 'min:1', 'max:100'] : ['prohibited'],
            'variants.*.sku' => ['bail', 'required', 'string', 'max:90', 'distinct', 'unique:product_variants,sku'],
            'variants.*.barcode' => ['bail', 'nullable', 'string', 'max:100', 'distinct', 'unique:product_variants,barcode'],
            'variants.*.description' => ['nullable', 'string', 'max:2000'],
            'variants.*.sale_price' => ['required', new DecimalAmount],
            'variants.*.cost_price' => $this->user()?->permits('catalog.costs') ? ['nullable', new DecimalAmount] : ['missing'],
            'variants.*.attribute_value_ids' => ['present', 'array', 'max:12'],
            'variants.*.attribute_value_ids.*' => ['required', 'integer', 'min:1'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
