<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\StoreContext;
use App\Domain\Payments\PaymentMethodCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentMethodSettingsController extends Controller
{
    public function index(StoreContext $context): JsonResponse
    {
        $store = $context->store();

        return response()->json([
            'store_id' => $store->id,
            'methods' => PaymentMethodCatalog::forStore($store->id),
        ]);
    }

    public function sync(Request $request, StoreContext $context, AuditTrailService $audit): JsonResponse
    {
        $data = $request->validate([
            'enabled_codes' => ['required', 'array', 'min:1'],
            'enabled_codes.*' => ['required', 'string', 'max:30'],
        ]);

        $store = $context->store();
        $enabledSet = array_values(array_unique(array_map('strval', $data['enabled_codes'])));
        $builtIn = PaymentMethodCatalog::builtIn();

        $existingCustom = DB::table('store_payment_methods')
            ->where('store_id', $store->id)
            ->where('is_custom', true)
            ->get()
            ->keyBy('code');

        $validEnabled = array_values(array_filter(
            $enabledSet,
            fn (string $code) => isset($builtIn[$code]) || $existingCustom->has($code),
        ));

        if ($validEnabled === []) {
            throw ValidationException::withMessages([
                'enabled_codes' => 'Debes mantener al menos un método de pago activo.',
            ]);
        }

        $now = now();
        $orderMap = array_flip($validEnabled);

        DB::transaction(function () use ($store, $builtIn, $existingCustom, $orderMap, $now) {
            foreach ($builtIn as $code => $meta) {
                $isEnabled = array_key_exists($code, $orderMap);
                $sortOrder = $isEnabled ? (($orderMap[$code] + 1) * 10) : (500 + $meta['sort_order']);

                DB::table('store_payment_methods')->updateOrInsert(
                    ['store_id' => $store->id, 'code' => $code],
                    [
                        'label' => $meta['label'],
                        'bg_color' => $meta['bg_color'],
                        'fg_color' => $meta['fg_color'],
                        'is_custom' => false,
                        'is_enabled' => $isEnabled,
                        'sort_order' => $sortOrder,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            foreach ($existingCustom as $code => $row) {
                $isEnabled = array_key_exists($code, $orderMap);
                $sortOrder = $isEnabled ? (($orderMap[$code] + 1) * 10) : (900 + ((int) $row->id % 100));

                DB::table('store_payment_methods')
                    ->where('id', $row->id)
                    ->where('store_id', $store->id)
                    ->update([
                        'is_enabled' => $isEnabled,
                        'sort_order' => $sortOrder,
                        'updated_at' => $now,
                    ]);
            }
        });

        $audit->record('payment_methods.synced', $store, null, [
            'enabled_count' => count($validEnabled),
            'enabled_codes' => $validEnabled,
        ], $request->user());

        return response()->json([
            'store_id' => $store->id,
            'methods' => PaymentMethodCatalog::forStore($store->id),
        ]);
    }

    public function storeCustom(Request $request, StoreContext $context, AuditTrailService $audit): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:60'],
            'bg_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'fg_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon_svg' => ['nullable', 'string', 'max:32768'],
            'is_enabled' => ['nullable', 'boolean'],
        ]);

        $store = $context->store();
        $label = trim($data['label']);
        $sanitizedSvg = PaymentMethodCatalog::sanitizeSvg($data['icon_svg'] ?? null);
        $code = PaymentMethodCatalog::slugifyCode($label, $store->id);
        $now = now();

        $maxOrder = (int) DB::table('store_payment_methods')
            ->where('store_id', $store->id)
            ->max('sort_order');

        $id = DB::table('store_payment_methods')->insertGetId([
            'store_id' => $store->id,
            'code' => $code,
            'label' => $label,
            'bg_color' => $data['bg_color'] ?? '#ede9fe',
            'fg_color' => $data['fg_color'] ?? '#4c1d95',
            'icon_svg' => $sanitizedSvg,
            'is_custom' => true,
            'is_enabled' => $data['is_enabled'] ?? true,
            'sort_order' => $maxOrder > 0 ? min($maxOrder + 10, 60000) : 200,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $audit->record('payment_methods.custom_created', $store, null, [
            'method_id' => $id,
            'code' => $code,
            'label' => $label,
        ], $request->user());

        return response()->json([
            'created_code' => $code,
            'methods' => PaymentMethodCatalog::forStore($store->id),
        ], 201);
    }

    public function updateCustom(Request $request, StoreContext $context, AuditTrailService $audit): JsonResponse
    {
        $methodId = (int) $request->route('method');
        $store = $context->store();

        $row = DB::table('store_payment_methods')
            ->where('id', $methodId)
            ->where('store_id', $store->id)
            ->where('is_custom', true)
            ->first();

        abort_unless($row, 404, 'Método de pago personalizado no encontrado.');

        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'min:2', 'max:60'],
            'bg_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'fg_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon_svg' => ['sometimes', 'nullable', 'string', 'max:32768'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        $updates = ['updated_at' => now()];
        if (array_key_exists('label', $data)) {
            $updates['label'] = trim($data['label']);
        }
        if (array_key_exists('bg_color', $data) && $data['bg_color']) {
            $updates['bg_color'] = $data['bg_color'];
        }
        if (array_key_exists('fg_color', $data) && $data['fg_color']) {
            $updates['fg_color'] = $data['fg_color'];
        }
        if (array_key_exists('icon_svg', $data)) {
            $updates['icon_svg'] = PaymentMethodCatalog::sanitizeSvg($data['icon_svg']);
        }
        if (array_key_exists('is_enabled', $data)) {
            $updates['is_enabled'] = (bool) $data['is_enabled'];
        }

        DB::table('store_payment_methods')
            ->where('id', $row->id)
            ->where('store_id', $store->id)
            ->update($updates);

        $audit->record('payment_methods.custom_updated', $store, null, [
            'method_id' => $row->id,
            'code' => $row->code,
            'label' => $updates['label'] ?? $row->label,
        ], $request->user());

        return response()->json([
            'methods' => PaymentMethodCatalog::forStore($store->id),
        ]);
    }

    public function destroyCustom(Request $request, StoreContext $context, AuditTrailService $audit): JsonResponse
    {
        $methodId = (int) $request->route('method');
        $store = $context->store();

        $row = DB::table('store_payment_methods')
            ->where('id', $methodId)
            ->where('store_id', $store->id)
            ->where('is_custom', true)
            ->first();

        abort_unless($row, 404, 'Método de pago personalizado no encontrado.');

        DB::table('store_payment_methods')
            ->where('id', $row->id)
            ->where('store_id', $store->id)
            ->delete();

        $audit->record('payment_methods.custom_deleted', $store, [
            'method_id' => $row->id,
            'code' => $row->code,
            'label' => $row->label,
        ], null, $request->user());

        return response()->json([
            'methods' => PaymentMethodCatalog::forStore($store->id),
        ]);
    }
}
