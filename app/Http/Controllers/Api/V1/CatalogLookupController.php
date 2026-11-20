<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Http\Controllers\Controller;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\BusinessProfile;
use App\Models\Category;
use App\Models\ProductAttribute;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogLookupController extends Controller
{
    private const MODELS = ['categories' => Category::class, 'brands' => Brand::class, 'attributes' => ProductAttribute::class, 'values' => AttributeValue::class];

    public function options(Request $request)
    {
        $manage = $request->user()->permits('catalog.manage');
        $storeId = $request->integer('store_id') ?: null;
        $usedOnly = $request->boolean('used_only');

        $categoriesQuery = Category::when(! $manage || $usedOnly, fn ($q) => $q->where('is_active', true));
        if ($storeId !== null && $usedOnly) {
            $categoriesQuery->whereExists(function ($q) use ($storeId) {
                $q->selectRaw('1')
                    ->from('products')
                    ->whereColumn('products.category_id', 'categories.id')
                    ->where('products.store_id', $storeId)
                    ->where('products.status', 'active')
                    ->whereNull('products.deleted_at');
            });
        }

        return response()->json(['data' => [
            'categories' => $categoriesQuery->orderBy('name')->get(),
            'brands' => Brand::when(! $manage, fn ($q) => $q->where('is_active', true))->orderBy('name')->get(),
            'attributes' => ProductAttribute::when(! $manage, fn ($q) => $q->where('is_active', true))->with(['values' => fn ($q) => $q->when(! $manage, fn ($v) => $v->where('is_active', true))->orderBy('value')])->orderBy('name')->get(),
        ]]);
    }

    public function create(Request $request, AuditTrailService $audit)
    {
        return $this->save($request, $audit);
    }

    public function update(Request $request, AuditTrailService $audit)
    {
        return $this->save($request, $audit, (int) $request->route('record'));
    }

    private function save(Request $request, AuditTrailService $audit, ?int $id = null)
    {
        $kind = $request->route('kind');
        $model = self::MODELS[$kind] ?? abort(404);
        $field = $kind === 'values' ? 'value' : 'name';
        if (is_string($request->input($field))) {
            $request->merge([$field => trim($request->input($field))]);
        }
        $rules = [$field => ['required', 'string', 'max:'.match ($kind) {
            'attributes' => 100, 'values' => 120, default => 130
        }], 'is_active' => ['required', 'boolean']];
        if ($kind === 'categories') {
            $rules['parent_id'] = ['nullable', 'integer', 'exists:categories,id'];
        }
        if ($kind === 'values') {
            $rules['attribute_id'] = $id ? ['missing'] : ['required', 'integer', 'exists:product_attributes,id'];
        }
        $data = $request->validate($rules);
        try {
            $record = DB::transaction(function () use ($data, $id, $model, $kind, $field, $audit, $request) {
                BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
                abort_unless($request->user()->fresh()->permits('catalog.manage'), 403);
                $record = $id ? $model::findOrFail($id) : new $model;
                if ($kind === 'categories') {
                    if ($id && ! array_key_exists('parent_id', $data)) {
                        $data['parent_id'] = $record->parent_id;
                    }
                    $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
                    $visited = [];
                    while ($parent) {
                        if ($parent === $record->id || isset($visited[$parent])) {
                            throw ValidationException::withMessages(['parent_id' => 'La categoría no puede depender de sí misma o de sus descendientes.']);
                        }
                        $visited[$parent] = true;
                        $parent = Category::findOrFail($parent)->parent_id;
                    }
                    if (! $id) {
                        $data['slug'] = (Str::slug($data['name']) ?: 'categoria').'-'.Str::lower(Str::random(8));
                    }
                }
                if ($kind === 'values' && ! $id && ! ProductAttribute::whereKey($data['attribute_id'])->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['attribute_id' => 'El atributo está inactivo.']);
                }
                $duplicate = $model::whereRaw('LOWER('.$field.') = ?', [mb_strtolower($data[$field])])->when($id, fn ($q) => $q->where('id', '!=', $id));
                if ($kind === 'categories') {
                    $duplicate->where('parent_id', $data['parent_id'] ?? null);
                }
                if ($kind === 'values') {
                    $duplicate->where('attribute_id', $data['attribute_id'] ?? $record->attribute_id);
                }
                if ($duplicate->exists()) {
                    throw ValidationException::withMessages([$field => 'Ya existe una opción con ese nombre.']);
                }
                $before = $id ? $record->attributesToArray() : null;
                $record->fill($data)->save();
                $audit->record('catalog.'.$kind.($id ? '.updated' : '.created'), $record, $before, actor: $request->user());

                return $record->refresh();
            });

            return response()->json(['data' => $record], $id ? 200 : 201);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => 'Ya existe una opción equivalente.']);
        }
    }
}
