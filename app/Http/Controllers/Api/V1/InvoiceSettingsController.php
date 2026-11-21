<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\StoreContext;
use App\Domain\Sales\InvoiceSettingsService;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class InvoiceSettingsController extends Controller
{
    public function show(StoreContext $context, InvoiceSettingsService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->getForStore($context->store()->id),
        ]);
    }

    public function update(Request $request, StoreContext $context, InvoiceSettingsService $service, AuditTrailService $audit): JsonResponse
    {
        $data = $request->validate([
            'logo_width_px' => ['sometimes', 'integer', 'min:28', 'max:110'],
            'accent_color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'document_title' => ['sometimes', 'string', 'min:2', 'max:40'],
            'brand_subtitle' => ['sometimes', 'nullable', 'string', 'max:120'],
            'show_logo' => ['sometimes', 'boolean'],
            'show_product_images' => ['sometimes', 'boolean'],
            'show_sku' => ['sometimes', 'boolean'],
            'show_store_contact' => ['sometimes', 'boolean'],
            'show_customer_contact' => ['sometimes', 'boolean'],
            'show_payment_summary' => ['sometimes', 'boolean'],
            'show_payment_methods' => ['sometimes', 'boolean'],
            'show_notes' => ['sometimes', 'boolean'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:240'],
            'terms_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $store = $context->store();
        $updated = $service->updateForStore($store->id, $data);

        $audit->record('invoice_settings.updated', $store, null, [
            'fields' => array_keys($data),
        ], $request->user());

        return response()->json(['data' => $updated]);
    }

    public function uploadLogo(Request $request, StoreContext $context, InvoiceSettingsService $service, AuditTrailService $audit): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $store = $context->store();
        $updated = $service->saveLogo($store->id, $request->file('logo'));

        $audit->record('invoice_settings.logo_uploaded', $store, null, [
            'has_logo' => $updated['has_logo'],
        ], $request->user());

        return response()->json(['data' => $updated]);
    }

    public function destroyLogo(Request $request, StoreContext $context, InvoiceSettingsService $service, AuditTrailService $audit): JsonResponse
    {
        $store = $context->store();
        $updated = $service->removeLogo($store->id);

        $audit->record('invoice_settings.logo_removed', $store, null, [
            'has_logo' => false,
        ], $request->user());

        return response()->json(['data' => $updated]);
    }

    public function preview(StoreContext $context, InvoiceSettingsService $service): Response
    {
        $store = $context->store();
        $invoiceSettings = $service->getForStore($store->id);
        $sampleImg = $service->sampleProductImageDataUri($store->id);

        $sampleProduct = DB::table('products as p')
            ->join('product_variants as pv', 'pv.product_id', '=', 'p.id')
            ->where('p.store_id', $store->id)
            ->orderByDesc('p.id')
            ->select('p.name', 'pv.sku', 'pv.sale_price')
            ->first();

        $unitPrice = $sampleProduct?->sale_price ?? '125.0000';
        $invoice = (object) [
            'id' => 0,
            'order_id' => 1042,
            'invoice_number' => ($store->code ?: 'FAC').'-2026-0001',
            'issued_at' => now()->format('Y-m-d H:i:s'),
            'currency' => 'USD',
            'subtotal' => $unitPrice,
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'shipping_total' => '0.0000',
            'grand_total' => $unitPrice,
            'notes' => 'Ejemplo de nota: entrega coordinada con el cliente.',
            'weekly_account_id' => null,
        ];
        $items = collect([
            (object) [
                'id' => 1,
                'order_id' => 1042,
                'variant_id' => 1,
                'description' => $sampleProduct?->name ?? 'Producto de ejemplo',
                'sku_snapshot' => $sampleProduct?->sku ?? 'SKU-001',
                'quantity' => '1.0000',
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice,
            ],
        ]);
        $itemImages = ($invoiceSettings['show_product_images'] && $sampleImg) ? [1 => $sampleImg] : [];
        $business = [
            'name' => $store->name,
            'phone' => $store->phone,
            'address' => $store->address,
            'store_code' => $store->code,
        ];
        $customer = [
            'full_name' => 'Cliente de Prueba',
            'phone' => '+1 809-555-0100',
            'email' => null,
            'address' => null,
        ];
        $weekly = null;
        $settlement = ['paid_total' => $unitPrice, 'pending_total' => '0.0000'];
        $paymentMethods = ['zelle'];
        $paymentMethodLabels = ['zelle' => 'Zelle'];

        $bytes = Pdf::loadView('documents.invoice', compact(
            'invoice',
            'items',
            'business',
            'customer',
            'weekly',
            'settlement',
            'paymentMethods',
            'paymentMethodLabels',
            'invoiceSettings',
            'itemImages',
        ))->setPaper('a4')->setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ])->output();

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa-factura.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
