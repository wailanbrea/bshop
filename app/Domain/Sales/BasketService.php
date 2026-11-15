<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Domain\Inventory\StockLedger;
use App\Domain\Media\ObjectStorage;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\DecimalAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BasketService
{
    public function __construct(private SalesService $sales, private StockLedger $ledger, private IdempotentAction $once, private AuditTrailService $audit) {}

    public function create(StoreContext $context, array $input, User $actor): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'customer_id' => ['required', 'integer', 'min:1'], 'warehouse_id' => ['required', 'integer', 'min:1']])->validate();

        return $this->once->run('baskets.create', $data['request_key'], [$actor->id, $context->store()->id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $data, $actor) {
            $store = Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::where('store_id', $store->id)->lockForUpdate()->findOrFail($data['warehouse_id']);
            abort_unless($store->is_active && $warehouse->is_active, 409, 'Sucursal o almacén inactivos.');
            $customer = Customer::where('status', 'active')->sharedLock()->findOrFail($data['customer_id']);
            $order = SalesOrder::create(['store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'customer_snapshot' => $customer->only(['full_name', 'phone', 'email', 'address']), 'status' => 'draft', 'order_kind' => 'reservation', 'channel' => 'manual', 'currency' => BusinessProfile::current()->default_currency, 'subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '0.0000', 'shipping_total' => '0.0000', 'grand_total' => '0.0000', 'tax_rate_snapshot' => DB::table('business_settings')->where('id', 1)->value('sales_tax_rate')]);
            $this->sales->assignNumber($order);
            $this->audit->record('basket.created', $order, actor: $actor);

            return ['order' => $this->sales->present($order)];
        });
    }

    private function data(array $input, bool $save): array
    {
        $rules = ['version' => ['required', 'integer', 'min:0'], 'lines' => ['present', 'array', 'list', 'max:50'], 'lines.*.id' => ['nullable', 'integer', 'min:1'], 'lines.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'], 'lines.*.quantity' => ['required', new DecimalAmount], 'shipping' => ['required', new DecimalAmount], 'discount' => ['required', new DecimalAmount]];
        if ($save) {
            $rules += ['request_key' => ['required', 'uuid'], 'quote_hash' => ['required', 'string', 'size:64']];
        }
        $data = Validator::make($input, $rules)->validate();
        $data['version'] = (int) $data['version'];
        foreach ($data['lines'] as &$line) {
            $line['variant_id'] = (int) $line['variant_id'];
            if (isset($line['id'])) {
                $line['id'] = (int) $line['id'];
            }
            $line['quantity'] = DecimalAmount::normalize($line['quantity']);
            if (bccomp($line['quantity'], '0', 4) <= 0) {
                throw ValidationException::withMessages(['lines' => 'Las cantidades deben ser positivas. Retira la línea para eliminarla.']);
            }
        } unset($line);
        $data['shipping'] = Money::round($data['shipping']);
        $data['discount'] = Money::round($data['discount']);

        return $data;
    }

    private function editable(SalesOrder $order): void
    {
        abort_if($order->order_kind !== 'reservation' || $order->closed_at || in_array($order->status, ['cancelled', 'fulfilled', 'confirmed'], true) || OrderInvoice::query($order->id)->exists(), 409, 'El apartado está cerrado, facturado o entregado; conserva su documento histórico.');
    }

    private function calculate(SalesOrder $order, array $data, $variants, $items): array
    {
        $subtotal = '0.0000';
        $lines = [];
        foreach ($data['lines'] as $line) {
            $old = $items->get($line['variant_id']);
            if (isset($line['id']) && (! $old || $old->id !== $line['id'])) {
                throw ValidationException::withMessages(['lines' => 'La línea no pertenece a este apartado o variante.']);
            }
            $variant = $variants->get($line['variant_id']);
            abort_unless($variant, 404);
            if (! $old) {
                abort_unless(! $variant->trashed() && $variant->is_active && $variant->product && ! $variant->product->trashed() && $variant->product->status === 'active', 409, 'No se puede añadir una variante inactiva.');
            }
            $price = $old?->unit_price ?? $variant->sale_price;
            $total = Money::round(bcmul($price, $line['quantity'], 8));
            $subtotal = bcadd($subtotal, $total, 4);
            $lines[] = [...$line, 'unit_price' => $price, 'line_total' => $total];
        }
        if (bccomp($data['discount'], $subtotal, 4) > 0) {
            throw ValidationException::withMessages(['discount' => 'El descuento supera el subtotal.']);
        }
        $tax = Money::round(bcdiv(bcmul(bcsub($subtotal, $data['discount'], 4), (string) ($order->tax_rate_snapshot ?? '0'), 8), '100', 8));
        $grand = Money::round(bcadd(bcadd(bcsub($subtotal, $data['discount'], 4), $tax, 4), $data['shipping'], 4));
        $paid = $this->sales->paid($order->id);
        if (bccomp($grand, $paid, 4) < 0) {
            throw ValidationException::withMessages(['lines' => 'El nuevo total sería inferior a los depósitos registrados. Resuelve primero la devolución correspondiente.']);
        }
        $result = ['lines' => $lines, 'subtotal' => $subtotal, 'shipping_total' => $data['shipping'], 'discount_total' => $data['discount'], 'tax_total' => $tax, 'grand_total' => $grand, 'paid_total' => $paid, 'pending_total' => bcsub($grand, $paid, 4), 'currency' => $order->currency];
        $result['quote_hash'] = hash('sha256', json_encode([$order->id, $order->lock_version, $result], JSON_THROW_ON_ERROR));

        return $result;
    }

    public function preview(StoreContext $context, int $id, array $input, User $actor): array
    {
        $this->sales->authorize($context, $actor);
        $data = $this->data($input, false);
        $order = SalesOrder::where('store_id', $context->store()->id)->findOrFail($id);
        $this->editable($order);
        abort_if($order->lock_version !== $data['version'], 409, 'El apartado cambió. Actualiza conservando tu borrador.');
        $variants = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed(), 'attributeValues.attribute'])->whereIn('id', array_column($data['lines'], 'variant_id'))->get()->keyBy('id');

        return $this->calculate($order, $data, $variants, $order->items()->get()->keyBy('variant_id'));
    }

    public function save(StoreContext $context, int $id, array $input, User $actor): array
    {
        $data = $this->data($input, true);
        $key = $data['request_key'];
        unset($data['request_key']);

        return $this->once->run('baskets.save', $key, [$actor->id, $context->store()->id, $id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $id, $data, $actor, $key) {
            $ref = SalesOrder::where('store_id', $context->store()->id)->findOrFail($id);
            $ids = array_values(array_unique([...$ref->items()->pluck('variant_id')->all(), ...array_column($data['lines'], 'variant_id')]));
            sort($ids);
            [$warehouse, $variants, $balances, $products] = $this->sales->lockStock($context, $ids, $ref->warehouse_id);
            $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
            $this->editable($order);
            abort_if($order->lock_version !== $data['version'], 409, 'El apartado cambió. Actualiza conservando el borrador.');
            $items = $order->items()->orderBy('id')->lockForUpdate()->get()->keyBy('variant_id');
            abort_if(array_diff($items->keys()->all(), $ids), 409, 'Hay artículos nuevos en el apartado. Actualiza.');
            $reservations = DB::table('stock_reservations')->whereIn('order_item_id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('variant_id');
            abort_if($reservations->contains(fn ($r) => bccomp((string) $r->fulfilled_quantity, '0', 4) > 0), 409, 'Hay entregas parciales; no se pueden reescribir sus cantidades.');
            $quote = $this->calculate($order, $data, $variants, $items);
            abort_unless(hash_equals($quote['quote_hash'], $data['quote_hash']), 409, 'El precio o saldo cambió. Revisa la cotización actual.');
            $targets = collect($quote['lines'])->keyBy('variant_id');
            $before = $order->toArray();
            $deadline = app(ReservationExpiry::class)->deadline($order->id);
            foreach ($ids as $variantId) {
                $old = $items->get($variantId);
                $target = $targets->get($variantId);
                $variant = $variants[$variantId];
                $item = $old;
                if ($target && ! $item) {
                    $item = $order->items()->create(['store_id' => $order->store_id, 'variant_id' => $variantId, 'quantity' => $target['quantity'], 'unit_price' => $target['unit_price'], 'line_total' => $target['line_total'], 'product_name_snapshot' => $variant->product->name, 'sku_snapshot' => $variant->sku, 'variant_snapshot' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all()]);
                    $photo = $variant->product->images()->with('asset.renditions')->where('is_primary', true)->first();
                    $thumb = $photo?->asset?->renditions?->firstWhere('rendition', 'thumb');
                    if ($thumb) {
                        $item->image_url_snapshot = app(ObjectStorage::class)->url($thumb);
                        $item->save();
                    }
                }
                $r = $reservations->get($variantId);
                if ($variant->product->track_stock) {
                    $held = $r ? bcsub(bcsub((string) $r->quantity, (string) $r->fulfilled_quantity, 4), (string) $r->released_quantity, 4) : '0.0000';
                    $wanted = $target['quantity'] ?? '0.0000';
                    $delta = bcsub($wanted, $held, 4);
                    if (bccomp($delta, '0', 4) !== 0) {
                        $balance = $balances[$variantId];
                        abort_if(bccomp($delta, '0', 4) > 0 && bccomp(bcsub((string) $balance->on_hand, (string) $balance->reserved, 4), $delta, 4) < 0, 409, 'Stock insuficiente para '.$variant->sku.'.');
                        if (! $r) {
                            $r = (object) ['id' => DB::table('stock_reservations')->insertGetId(['order_item_id' => $item->id, 'store_id' => $order->store_id, 'warehouse_id' => $warehouse->id, 'variant_id' => $variantId, 'quantity' => $wanted, 'fulfilled_quantity' => '0.0000', 'released_quantity' => '0.0000', 'status' => 'active', 'expires_at' => $deadline, 'created_at' => now(), 'updated_at' => now()])];
                        } else {
                            DB::table('stock_reservations')->where('id', $r->id)->update(['quantity' => bccomp($delta, '0', 4) > 0 ? bcadd((string) $r->quantity, $delta, 4) : $r->quantity, 'released_quantity' => bccomp($delta, '0', 4) < 0 ? bcsub((string) $r->released_quantity, $delta, 4) : $r->released_quantity, 'status' => bccomp($wanted, '0', 4) > 0 ? 'active' : 'released', 'updated_at' => now()]);
                        }
                        $this->ledger->record($warehouse, $variantId, $balance, '0.0000', $delta, bccomp($delta, '0', 4) > 0 ? 'reserve' : 'release', 'basket:'.$key.':'.$variantId, 'Edición de apartado #'.$order->basket_number, $actor, $r->id);
                    }
                }
                if ($old) {
                    if ($target) {
                        $old->update(['quantity' => $target['quantity'], 'line_total' => $target['line_total']]);
                    } else {
                        $old->update(['removed_at' => now()]);
                    }
                }
            }
            $order->fill(collect($quote)->only(['subtotal', 'discount_total', 'shipping_total', 'tax_total', 'grand_total'])->all());
            $order->status = count($data['lines']) ? 'reserved' : 'draft';
            $order->lock_version++;
            $order->save();
            foreach ($products as $product) {
                $product->increment('lock_version');
            }
            $this->audit->record('basket.updated', $order, before: $before, after: ['total' => $order->grand_total, 'version' => $order->lock_version, 'lines' => $data['lines']], actor: $actor);

            return ['order' => $this->sales->present($order)];
        });
    }

    public function number(StoreContext $context, int $id, array $input, User $actor): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'number' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,23}$/D']])->validate();
        $data['number'] = strtoupper($data['number']);
        try {
            return $this->once->run('baskets.number', $data['request_key'], [$actor->id, $context->store()->id, $id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $id, $data, $actor) {
                Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
                $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
                $this->editable($order);
                abort_if($order->lock_version !== (int) $data['version'], 409, 'El apartado cambió. Actualiza.');
                abort_if(SalesOrder::where('store_id', $order->store_id)->where('basket_number', $data['number'])->where('id', '!=', $id)->lockForUpdate()->first(), 409, 'Este número ya está ocupado en la sucursal.');
                $old = $order->basket_number;
                $order->basket_number = $data['number'];
                $order->lock_version++;
                $order->save();
                $this->audit->record('basket.renumbered', $order, before: ['number' => $old], after: ['number' => $order->basket_number], actor: $actor);

                return ['order' => $this->sales->present($order)];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['number' => 'El número ya está ocupado.']);
        }
    }

    public function dispatch(StoreContext $context, int $id, array $input, User $actor): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0']])->validate();

        return $this->once->run('baskets.dispatch', $data['request_key'], [$actor->id, $context->store()->id, $id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $id, $data, $actor) {
            $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
            abort_if($order->lock_version !== (int) $data['version'], 409, 'El apartado cambió.');
            abort_unless($order->status === 'fulfilled', 409, 'Registra la entrega física antes de marcar enviado.');
            if (! $order->shipped_at) {
                $order->shipped_at = now();
                $order->lock_version++;
                $order->save();
                $this->audit->record('basket.dispatched', $order, actor: $actor);
            }

            return ['order' => $this->sales->present($order)];
        });
    }
}
