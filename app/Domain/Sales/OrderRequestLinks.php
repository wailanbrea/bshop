<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Domain\Media\ObjectStorage;
use App\Domain\Media\VariantPhoto;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\DecimalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class OrderRequestLinks
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    public function present(object $link): array
    {
        return ['id' => (int) $link->id, 'status' => $link->status === 'active' && CarbonImmutable::parse($link->expires_at)->lessThanOrEqualTo(now()) ? 'expired' : $link->status,
            'expires_at' => CarbonImmutable::parse($link->expires_at)->toIso8601String()];
    }

    public function issue(StoreContext $context, User $actor, array $input): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'customer_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'],
            'target_order_id' => ['nullable', 'integer', 'min:1'],
            'variant_ids' => ['required', 'array', 'list', 'min:1', 'max:50'], 'variant_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'hours' => ['required', 'integer', 'in:1,24,72,168'], 'shipping' => ['required', new DecimalAmount]])->validate();
        $key = strtolower($data['request_key']);
        unset($data['request_key']);
        $targetOrderId = ! empty($data['target_order_id']) ? (int) $data['target_order_id'] : null;
        if ($targetOrderId !== null) {
            $data['target_order_id'] = $targetOrderId;
        } else {
            unset($data['target_order_id']);
        }
        $data['variant_ids'] = array_map('intval', $data['variant_ids']);
        sort($data['variant_ids']);
        $data['customer_id'] = (int) $data['customer_id'];
        $data['warehouse_id'] = (int) $data['warehouse_id'];
        $data['hours'] = (int) $data['hours'];
        $data['shipping'] = Money::round(DecimalAmount::normalize($data['shipping']));
        $store = $context->store()->id;
        $token = hash_hmac('sha256', 'order-request:v1|'.$actor->id.'|'.$store.'|'.$key, config('app.key'));
        $result = $this->once->run('order-request.issue', $key, [$actor->id, $store], $data, fn () => $this->sales->authorize($context, $actor), function () use ($actor, $data, $token, $store, $targetOrderId) {
            Store::whereKey($store)->where('is_active', true)->lockForUpdate()->firstOrFail();
            Warehouse::where('store_id', $store)->where('is_active', true)->findOrFail($data['warehouse_id']);
            Customer::where('status', 'active')->sharedLock()->findOrFail($data['customer_id']);
            $targetOrder = null;
            if ($targetOrderId !== null) {
                $targetOrder = SalesOrder::where('store_id', $store)->where('customer_id', $data['customer_id'])->lockForUpdate()->findOrFail($targetOrderId);
                abort_if($targetOrder->order_kind !== 'reservation' || $targetOrder->closed_at || ! in_array($targetOrder->status, ['draft', 'reserved'], true) || OrderInvoice::query($targetOrder->id)->exists(), 409, 'El apartado seleccionado ya no admite agregar artículos.');
            }
            $valid = ProductVariant::whereIn('id', $data['variant_ids'])->where('is_active', true)->whereHas('product', fn ($q) => $q->where('status', 'active'))->count();
            abort_unless($valid === count($data['variant_ids']), 409, 'Selecciona variantes activas del catálogo.');
            // Read the current committed links after acquiring the store lock.
            abort_if(DB::table('share_links')->where('store_id', $store)->where('customer_id', $data['customer_id'])->where('status', 'active')->where('expires_at', '>', now())->lockForUpdate()->get(['id'])->count() >= 5, 409, 'Revoca un enlace activo de este cliente antes de generar otro.');
            $id = DB::table('share_links')->insertGetId(['store_id' => $store, 'customer_id' => $data['customer_id'], 'created_by_user_id' => $actor->id,
                'token_hash' => hash('sha256', $token), 'status' => 'active', 'expires_at' => now()->addHours($data['hours']), 'created_at' => now(), 'updated_at' => now()]);
            $catalogPayload = $targetOrderId !== null ? ['variants' => $data['variant_ids'], 'target_order_id' => $targetOrderId] : $data['variant_ids'];
            DB::table('share_link_catalogs')->insert(['share_link_id' => $id, 'store_id' => $store, 'warehouse_id' => $data['warehouse_id'], 'variant_ids' => json_encode($catalogPayload, JSON_THROW_ON_ERROR), 'shipping_total' => $data['shipping']]);
            $this->audit->record('order.request_link.created', Customer::findOrFail($data['customer_id']), after: ['link_id' => $id, 'store_id' => $store, 'customer_id' => $data['customer_id'], 'target_order_id' => $targetOrderId, 'variant_count' => count($data['variant_ids'])], actor: $actor);

            return ['link_id' => $id];
        });
        $link = DB::table('share_links')->find($result['link_id']);
        $view = $this->present($link);

        return ['link' => $view, 'path' => $view['status'] === 'active' && hash_equals($link->token_hash, hash('sha256', $token)) ? '/s/'.$token : null, 'replay' => $result['replay']];
    }

    public function revoke(StoreContext $context, User $actor, int $id): array
    {
        return DB::transaction(function () use ($context, $actor, $id) {
            $this->sales->authorize($context, $actor);
            $link = DB::table('share_links')->where('store_id', $context->store()->id)->where('id', $id)->whereExists(fn ($q) => $q->selectRaw('1')->from('share_link_catalogs')->whereColumn('share_link_id', 'share_links.id'))->lockForUpdate()->first();
            abort_unless($link, 404);
            if ($link->status !== 'revoked') {
                DB::table('share_links')->where('id', $id)->update(['status' => 'revoked', 'updated_at' => now()]);
                $this->audit->record('order.request_link.revoked', Customer::findOrFail($link->customer_id), after: ['link_id' => $id, 'store_id' => $context->store()->id], actor: $actor);
            }

            return $this->present(DB::table('share_links')->find($id));
        }, 3);
    }

    private function resolve(string $token, bool $lock = false): object
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $token), 404);
        $query = DB::table('share_links as l')->join('share_link_catalogs as c', fn ($q) => $q->on('c.share_link_id', '=', 'l.id')->on('c.store_id', '=', 'l.store_id'))->where('l.token_hash', hash('sha256', $token))->select('l.*', 'c.warehouse_id', 'c.variant_ids', 'c.shipping_total');
        $link = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($link && in_array($link->status, ['active', 'used'], true) && CarbonImmutable::parse($link->expires_at)->greaterThan(now()), 404);
        abort_unless(Store::whereKey($link->store_id)->where('is_active', true)->exists() && Warehouse::whereKey($link->warehouse_id)->where('store_id', $link->store_id)->where('is_active', true)->exists(), 404);
        $actor = User::find($link->created_by_user_id);
        abort_unless($actor?->permits('sales.create') && DB::table('store_user')->where('user_id', $actor->id)->where('store_id', $link->store_id)->exists(), 404);
        if ($link->status === 'active') {
            abort_unless(Customer::whereKey($link->customer_id)->where('status', 'active')->exists(), 404);
        }

        return $link;
    }

    private function context(object $link): StoreContext
    {
        $context = new StoreContext;
        $context->useStore(Store::findOrFail($link->store_id));

        return $context;
    }

    private function extractVariantIds(object $link): array
    {
        $decoded = json_decode($link->variant_ids, true);
        if (is_array($decoded) && isset($decoded['variants']) && is_array($decoded['variants'])) {
            return $decoded['variants'];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function extractTargetOrderId(object $link): ?int
    {
        $decoded = json_decode($link->variant_ids, true);
        if (is_array($decoded) && isset($decoded['target_order_id'])) {
            return (int) $decoded['target_order_id'];
        }

        return null;
    }

    private function receipt(int $linkId): array
    {
        $catalog = DB::table('share_link_catalogs')->where('share_link_id', $linkId)->first();
        $targetId = $catalog ? $this->extractTargetOrderId($catalog) : null;
        $order = $targetId !== null
            ? SalesOrder::where('store_id', $catalog->store_id)->findOrFail($targetId)
            : SalesOrder::where('share_link_id', $linkId)->firstOrFail();
        $invoice = OrderInvoice::query($order->id)->first();
        $position = OrderSettlement::position($order, $invoice?->id);

        return ['number' => $order->basket_number ?: $order->id, 'status' => $order->status, 'currency' => $order->currency, 'total' => $position['net_total'], 'original_total' => $order->grand_total, 'return_credit_total' => $position['return_credit_total'], 'refunded_total' => $position['refunded_total'], 'credit_available' => $position['credit_available'], 'return_pending' => $invoice ? ReturnReceiptState::pendingInvoice($invoice->id) : false,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => ['name' => $item->product_name_snapshot, 'quantity' => $item->quantity, 'price' => $item->unit_price, 'total' => $item->line_total])->all()];
    }

    public function info(string $token): array
    {
        $link = $this->resolve($token);
        $targetId = $link->status === 'active' ? $this->extractTargetOrderId($link) : null;
        $target = $targetId !== null ? SalesOrder::where('store_id', $link->store_id)->where('customer_id', $link->customer_id)->findOrFail($targetId) : null;

        return ['store_name' => Store::findOrFail($link->store_id)->name, 'currency' => BusinessProfile::current()->default_currency, 'status' => $link->status,
            'expires_at' => CarbonImmutable::parse($link->expires_at)->toIso8601String(), 'shipping' => (string) $link->shipping_total,
            'target_order' => $target ? ['number' => $target->basket_number ?: $target->id, 'items' => $target->items()->orderBy('id')->get()->map(fn ($item) => ['name' => $item->product_name_snapshot, 'quantity' => $item->quantity, 'price' => $item->unit_price, 'total' => $item->line_total])->all()] : null,
            'receipt' => $link->status === 'used' ? $this->receipt($link->id) : null];
    }

    public function catalog(string $token, array $input): array
    {
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000'], 'group_products' => ['nullable', 'boolean']])->validate();
        $link = $this->resolve($token);
        abort_unless($link->status === 'active', 409);
        $query = ProductVariant::with(['product.images.asset.renditions', 'attributeValues.attribute'])->whereIn('id', $this->extractVariantIds($link))->where('is_active', true)->whereHas('product', fn ($q) => $q->where('status', 'active'));
        if ($data['group_products'] ?? false) {
            $variants = $query->orderBy('id')->get();
            $balances = DB::table('stock_balances')->where('warehouse_id', $link->warehouse_id)->whereIn('variant_id', $variants->pluck('id'))->get()->keyBy('variant_id');
            $photoVariants = ProductVariant::with('attributeValues.attribute')->whereIn('id', $variants->flatMap(fn ($variant) => $variant->product->images->pluck('variant_id'))->filter()->unique())->get()->keyBy('id');
            $search = trim($data['q'] ?? '');
            $groups = $variants->groupBy('product_id')->filter(function ($items) use ($search) {
                return $search === '' || mb_stripos($items->first()->product->name, $search) !== false || $items->contains(fn ($variant) => mb_stripos($variant->sku, $search) !== false || $variant->attributeValues->contains(fn ($value) => mb_stripos($value->value, $search) !== false));
            })->values();
            $page = (int) ($data['page'] ?? 1);
            $rows = $groups->forPage($page, 12)->values()->map(function ($items) use ($balances, $photoVariants) {
                $product = $items->first()->product;
                $primary = $product->images->firstWhere('is_primary', true)?->asset?->renditions?->firstWhere('rendition', 'card');
                $primaryUrl = $primary ? app(ObjectStorage::class)->url($primary) : null;

                return ['id' => $product->id, 'name' => $product->name, 'image_url' => $primaryUrl, 'variants' => $items->map(function ($variant) use ($product, $balances, $photoVariants) {
                    $balance = $balances->get($variant->id);

                    return ['id' => $variant->id, 'sku' => $variant->sku, 'description' => $variant->description, 'price' => $variant->sale_price, 'image_url' => app(VariantPhoto::class)->url($product, $variant, 'card', $photoVariants), 'available' => $product->track_stock ? bcsub((string) ($balance->on_hand ?? '0'), (string) ($balance->reserved ?? '0'), 4) : null, 'attributes' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all()];
                })->values()->all()];
            });

            return ['data' => $rows->all(), 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($groups->count() / 12)), 'total' => $groups->count()]];
        }
        if (! empty($data['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("sku LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('product', fn ($p) => $p->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])));
        }
        $page = $query->orderBy('id')->paginate(12, ['*'], 'page', $data['page'] ?? 1);
        $balances = DB::table('stock_balances')->where('warehouse_id', $link->warehouse_id)->whereIn('variant_id', $page->pluck('id'))->get()->keyBy('variant_id');

        return ['data' => $page->getCollection()->map(function ($variant) use ($balances) {
            $balance = $balances->get($variant->id);
            $photo = $variant->product->images->firstWhere('is_primary', true);
            $image = $photo?->asset?->renditions?->firstWhere('rendition', 'card');

            return ['id' => $variant->id, 'name' => $variant->product->name, 'sku' => $variant->sku, 'description' => $variant->description, 'price' => $variant->sale_price,
                'available' => ! $variant->product->track_stock || bccomp(bcsub((string) ($balance->on_hand ?? '0'), (string) ($balance->reserved ?? '0'), 4), '0', 4) > 0,
                'attributes' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all(), 'image_url' => $image ? app(ObjectStorage::class)->url($image) : null];
        })->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]];
    }

    private function data(object $link, array $input, bool $confirmation): array
    {
        $data = $this->sales->validate([...$input, 'warehouse_id' => $link->warehouse_id, 'customer_id' => $link->customer_id,
            'discount' => '0', 'shipping' => (string) $link->shipping_total, 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash', 'notes' => null, 'estimated_delivery' => null], $confirmation);
        abort_unless(! array_diff(array_column($data['lines'], 'variant_id'), $this->extractVariantIds($link)), 422, 'Uno de los artículos no está autorizado en este enlace.');

        return $data;
    }

    private function mergeWithTargetOrder(SalesOrder $target, array $newLines): array
    {
        $map = [];
        foreach ($target->items()->orderBy('id')->get() as $item) {
            $map[(int) $item->variant_id] = [
                'id' => (int) $item->id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => bcadd((string) $item->quantity, '0', 4),
            ];
        }
        foreach ($newLines as $line) {
            $vid = (int) $line['variant_id'];
            if (isset($map[$vid])) {
                $map[$vid]['quantity'] = bcadd($map[$vid]['quantity'], (string) $line['quantity'], 4);
            } else {
                $map[$vid] = [
                    'variant_id' => $vid,
                    'quantity' => bcadd((string) $line['quantity'], '0', 4),
                ];
            }
        }

        return array_values($map);
    }

    public function quote(string $token, array $input): array
    {
        $link = $this->resolve($token);
        abort_unless($link->status === 'active', 409);
        $data = $this->data($link, $input, false);
        $actor = User::findOrFail($link->created_by_user_id);
        $targetId = $this->extractTargetOrderId($link);
        if ($targetId !== null) {
            $target = SalesOrder::where('store_id', $link->store_id)->where('customer_id', $link->customer_id)->findOrFail($targetId);
            $merged = $this->mergeWithTargetOrder($target, $data['lines']);
            $quote = app(BasketService::class)->preview($this->context($link), $target->id, [
                'version' => $target->lock_version,
                'lines' => $merged,
                'shipping' => (string) $link->shipping_total,
                'discount' => (string) $target->discount_total,
            ], $actor);

            return array_intersect_key($quote, array_flip(['currency', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total', 'lines', 'quote_hash']));
        }
        $quote = $this->sales->quote($this->context($link), $data, $actor);

        return array_intersect_key($quote, array_flip(['currency', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total', 'lines', 'quote_hash']));
    }

    public function confirm(string $token, array $input): array
    {
        $link = $this->resolve($token);
        $data = $this->data($link, $input, true);
        $key = strtolower($data['request_key']);
        unset($data['request_key']);
        $result = $this->once->run('order-request.confirm', $key, [(int) $link->id], $data, function () use ($token) {
            $this->resolve($token, true);
        }, function () use ($token, $data, $key) {
            $link = $this->resolve($token, true);
            abort_unless($link->status === 'active', 409, 'Este enlace ya confirmó un pedido. Consulta su resumen.');
            $hex = hash_hmac('sha256', 'order-request-confirm|'.$link->id.'|'.$key, config('app.key'));
            $salesKey = substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
            $actor = User::findOrFail($link->created_by_user_id);
            $targetId = $this->extractTargetOrderId($link);
            if ($targetId !== null) {
                $target = SalesOrder::where('store_id', $link->store_id)->where('customer_id', $link->customer_id)->lockForUpdate()->findOrFail($targetId);
                $merged = $this->mergeWithTargetOrder($target, $data['lines']);
                app(BasketService::class)->save($this->context($link), $target->id, [
                    'request_key' => $salesKey,
                    'version' => $target->lock_version,
                    'quote_hash' => $data['quote_hash'],
                    'lines' => $merged,
                    'shipping' => (string) $link->shipping_total,
                    'discount' => (string) $target->discount_total,
                ], $actor);
                abort_unless(CarbonImmutable::parse($link->expires_at)->greaterThan(now()), 404);
                DB::table('share_links')->where('id', $link->id)->update(['status' => 'used', 'used_at' => now(), 'updated_at' => now()]);
                $this->audit->record('order.request_link.confirmed', SalesOrder::findOrFail($target->id), after: ['link_id' => $link->id, 'order_id' => $target->id, 'mode' => 'append'], actor: $actor);

                return ['link_id' => (int) $link->id];
            }
            $result = $this->sales->create($this->context($link), [...$data, 'request_key' => $salesKey], $actor);
            // A stock lock may have waited beyond expiry: rollback the entire nested operation.
            abort_unless(CarbonImmutable::parse($link->expires_at)->greaterThan(now()), 404);
            SalesOrder::whereKey($result['order']['id'])->update(['share_link_id' => $link->id, 'channel' => 'share_link']);
            DB::table('share_links')->where('id', $link->id)->update(['status' => 'used', 'used_at' => now(), 'updated_at' => now()]);
            $this->audit->record('order.request_link.confirmed', SalesOrder::findOrFail($result['order']['id']), after: ['link_id' => $link->id, 'order_id' => $result['order']['id']], actor: $actor);

            return ['link_id' => (int) $link->id];
        });

        return ['receipt' => $this->receipt($result['link_id']), 'replay' => $result['replay']];
    }
}
