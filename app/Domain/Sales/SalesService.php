<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Domain\Inventory\StockLedger;
use App\Domain\Media\ObjectStorage;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\DecimalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesService
{
    public function __construct(private IdempotentAction $idempotency, private StockLedger $ledger, private AuditTrailService $audit) {}

    public function authorize(StoreContext $context, User $user, string $permission = 'sales.create'): void
    {
        $actor = User::whereKey($user->id)->sharedLock()->firstOrFail();
        abort_unless($actor->permits($permission), 403);
        abort_unless(DB::table('store_user')->where('user_id', $user->id)->where('store_id', $context->store()->id)->sharedLock()->first(), 404);
    }

    public function validate(array $input, bool $confirmation = false): array
    {
        $rules = ['warehouse_id' => ['required', 'integer', 'min:1'], 'customer_id' => [$confirmation ? 'required' : 'nullable', 'integer', 'min:1'], 'lines' => ['required', 'array', 'list', 'min:1', 'max:50'], 'lines.*.variant_id' => ['required', 'integer', 'min:1', 'distinct'], 'lines.*.quantity' => ['required', new DecimalAmount], 'discount' => ['required', new DecimalAmount], 'shipping' => ['required', new DecimalAmount], 'notes' => ['nullable', 'string', 'max:4000'], 'estimated_delivery' => ['nullable', 'date_format:Y-m-d']];
        if ($confirmation) {
            $rules = [...$rules, 'request_key' => ['required', 'uuid'], 'quote_hash' => ['required', 'string', 'size:64'], 'mode' => ['required', 'in:sale,reservation'], 'initial_payment' => ['required', new DecimalAmount], 'payment_method' => ['required', 'in:cash,bank_transfer,card']];
        }
        $data = Validator::make($input, $rules)->validate();
        foreach (['warehouse_id', 'customer_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach ($data['lines'] as &$line) {
            $line['variant_id'] = (int) $line['variant_id'];
            $line['quantity'] = DecimalAmount::normalize($line['quantity']);
            if (bccomp($line['quantity'], '0', 4) <= 0) {
                throw ValidationException::withMessages(['lines' => 'Cada cantidad debe ser mayor que cero.']);
            }
        } unset($line);
        foreach (['discount', 'shipping', 'initial_payment'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Money::round(DecimalAmount::normalize($data[$field]));
            }
        }

        return $data;
    }

    /** A quote reads current prices; it never creates an order or reserves stock. */
    public function quote(StoreContext $context, array $input, User $actor): array
    {
        $this->authorize($context, $actor);
        $data = $this->validate($input);
        $warehouse = Warehouse::where('store_id', $context->store()->id)->where('is_active', true)->findOrFail($data['warehouse_id']);
        $variants = ProductVariant::with(['product', 'attributeValues.attribute'])->whereIn('id', array_column($data['lines'], 'variant_id'))->get()->keyBy('id');

        return $this->price($data, $warehouse, $variants);
    }

    private function price(array $data, Warehouse $warehouse, $variants): array
    {
        $profile = BusinessProfile::current();
        $settings = DB::table('business_settings')->where('id', 1)->first();
        $subtotal = '0.0000';
        $tax = '0.0000';
        $lines = [];
        foreach ($data['lines'] as $line) {
            $variant = $variants->get($line['variant_id']);
            abort_unless($variant && $variant->is_active && $variant->product && $variant->product->status === 'active', 409, 'Un producto o variante ya no está activo. Actualiza el catálogo.');
            $total = Money::round(bcmul($variant->sale_price, $line['quantity'], 8));
            $subtotal = bcadd($subtotal, $total, 4);
            $lines[] = ['variant_id' => $variant->id, 'quantity' => $line['quantity'], 'unit_price' => $variant->sale_price, 'line_total' => $total, 'product_name' => $variant->product->name, 'sku' => $variant->sku, 'track_stock' => $variant->product->track_stock, 'variant' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all()];
        }
        if (bccomp($data['discount'], $subtotal, 4) > 0) {
            throw ValidationException::withMessages(['discount' => 'El descuento no puede superar el subtotal.']);
        }
        // Existing configuration defaults to 0%. Item taxes apply to the discounted subtotal.
        $tax = Money::round(bcdiv(bcmul(bcsub($subtotal, $data['discount'], 4), (string) $settings->sales_tax_rate, 8), '100', 8));
        $total = Money::round(bcadd(bcadd(bcsub($subtotal, $data['discount'], 4), $tax, 4), $data['shipping'], 4));
        $result = ['currency' => $profile->default_currency, 'warehouse_id' => $warehouse->id, 'tax_rate' => bcadd((string) $settings->sales_tax_rate, '0', 4), 'subtotal' => $subtotal, 'discount_total' => $data['discount'], 'tax_total' => $tax, 'shipping_total' => $data['shipping'], 'grand_total' => $total, 'lines' => $lines];
        $result['quote_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    /** Product -> variants -> store -> warehouse -> balances; shared with manual stock lock order. */
    public function lockStock(StoreContext $context, array $variantIds, int $warehouseId, bool $requireActive = true): array
    {
        $references = ProductVariant::withTrashed()->whereIn('id', $variantIds)->get();
        abort_unless($references->count() === count($variantIds), 404);
        $products = Product::withTrashed()->whereIn('id', $references->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variants = ProductVariant::withTrashed()->with('attributeValues.attribute')->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($variants as $variant) {
            $variant->setRelation('product', $products[$variant->product_id]);
        }
        $store = Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
        $warehouse = Warehouse::where('store_id', $store->id)->lockForUpdate()->findOrFail($warehouseId);
        if ($requireActive) {
            abort_if(! $store->is_active || ! $warehouse->is_active, 409, 'La sucursal o el almacén están inactivos.');
        }
        foreach ($variants as $variant) {
            if ($variant->product->track_stock) {
                DB::table('stock_balances')->insertOrIgnore(['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'on_hand' => '0.0000', 'reserved' => '0.0000', 'version' => 0]);
            }
        }
        $balances = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->whereIn('variant_id', $variantIds)->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');

        return [$warehouse, $variants, $balances, $products];
    }

    public function create(StoreContext $context, array $input, User $actor): array
    {
        $data = $this->validate($input, true);
        $key = $data['request_key'];
        unset($data['request_key']);

        return $this->idempotency->run('sales.create', $key, [$actor->id, $context->store()->id], $data, fn () => $this->authorize($context, $actor), function () use ($context, $actor, $data, $key) {
            [$warehouse, $variants, $balances, $products] = $this->lockStock($context, array_column($data['lines'], 'variant_id'), $data['warehouse_id']);
            $customer = Customer::where('status', 'active')->sharedLock()->findOrFail($data['customer_id']);
            $quote = $this->price($data, $warehouse, $variants);
            abort_unless(hash_equals($quote['quote_hash'], $data['quote_hash']), 409, 'Los precios cambiaron. Revisa el total actualizado antes de confirmar.');
            if (bccomp($data['initial_payment'], $quote['grand_total'], 4) > 0) {
                throw ValidationException::withMessages(['initial_payment' => 'El abono supera el total exigible.']);
            }
            if (bccomp($data['initial_payment'], '0', 4) > 0) {
                $this->authorize($context, $actor, 'payments.record');
            }
            $order = SalesOrder::create(['store_id' => $context->store()->id, 'customer_id' => $customer->id, 'customer_snapshot' => $customer->only(['full_name', 'phone', 'email', 'address']), 'channel' => 'manual', 'status' => $data['mode'] === 'reservation' ? 'reserved' : 'fulfilled', 'currency' => $quote['currency'], 'subtotal' => $quote['subtotal'], 'discount_total' => $quote['discount_total'], 'tax_total' => $quote['tax_total'], 'shipping_total' => $quote['shipping_total'], 'grand_total' => $quote['grand_total'], 'notes' => $data['notes'] ?? null, 'estimated_delivery' => $data['estimated_delivery'] ?? null, 'confirmed_at' => now(), 'fulfilled_at' => $data['mode'] === 'sale' ? now() : null]);
            $order->warehouse_id = $warehouse->id;
            $order->order_kind = $data['mode'] === 'reservation' ? 'reservation' : 'sale';
            $order->tax_rate_snapshot = $quote['tax_rate'];
            $order->save();
            $this->assignNumber($order);
            $deadline = $data['mode'] === 'reservation' ? app(ReservationExpiry::class)->deadline() : null;
            foreach ($quote['lines'] as $line) {
                $item = $order->items()->create(['store_id' => $order->store_id, 'variant_id' => $line['variant_id'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $line['line_total'], 'product_name_snapshot' => $line['product_name'], 'sku_snapshot' => $line['sku'], 'variant_snapshot' => $line['variant']]);
                $photo = $variants[$line['variant_id']]->product->images()->with('asset.renditions')->where('is_primary', true)->first();
                $thumb = $photo?->asset?->renditions?->firstWhere('rendition', 'thumb');
                if ($thumb) {
                    $item->image_url_snapshot = app(ObjectStorage::class)->url($thumb);
                    $item->save();
                }
                if (! $line['track_stock']) {
                    continue;
                }
                $balance = $balances[$line['variant_id']];
                abort_if(bccomp(bcsub((string) $balance->on_hand, (string) $balance->reserved, 4), $line['quantity'], 4) < 0, 409, 'Stock insuficiente para '.$line['sku'].'. Otro usuario pudo reservarlo.');
                $reservationId = null;
                if ($data['mode'] === 'reservation') {
                    $reservationId = DB::table('stock_reservations')->insertGetId(['order_item_id' => $item->id, 'store_id' => $order->store_id, 'warehouse_id' => $warehouse->id, 'variant_id' => $line['variant_id'], 'quantity' => $line['quantity'], 'fulfilled_quantity' => '0.0000', 'released_quantity' => '0.0000', 'status' => 'active', 'expires_at' => $deadline, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->ledger->record($warehouse, $line['variant_id'], $balance, $data['mode'] === 'sale' ? bcsub('0', $line['quantity'], 4) : '0.0000', $data['mode'] === 'reservation' ? $line['quantity'] : '0.0000', $data['mode'] === 'sale' ? 'sale' : 'reserve', 'sale:'.$key.':'.$item->id, 'Pedido #'.$order->id, $actor, $reservationId);
            }
            foreach ($products as $product) {
                $product->increment('lock_version');
            }
            if (bccomp($data['initial_payment'], '0', 4) > 0) {
                $this->receivePayment($order, $data['initial_payment'], $data['payment_method'], $key.':initial', $actor);
            }
            if ($data['mode'] === 'sale') {
                $this->invoice($order, $actor);
            }
            $this->audit->record('sales.'.$data['mode'].'.created', $order, actor: $actor);

            return ['order' => $this->present($order)];
        });
    }

    public function present(SalesOrder $order, ?bool $locked = null): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->present(SalesOrder::whereKey($order->id)->firstOrFail(), false));
        }
        $locked ??= true;
        $order->load('items');
        $invoiceQuery = OrderInvoice::query($order->id)->select('invoices.id', 'invoices.invoice_number', 'invoices.status', 'invoices.currency', 'invoices.weekly_account_id');
        if ($locked) {
            $invoiceQuery->lockForUpdate();
        }
        $invoice = $invoiceQuery->first();
        $position = OrderSettlement::position($order, $invoice?->id, $locked);
        $paid = $position['paid_total'];
        $pending = $order->status === 'cancelled' ? '0.0000' : $position['pending_total'];
        $send = $invoice ? DB::table('invoice_deliveries')->where('invoice_id', $invoice->id)->orderByDesc('created_at')->orderByDesc('id')->first(['id', 'status', 'error_code', 'attempts', 'sent_at', 'delivered_at', 'read_at']) : null;

        $reservationRows = DB::table('stock_reservations')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $order->id)->select('id'))->get(['expires_at', 'status']);
        $activeReservations = $reservationRows->where('status', 'active');
        $deadline = $activeReservations->contains(fn ($r) => $r->expires_at === null) ? null : $activeReservations->min('expires_at');

        return [...$order->toArray(), ...$position, 'return_pending' => $invoice ? ReturnReceiptState::pendingInvoice($invoice->id) : false, 'reservation_expires_at' => $deadline ? CarbonImmutable::parse($deadline, 'UTC')->toIso8601String() : null, 'reservation_expired' => $order->status === 'cancelled' && $reservationRows->contains('status', 'expired'), 'customer_live' => Customer::withTrashed()->find($order->customer_id)?->only(['id', 'full_name', 'phone', 'email', 'address', 'notes', 'lock_version']), 'whatsapp_delivery' => $send, 'reservations' => DB::table('stock_reservations')->whereIn('order_item_id', $order->items->pluck('id'))->get()->map(fn ($r) => ['id' => $r->id, 'order_item_id' => $r->order_item_id, 'quantity' => bcadd((string) $r->quantity, '0', 4), 'fulfilled_quantity' => bcadd((string) $r->fulfilled_quantity, '0', 4), 'released_quantity' => bcadd((string) $r->released_quantity, '0', 4), 'status' => $r->status])->all(), 'paid_total' => $paid, 'pending_total' => $pending, 'invoice' => $invoice];
    }

    public function assignNumber(SalesOrder $order): void
    {
        if ($order->basket_number) {
            return;
        }
        $number = (string) $order->id;
        $suffix = 0;
        while (SalesOrder::where('store_id', $order->store_id)->where('basket_number', $number)->where('id', '!=', $order->id)->exists()) {
            $number = 'A-'.$order->id.'-'.(++$suffix);
        }
        $order->basket_number = $number;
        $order->save();
    }

    public function change(StoreContext $context, int $orderId, string $action, array $input, User $actor): array
    {
        abort_unless(in_array($action, ['cancel', 'invoice', 'deliver', 'payment', 'remove'], true), 404);
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'amount' => ['sometimes', new DecimalAmount], 'payment_method' => ['sometimes', 'in:cash,bank_transfer,card'], 'item_id' => ['sometimes', 'integer', 'min:1'], 'quantity' => ['sometimes', new DecimalAmount], 'deliver_now' => ['sometimes', 'boolean']])->validate();
        if ($action === 'payment') {
            if (! isset($data['amount'], $data['payment_method'])) {
                throw ValidationException::withMessages(['amount' => 'Indica el abono y su método.']);
            }
            $data['amount'] = Money::round(DecimalAmount::normalize($data['amount']));
            if (bccomp($data['amount'], '0', 4) <= 0) {
                throw ValidationException::withMessages(['amount' => 'El abono debe ser mayor que cero.']);
            }
        }
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $permission = $action === 'cancel' ? 'sales.cancel' : ($action === 'payment' ? 'payments.record' : 'sales.create');

        return $this->idempotency->run('sales.'.$action, $key, [$actor->id, $context->store()->id, $orderId], $data, fn () => $this->authorize($context, $actor, $permission), function () use ($context, $actor, $orderId, $action, $data, $key) {
            $reference = SalesOrder::where('store_id', $context->store()->id)->findOrFail($orderId);
            abort_unless($reference->warehouse_id, 409, 'El pedido histórico no tiene contexto de almacén.');
            [$warehouse, $variants, $balances, $products] = $this->lockStock($context, $reference->items()->pluck('variant_id')->all(), $reference->warehouse_id, false);
            $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($orderId);
            abort_if($order->lock_version !== $data['version'], 409, 'El pedido cambió. Actualiza antes de continuar.');
            abort_if($order->status === 'cancelled', 409, 'La canasta está cancelada.');
            $invoice = OrderInvoice::query($order->id)->lockForUpdate()->first();
            if ($invoice && in_array($action, ['payment', 'invoice'], true)) {
                abort_if(ReturnReceiptState::pendingInvoice($invoice->id, true), 409, 'La factura tiene una devolución recibida con ajuste económico pendiente. Concilia la devolución antes de registrar nuevos cobros.');
            }
            if ($action === 'payment') {
                $pending = OrderSettlement::position($order, $invoice?->id, true)['pending_total'];
                if (bccomp($data['amount'], $pending, 4) > 0) {
                    throw ValidationException::withMessages(['amount' => 'El abono supera el saldo pendiente.']);
                }
                $this->receivePayment($order, $data['amount'], $data['payment_method'], $key, $actor);
                if ($invoice) {
                    $this->allocatePayments($order, $invoice->id);
                }
            } elseif ($action === 'invoice') {
                if (! $invoice) {
                    $this->invoice($order, $actor);
                }
                if ($order->status === 'reserved') {
                    $order->status = 'confirmed';
                }
                if ($data['deliver_now'] ?? false) {
                    $this->deliver($order, $warehouse, $variants, $balances, $actor, $key);
                }
            } elseif ($action === 'deliver') {
                abort_if($order->status === 'fulfilled', 409, 'El pedido ya está entregado.');
                $this->deliver($order, $warehouse, $variants, $balances, $actor, $key, isset($data['item_id']) ? (int) $data['item_id'] : null, $data['quantity'] ?? null);
            } else {
                abort_if($invoice, 409, 'El pedido ya está facturado. Usa el flujo de devolución para cambios comerciales.');
                abort_if(bccomp($this->paid($order->id), '0', 4) > 0, 409, 'Hay abonos registrados. Deben resolverse explícitamente antes de retirar artículos o cancelar.');
                $items = $order->items()->orderBy('id')->get();
                $reservations = DB::table('stock_reservations')->whereIn('order_item_id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get();
                abort_if($reservations->contains(fn ($r) => bccomp((string) $r->fulfilled_quantity, '0', 4) > 0), 409, 'Hay entregas parciales; no se puede cancelar silenciosamente su historial.');
                $target = null;
                if ($action === 'remove') {
                    $target = $items->firstWhere('id', (int) ($data['item_id'] ?? 0));
                    abort_unless($target, 404);
                    abort_if($items->count() === 1, 409, 'Para retirar el último artículo cancela la canasta.');
                }
                foreach ($reservations as $reservation) {
                    if ($target && $reservation->order_item_id !== $target->id) {
                        continue;
                    }
                    $remaining = bcsub(bcsub((string) $reservation->quantity, (string) $reservation->fulfilled_quantity, 4), (string) $reservation->released_quantity, 4);
                    if (bccomp($remaining, '0', 4) <= 0) {
                        continue;
                    }
                    $this->ledger->record($warehouse, $reservation->variant_id, $balances[$reservation->variant_id], '0.0000', bcsub('0', $remaining, 4), 'release', 'order:'.$key.':'.$reservation->id, 'Liberación de pedido #'.$order->id, $actor, $reservation->id);
                    DB::table('stock_reservations')->where('id', $reservation->id)->update(['released_quantity' => bcadd((string) $reservation->released_quantity, $remaining, 4), 'status' => 'released', 'updated_at' => now()]);
                }
                if ($target) {
                    $target->removed_at = now();
                    $target->save();
                    $subtotal = bcsub($order->subtotal, $target->line_total, 4);
                    abort_if(bccomp($order->discount_total, $subtotal, 4) > 0, 409, 'El descuento excedería el nuevo subtotal.');
                    $order->subtotal = $subtotal;
                    $order->tax_total = Money::round(bcdiv(bcmul(bcsub($subtotal, $order->discount_total, 4), (string) ($order->tax_rate_snapshot ?? '0'), 8), '100', 8));
                    $order->grand_total = Money::round(bcadd(bcadd(bcsub($subtotal, $order->discount_total, 4), $order->tax_total, 4), $order->shipping_total, 4));
                } else {
                    $order->status = 'cancelled';
                    $order->cancelled_at = now();
                }
            }
            $order->lock_version++;
            $order->save();
            foreach ($products as $product) {
                if (in_array($action, ['cancel', 'remove', 'deliver'], true) || ($data['deliver_now'] ?? false)) {
                    $product->increment('lock_version');
                }
            }
            $this->audit->record('sales.order.'.$action, $order, after: ['status' => $order->status, 'version' => $order->lock_version, 'item_id' => $data['item_id'] ?? null], actor: $actor);

            return ['order' => $this->present($order)];
        });
    }

    private function deliver(SalesOrder $order, Warehouse $warehouse, $variants, $balances, User $actor, string $key, ?int $itemId = null, ?string $quantity = null): void
    {
        $items = $order->items()->orderBy('id')->get();
        if ($itemId) {
            abort_unless($items->contains('id', $itemId), 404);
        }
        $reservations = DB::table('stock_reservations')->whereIn('order_item_id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($reservations as $reservation) {
            if ($itemId && $reservation->order_item_id !== $itemId) {
                continue;
            }
            $remaining = bcsub(bcsub((string) $reservation->quantity, (string) $reservation->fulfilled_quantity, 4), (string) $reservation->released_quantity, 4);
            $deliver = $quantity !== null ? DecimalAmount::normalize($quantity) : $remaining;
            if (bccomp($remaining, '0', 4) <= 0) {
                continue;
            }
            if (bccomp($deliver, '0', 4) <= 0 || bccomp($deliver, $remaining, 4) > 0) {
                throw ValidationException::withMessages(['quantity' => 'La entrega debe ser positiva y no superar lo reservado pendiente.']);
            }
            $negative = bcsub('0', $deliver, 4);
            $this->ledger->record($warehouse, $reservation->variant_id, $balances[$reservation->variant_id], $negative, $negative, 'fulfill', 'deliver:'.$key.':'.$reservation->id, 'Entrega de pedido #'.$order->id, $actor, $reservation->id);
            $fulfilled = bcadd((string) $reservation->fulfilled_quantity, $deliver, 4);
            DB::table('stock_reservations')->where('id', $reservation->id)->update(['fulfilled_quantity' => $fulfilled, 'status' => bccomp($deliver, $remaining, 4) === 0 ? 'fulfilled' : 'active', 'updated_at' => now()]);
        }
        $pending = DB::table('stock_reservations')->whereIn('order_item_id', $items->pluck('id'))->where('status', 'active')->lockForUpdate()->get()->count();
        if ($pending === 0) {
            $order->status = 'fulfilled';
            $order->fulfilled_at = now();
        }
    }

    public function paid(int $orderId): string
    {
        $total = '0.0000';
        $query = DB::table('order_payments')->where('order_id', $orderId)->join('payments', 'payments.id', '=', 'order_payments.payment_id')->where('payments.status', 'confirmed');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        foreach ($query->pluck('payments.amount') as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        return $total;
    }

    private function receivePayment(SalesOrder $order, string $amount, string $method, string $eventKey, User $actor): int
    {
        $paymentId = DB::table('payments')->insertGetId(['store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'payment_method' => $method, 'amount' => $amount, 'status' => 'confirmed', 'paid_at' => now(), 'notes' => 'Abono de pedido #'.$order->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_payments')->insert(['order_id' => $order->id, 'payment_id' => $paymentId, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'created_at' => now()]);
        DB::table('customer_credit_movements')->insert(['payment_id' => $paymentId, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'event_type' => 'receipt', 'amount_delta' => $amount, 'event_key' => 'payment:'.$eventKey, 'occurred_at' => now(), 'created_at' => now()]);
        $this->audit->record('sales.payment.received', $order, after: ['payment_id' => $paymentId, 'amount' => $amount, 'method' => $method], actor: $actor);

        return $paymentId;
    }

    private function invoice(SalesOrder $order, User $actor): int
    {
        $existing = OrderInvoice::query($order->id)->lockForUpdate()->first();
        if ($existing) {
            return $existing->id;
        }
        abort_if(! $order->items()->exists(), 422, 'Agrega artículos antes de cerrar el apartado.');
        $customer = Customer::withTrashed()->whereKey($order->customer_id)->sharedLock()->firstOrFail();
        $order->customer_snapshot = $customer->only(['full_name', 'phone', 'email', 'address']);
        $order->closed_at = now();
        $order->save();
        $number = OrderInvoice::number($order->store_id);
        $invoiceId = DB::table('invoices')->insertGetId(['store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'order_id' => $order->id, 'invoice_number' => $number, 'status' => 'issued', 'currency' => $order->currency, 'subtotal' => $order->subtotal, 'discount_total' => $order->discount_total, 'tax_total' => $order->tax_total, 'shipping_total' => $order->shipping_total, 'grand_total' => $order->grand_total, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoices')->where('id', $invoiceId)->update(['business_snapshot' => json_encode(BusinessProfile::current()->only(['name', 'legal_name', 'timezone', 'locale']), JSON_THROW_ON_ERROR)]);
        DB::table('invoice_orders')->insert(['invoice_id' => $invoiceId, 'order_id' => $order->id, 'direct_order_id' => $order->id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency]);
        foreach ($order->items()->get() as $item) {
            DB::table('invoice_items')->insert(['invoice_id' => $invoiceId, 'order_item_id' => $item->id, 'order_id' => $order->id, 'variant_id' => $item->variant_id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'description' => $item->product_name_snapshot, 'sku_snapshot' => $item->sku_snapshot, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount_amount' => $item->line_discount, 'tax_amount' => $item->line_tax, 'line_total' => $item->line_total, 'created_at' => now()]);
        }
        DB::table('invoice_documents')->insert(['invoice_id' => $invoiceId, 'version' => 1, 'provider' => 'local', 'bucket_name' => 'private_invoices', 'status' => 'pending', 'created_at' => now()]);
        $this->allocatePayments($order, $invoiceId);
        $this->audit->record('sales.invoice.issued', $order, after: ['invoice_id' => $invoiceId, 'invoice_number' => $number], actor: $actor);

        return $invoiceId;
    }

    public function allocatePayments(SalesOrder $order, int $invoiceId): void
    {
        $payments = DB::table('order_payments')->where('order_id', $order->id)->join('payments', 'payments.id', '=', 'order_payments.payment_id')->where('payments.status', 'confirmed')->select('payments.*')->orderBy('payments.id')->lockForUpdate()->get();
        foreach ($payments as $payment) {
            if (DB::table('payment_allocations')->where('invoice_id', $invoiceId)->where('payment_id', $payment->id)->lockForUpdate()->first()) {
                continue;
            }
            $allocation = DB::table('payment_allocations')->insertGetId(['payment_id' => $payment->id, 'invoice_id' => $invoiceId, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'event_key' => 'invoice:'.$invoiceId.':payment:'.$payment->id, 'amount' => $payment->amount, 'created_at' => now()]);
            DB::table('customer_credit_movements')->insert(['payment_id' => $payment->id, 'payment_allocation_id' => $allocation, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'event_type' => 'allocation', 'amount_delta' => bcsub('0', (string) $payment->amount, 4), 'event_key' => 'allocation:'.$allocation, 'occurred_at' => now(), 'created_at' => now()]);
        }
        $this->refreshInvoiceBalance($invoiceId);
    }

    public function refreshInvoiceBalance(int $invoiceId): void
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->lockForUpdate()->firstOrFail();
        abort_unless(in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true), 409, 'La factura requiere conciliación de sus ajustes.');
        $position = OrderSettlement::invoice($invoice, true);
        $paid = $position['paid_total'];
        DB::table('invoices')->where('id', $invoiceId)->update(['status' => bccomp($paid, $position['net_total'], 4) === 0 ? 'paid' : (bccomp($paid, '0', 4) > 0 ? 'partially_paid' : 'issued'), 'updated_at' => now()]);
    }
}
