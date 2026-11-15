<?php

namespace App\Domain\Catalog;

use App\Models\AttributeValue;
use Illuminate\Validation\ValidationException;

final class VariantCombinationService
{
    public function resolve(array $ids, bool $lock = false): array
    {
        $values = AttributeValue::with(['attribute' => fn ($query) => $query->when($lock, fn ($builder) => $builder->sharedLock())])->whereIn('id', $ids)->orderBy('id')->when($lock, fn ($query) => $query->sharedLock())->get();
        if ($values->count() !== count($ids) || $values->contains(fn ($value) => ! $value->is_active || ! $value->attribute->is_active)) {
            throw ValidationException::withMessages(['attribute_value_ids' => 'Selecciona valores de atributos activos y existentes.']);
        }
        $map = [];
        foreach ($values as $value) {
            if (isset($map[$value->attribute_id])) {
                throw ValidationException::withMessages(['attribute_value_ids' => 'Selecciona sólo un valor por atributo.']);
            }
            $map[$value->attribute_id] = $value->id;
        }
        ksort($map, SORT_NUMERIC);
        $canonical = implode('|', array_map(fn ($key) => $key.':'.$map[$key], array_keys($map)));

        return ['key' => $map === [] ? 'default' : hash('sha256', $canonical), 'values' => $map];
    }
}
