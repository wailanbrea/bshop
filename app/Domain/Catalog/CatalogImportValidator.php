<?php

namespace App\Domain\Catalog;

use App\Http\Requests\ProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CatalogImportValidator
{
    public function __construct(private VariantCombinationService $combinations) {}

    public function validate(array $payload, User $actor): array
    {
        $rows = [];
        foreach ($payload['rows'] as $row) {
            $row['errors'] = $row['source_errors'];
            $rows[$row['row']] = $row;
        }
        $add = function (int $line, string $field, string $message) use (&$rows): void {
            $rows[$line]['errors'][] = compact('field', 'message');
        };
        foreach ($payload['groups'] as $group) {
            $request = new ProductRequest;
            $request->setMethod('POST');
            $request->replace($group['data']);
            $request->setUserResolver(fn () => $actor);
            $validator = Validator::make($request->all(), $request->rules(), [
                'required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.',
                'integer' => 'El campo :attribute debe ser un entero válido.', 'boolean' => 'El campo :attribute debe ser 1/0, true/false o sí/no.',
                'exists' => 'La referencia de :attribute no existe.', 'in' => 'El valor de :attribute no es válido.',
                'max' => 'El campo :attribute supera el máximo permitido (:max).', 'missing' => 'No tienes permiso para importar :attribute.',
                'track_stock.prohibited' => 'Un servicio no puede controlar existencias.',
                'variants.*.sku.unique' => 'El SKU ya existe en el catálogo.', 'variants.*.barcode.unique' => 'El código de barras ya existe en el catálogo.',
                'distinct' => 'El valor de :attribute se repite en el grupo.',
            ], ['name' => 'nombre', 'variants.*.sku' => 'SKU', 'variants.*.barcode' => 'código de barras', 'variants.*.sale_price' => 'precio', 'variants.*.cost_price' => 'costo']);
            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $lines = $group['rows'];
                    $displayField = $field;
                    if (preg_match('/^variants\.(\d+)\.(.+)$/', $field, $match)) {
                        $lines = [$group['rows'][(int) $match[1]]];
                        $displayField = $match[2];
                    }
                    foreach ($lines as $line) {
                        foreach ($messages as $message) {
                            $add($line, $displayField, $message);
                        }
                    }
                }

                continue;
            }
            foreach (['category_id' => Category::class, 'brand_id' => Brand::class] as $field => $model) {
                $id = $group['data'][$field] ?? null;
                if ($id && ! $model::whereKey($id)->where('is_active', true)->exists()) {
                    foreach ($group['rows'] as $line) {
                        $add($line, $field, 'La referencia está inactiva.');
                    }
                }
            }
            $seen = [];
            $attributeSet = null;
            foreach ($group['data']['variants'] as $index => $variant) {
                $line = $group['rows'][$index];
                try {
                    $combination = $this->combinations->resolve($variant['attribute_value_ids']);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $add($line, $field, $message);
                        }
                    }

                    continue;
                }
                if (isset($seen[$combination['key']])) {
                    $message = 'Esta combinación de atributos se repite dentro del grupo.';
                    $add($line, 'attribute_value_ids', $message);
                    $add($seen[$combination['key']], 'attribute_value_ids', $message);
                }
                $seen[$combination['key']] = $line;
                if ($variant['is_active']) {
                    $attributes = array_keys($combination['values']);
                    if ($attributeSet !== null && $attributeSet !== $attributes) {
                        foreach ($group['rows'] as $groupLine) {
                            $add($groupLine, 'attribute_value_ids', 'Las variantes activas del grupo deben usar los mismos atributos.');
                        }
                    }
                    $attributeSet = $attributes;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['errors'] = array_values(array_unique($row['errors'], SORT_REGULAR));
        }
        unset($row);
        $payload['rows'] = array_values($rows);

        return $payload;
    }
}
