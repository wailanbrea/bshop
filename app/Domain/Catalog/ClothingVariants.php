<?php

namespace App\Domain\Catalog;

use App\Domain\Business\AuditTrailService;
use App\Models\AttributeValue;
use App\Models\BusinessProfile;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Resolve reusable catalogue values inside the product creation transaction. */
final class ClothingVariants
{
    public function __construct(private AuditTrailService $audit) {}

    public function resolve(array $rows, User $actor): array
    {
        BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
        $hasAnyColor = collect($rows)->contains(fn ($r) => trim((string) ($r['color'] ?? '')) !== '');
        $hasAnySize = collect($rows)->contains(fn ($r) => trim((string) ($r['size'] ?? '')) !== '');
        $color = $hasAnyColor ? $this->attribute('Color', $actor) : null;
        $size = $hasAnySize ? $this->attribute('Talla', $actor) : null;
        $seen = [];
        $variants = [];
        foreach ($rows as $row) {
            $colorText = trim((string) ($row['color'] ?? ''));
            $sizeText = trim((string) ($row['size'] ?? ''));
            $attrIds = [];
            $comboParts = [];
            if ($colorText !== '' && $color !== null) {
                $colorValue = $this->value($color, $colorText, $actor);
                $attrIds[] = $colorValue->id;
                $comboParts[] = 'c:'.$colorValue->id;
            }
            if ($sizeText !== '' && $size !== null) {
                $sizeValue = $this->value($size, $sizeText, $actor);
                $attrIds[] = $sizeValue->id;
                $comboParts[] = 's:'.$sizeValue->id;
            }
            $combination = $comboParts !== [] ? implode(':', $comboParts) : 'general';
            if (isset($seen[$combination])) {
                throw ValidationException::withMessages(['clothing_variants' => 'No repitas la misma combinación.']);
            }
            $seen[$combination] = true;
            $variants[] = [...array_intersect_key($row, array_flip(['sku', 'barcode', 'description', 'sale_price', 'cost_price', 'is_active'])), 'attribute_value_ids' => $attrIds];
        }

        return $variants;
    }

    private function attribute(string $name, User $actor): ProductAttribute
    {
        // Current reads are required after waiting for the shared catalogue lock in MySQL.
        $matches = ProductAttribute::orderBy('id')->lockForUpdate()->get()->filter(fn ($item) => mb_strtolower(trim($item->name)) === mb_strtolower($name));
        if ($matches->count() > 1 || ($matches->isNotEmpty() && ! $matches->first()->is_active)) {
            throw ValidationException::withMessages(['clothing_variants' => "Revisa el atributo $name en Organizar catálogo: está inactivo o duplicado."]);
        }
        if ($matches->isNotEmpty()) {
            return $matches->first();
        }
        $attribute = ProductAttribute::create(['name' => $name, 'is_active' => true]);
        $this->audit->record('catalog.attributes.created', $attribute, actor: $actor);

        return $attribute;
    }

    private function value(ProductAttribute $attribute, string $name, User $actor): AttributeValue
    {
        if ($name === '') {
            throw ValidationException::withMessages(['clothing_variants' => 'Indica el color y la talla de cada combinación.']);
        }
        $matches = $attribute->values()->orderBy('id')->lockForUpdate()->get()->filter(fn ($item) => mb_strtolower(trim($item->value)) === mb_strtolower($name));
        if ($matches->count() > 1 || ($matches->isNotEmpty() && ! $matches->first()->is_active)) {
            throw ValidationException::withMessages(['clothing_variants' => "Revisa el valor $name en Organizar catálogo: está inactivo o duplicado."]);
        }
        if ($matches->isNotEmpty()) {
            return $matches->first();
        }
        $value = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => $name, 'is_active' => true]);
        $this->audit->record('catalog.values.created', $value, actor: $actor);

        return $value;
    }
}
