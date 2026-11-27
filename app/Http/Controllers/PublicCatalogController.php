<?php

namespace App\Http\Controllers;

use App\Domain\Media\ObjectStorage;
use App\Domain\Sales\InvoiceSettingsService;
use App\Domain\Sales\WhatsAppConfiguration;
use App\Http\Controllers\Api\V1\ReservationSettingsController;
use App\Models\BusinessProfile;
use App\Models\Product;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicCatalogController extends Controller
{
    public function show(Request $request, string $storeKey, WhatsAppConfiguration $waConfig, InvoiceSettingsService $invoiceSettings, ObjectStorage $storage)
    {
        $store = ctype_digit($storeKey)
            ? Store::where('id', (int) $storeKey)->where('is_active', true)->first()
            : Store::whereRaw('LOWER(code) = ?', [mb_strtolower($storeKey)])->where('is_active', true)->first();

        abort_unless($store !== null, 404);

        $warehouseIds = Warehouse::where('store_id', $store->id)->where('is_active', true)->pluck('id')->all();
        $products = Product::where('store_id', $store->id)
            ->where('status', 'active')
            ->with([
                'category:id,name',
                'images.asset.renditions',
                'variants' => fn ($q) => $q->where('is_active', true)->orderBy('id'),
                'variants.attributeValues.attribute:id,name',
            ])
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $allVariantIds = $products->flatMap(fn ($p) => $p->variants->pluck('id'))->all();
        $stockByVariant = [];
        if ($warehouseIds && $allVariantIds) {
            $rows = DB::table('stock_balances')
                ->whereIn('warehouse_id', $warehouseIds)
                ->whereIn('variant_id', $allVariantIds)
                ->groupBy('variant_id')
                ->selectRaw('variant_id, COALESCE(SUM(on_hand - reserved), 0) as avail')
                ->get();
            foreach ($rows as $r) {
                $stockByVariant[(int) $r->variant_id] = max(0.0, (float) $r->avail);
            }
        }

        $currency = BusinessProfile::current()->default_currency ?: 'USD';
        $currencyPrefix = $currency === 'USD' ? 'US$' : $currency;
        $wa = $waConfig->status(false, $store->id);
        $rawWaPhone = (string) ($wa['display_phone'] ?? $store->phone ?? '');
        $waDigits = preg_replace('/\D+/', '', $rawWaPhone);
        $settings = $invoiceSettings->getForStore($store->id);
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($settings['accent_color'] ?? '')) ? $settings['accent_color'] : '#6d28d9';
        $logoUri = (! empty($settings['show_logo']) && ! empty($settings['logo_data_uri'])) ? $settings['logo_data_uri'] : null;

        $categoriesMap = [];
        $items = [];
        $ogImage = null;

        foreach ($products as $p) {
            $imgs = [];
            $sortedImgs = $p->images->sortBy(fn ($im) => [$im->is_primary ? 0 : 1, $im->sort_order, $im->id]);
            foreach ($sortedImgs as $im) {
                $renditions = $im->asset?->renditions?->keyBy('rendition');
                $r = $renditions?->get('card') ?: $renditions?->get('detail') ?: $renditions?->get('thumb');
                if ($r) {
                    $url = $storage->url($r);
                    $imgs[] = ['url' => $url, 'variant_id' => $im->variant_id];
                    if ($ogImage === null) {
                        $ogImage = url($url);
                    }
                }
            }

            $variantsList = [];
            $totalAvail = 0.0;
            $minPrice = null;
            foreach ($p->variants as $v) {
                $avail = $p->track_stock ? ($stockByVariant[$v->id] ?? 0.0) : 999.0;
                $totalAvail += $avail;
                $priceFloat = (float) $v->sale_price;
                if ($minPrice === null || $priceFloat < $minPrice) {
                    $minPrice = $priceFloat;
                }
                $label = $v->attributeValues->map(fn ($av) => trim((string) $av->value))->filter()->implode(' / ');
                $variantsList[] = [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'label' => $label,
                    'price' => number_format($priceFloat, 2, '.', ','),
                    'available' => $p->track_stock ? (int) floor($avail) : null,
                    'in_stock' => ! $p->track_stock || $avail >= 1,
                ];
            }

            if ($minPrice === null) {
                continue;
            }

            $catName = $p->category?->name ?: 'General';
            $categoriesMap[$catName] = $catName;

            $items[] = [
                'id' => $p->id,
                'code' => 'K-'.$p->id,
                'name' => $p->name,
                'description' => $p->description,
                'category' => $catName,
                'price' => number_format($minPrice, 2, '.', ','),
                'track_stock' => (bool) $p->track_stock,
                'available' => $p->track_stock ? (int) floor($totalAvail) : null,
                'in_stock' => ! $p->track_stock || $totalAvail >= 1,
                'images' => $imgs,
                'variants' => $variantsList,
            ];
        }

        ksort($categoriesMap);

        return view('public-catalog', [
            'store' => $store,
            'accent' => $accent,
            'logoUri' => $logoUri,
            'currencyPrefix' => $currencyPrefix,
            'waDigits' => $waDigits,
            'categories' => array_values($categoriesMap),
            'items' => $items,
            'ogImage' => $ogImage,
            'depositPolicy' => ReservationSettingsController::depositPolicy(),
        ]);
    }
}
