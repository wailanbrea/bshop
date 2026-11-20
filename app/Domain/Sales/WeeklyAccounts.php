<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\WeeklyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class WeeklyAccounts
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    public function find(StoreContext $context, int $id): WeeklyAccount
    {
        return WeeklyAccount::where('store_id', $context->store()->id)->findOrFail($id);
    }

    public function create(StoreContext $context, User $actor, array $input): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'customer_id' => ['required', 'integer', 'min:1'], 'period_start' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-25']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['customer_id'] = (int) $data['customer_id'];
        $result = $this->once->run('weekly.create', $key, [$actor->id, $context->store()->id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $actor, $data) {
            DB::table('weekly_cycle_locks')->insertOrIgnore(['store_id' => $context->store()->id, 'customer_id' => $data['customer_id']]);
            DB::table('weekly_cycle_locks')->where('store_id', $context->store()->id)->where('customer_id', $data['customer_id'])->lockForUpdate()->firstOrFail();
            Store::whereKey($context->store()->id)->where('is_active', true)->firstOrFail();
            Customer::where('status', 'active')->findOrFail($data['customer_id']);
            $profile = BusinessProfile::current();
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['period_start'], $profile->timezone)->addDays(6)->format('Y-m-d');
            abort_if(WeeklyAccount::where('store_id', $context->store()->id)->where('customer_id', $data['customer_id'])->where('status', '!=', 'cancelled')->where('period_start', '<=', $end)->where('period_end', '>=', $data['period_start'])->lockForUpdate()->get()->isNotEmpty(), 409, 'El cliente ya tiene una cuenta que se solapa con este período.');
            $account = WeeklyAccount::where('store_id', $context->store()->id)->where('customer_id', $data['customer_id'])->where('period_start', $data['period_start'])->where('period_end', $end)->lockForUpdate()->first();
            if ($account) {
                abort_unless($account->status === 'cancelled' && ! SalesOrder::where('weekly_account_id', $account->id)->lockForUpdate()->first() && ! DB::table('invoices')->where('weekly_account_id', $account->id)->lockForUpdate()->first(), 409, 'El período ya conserva operaciones.');
                $account->status = 'open';
                $account->currency = $profile->default_currency;
                $account->timezone = $profile->timezone;
                $account->lock_version++;
                $account->save();
                $this->audit->record('weekly.reopened', $account, actor: $actor);

                return ['account_id' => $account->id];
            }
            $account = WeeklyAccount::create(['store_id' => $context->store()->id, 'customer_id' => $data['customer_id'], 'period_start' => $data['period_start'], 'period_end' => $end, 'timezone' => $profile->timezone, 'currency' => $profile->default_currency]);
            $this->audit->record('weekly.created', $account, actor: $actor);

            return ['account_id' => $account->id];
        });

        return ['account' => $this->present($this->find($context, $result['account_id'])), 'replay' => $result['replay']];
    }

    public function cancel(StoreContext $context, int $id, User $actor, array $input): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $result = $this->once->run('weekly.cancel', $key, [$actor->id, $context->store()->id, $id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $id, $actor, $data) {
            $account = WeeklyAccount::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($account->status === 'open' && $account->lock_version === $data['version'], 409, 'La cuenta cambió o ya está cerrada.');
            abort_if(SalesOrder::where('weekly_account_id', $id)->lockForUpdate()->get(['id'])->isNotEmpty(), 409, 'Retira los pedidos antes de anular una cuenta vacía.');
            $account->status = 'cancelled';
            $account->lock_version++;
            $account->save();
            $this->audit->record('weekly.cancelled', $account, actor: $actor);

            return ['account_id' => $id];
        });

        return ['account' => $this->present($this->find($context, $id)), 'replay' => $result['replay']];
    }

    public function bounds(WeeklyAccount $account): array
    {
        $zone = $account->timezone ?: BusinessProfile::current()->timezone;

        return [CarbonImmutable::parse($account->period_start, $zone)->startOfDay()->utc(), CarbonImmutable::parse($account->period_end, $zone)->startOfDay()->addDay()->utc()];
    }

    public function candidates(WeeklyAccount $account)
    {
        [$start, $end] = $this->bounds($account);

        return SalesOrder::where('store_id', $account->store_id)->where('customer_id', $account->customer_id)->where('currency', $account->currency)
            ->where('order_kind', 'reservation')->whereIn('status', ['reserved', 'fulfilled'])->whereNull('weekly_account_id')->whereNull('closed_at')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoice_orders')->whereColumn('order_id', 'orders.id'));
    }

    public function membership(StoreContext $context, int $id, string $action, User $actor, array $input): array
    {
        abort_unless(in_array($action, ['attach', 'detach'], true), 404);
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'order_id' => ['required', 'integer', 'min:1']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $data['order_id'] = (int) $data['order_id'];
        $result = $this->once->run('weekly.'.$action, $key, [$actor->id, $context->store()->id, $id], $data, fn () => $this->sales->authorize($context, $actor), function () use ($context, $id, $action, $actor, $data) {
            $account = WeeklyAccount::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($account->status === 'open' && $account->lock_version === $data['version'], 409, 'La cuenta cambió o ya está cerrada. Actualiza la revisión.');
            $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($data['order_id']);
            if ($action === 'attach') {
                abort_unless($this->candidates($account)->whereKey($order->id)->lockForUpdate()->first(), 409, 'El pedido no corresponde al cliente/período/moneda o ya está agrupado/facturado.');
                abort_if(SalesOrder::where('weekly_account_id', $id)->lockForUpdate()->get(['id'])->count() >= 50, 409, 'La cuenta admite hasta 50 pedidos.');
                $order->weekly_account_id = $account->id;
            } else {
                abort_unless($order->weekly_account_id === $account->id, 404);
                $order->weekly_account_id = null;
            }
            $order->lock_version++;
            $order->save();
            $account->lock_version++;
            $account->save();
            $this->audit->record('weekly.order.'.$action, $account, after: ['order_id' => $order->id, 'version' => $account->lock_version], actor: $actor);

            return ['account_id' => $id];
        });

        return ['account' => $this->present($this->find($context, $id)), 'replay' => $result['replay']];
    }

    public function present(WeeklyAccount $account, bool $locked = false): array
    {
        $rows = [];
        $totals = array_fill_keys(['subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total', 'paid_total', 'return_credit_total', 'refunded_total', 'credit_available'], '0.0000');
        $invoice = DB::table('invoices')->where('weekly_account_id', $account->id)->first();
        $ordersQuery = SalesOrder::where('weekly_account_id', $account->id)->orderBy('id');
        if ($locked) {
            $ordersQuery->lockForUpdate();
        }
        foreach ($ordersQuery->get() as $order) {
            $sourceQuery = OrderInvoice::query($order->id);
            if ($locked) {
                $sourceQuery->lockForUpdate();
            }
            $source = $sourceQuery->first();
            $included = in_array($order->status, ['reserved', 'confirmed', 'fulfilled'], true) && (! $source || $source->weekly_account_id === $account->id);
            $position = OrderSettlement::position($order, $source?->id, $locked);
            $paid = $position['paid_total'];
            if ($included) {
                foreach (['subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total'] as $field) {
                    $totals[$field] = bcadd($totals[$field], $order->$field, 4);
                }
                $totals['paid_total'] = bcadd($totals['paid_total'], $paid, 4);
                foreach (['return_credit_total', 'refunded_total', 'credit_available'] as $field) {
                    $totals[$field] = bcadd($totals[$field], $position[$field], 4);
                }
            }
            $rows[] = ['id' => $order->id, 'number' => $order->basket_number ?: $order->id, 'version' => $order->lock_version, 'status' => $order->status, 'included' => $included,
                'total' => $order->grand_total, 'net_total' => $position['net_total'], 'return_credit_total' => $position['return_credit_total'], 'paid' => $paid, 'pending' => $order->status === 'cancelled' ? '0.0000' : $position['pending_total'], 'invoice_id' => $source?->id];
        }
        $totals['net_total'] = bcsub($totals['grand_total'], $totals['return_credit_total'], 4);
        $totals['pending_total'] = bcsub($totals['net_total'], $totals['paid_total'], 4);
        $result = [...$account->toArray(), 'return_pending' => $invoice ? ReturnReceiptState::pendingInvoice($invoice->id) : false, 'customer_name' => $account->customer_snapshot['full_name'] ?? Customer::withTrashed()->find($account->customer_id)?->full_name,
            'orders' => $rows, 'totals' => $totals, 'invoice' => $invoice ? ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status] : null];
        $result['review_hash'] = hash('sha256', json_encode([$account->id, $account->lock_version, $rows, $totals], JSON_THROW_ON_ERROR));

        return $result;
    }

    public function close(StoreContext $context, int $id, User $actor, array $input): array
    {
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $result = $this->once->run('weekly.close', $key, [$actor->id, $context->store()->id, $id], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor);
            $this->sales->authorize($context, $actor, 'invoices.view');
        }, function () use ($context, $id, $actor, $data) {
            $account = WeeklyAccount::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($account->status === 'open' && $account->lock_version === $data['version'], 409, 'La cuenta cambió o ya está cerrada.');
            $orderIds = SalesOrder::where('weekly_account_id', $id)->orderBy('id')->pluck('id');
            $variantIds = DB::table('order_items')->whereIn('order_id', $orderIds)->whereNull('removed_at')->distinct()->pluck('variant_id')->all();
            $references = ProductVariant::withTrashed()->whereIn('id', $variantIds)->get();
            Product::withTrashed()->whereIn('id', $references->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($account->store_id)->lockForUpdate()->firstOrFail();
            $orders = SalesOrder::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            $currentIds = DB::table('order_items')->whereIn('order_id', $orderIds)->whereNull('removed_at')->orderBy('id')->lockForUpdate()->get(['id', 'variant_id'])->pluck('variant_id')->unique()->all();
            abort_if(array_diff($currentIds, $variantIds), 409, 'Los artículos cambiaron durante la revisión.');
            $review = $this->present($account, true);
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, 'Los pedidos o abonos cambiaron. Revisa el total actualizado.');
            $included = collect($review['orders'])->where('included', true)->pluck('id')->all();
            abort_unless(count($included), 422, 'No hay pedidos pendientes de facturar en esta cuenta.');
            foreach ($review['totals'] as $value) {
                Money::round($value);
            }
            $customer = Customer::withTrashed()->whereKey($account->customer_id)->sharedLock()->firstOrFail();
            $account->customer_snapshot = $customer->only(['full_name', 'phone', 'email', 'address']);
            $account->paid_at_close = $review['totals']['paid_total'];
            $account->status = 'closed';
            $account->closed_at = now();
            $account->lock_version++;
            $account->save();
            $profile = BusinessProfile::current();
            $storeRecord = Store::find($account->store_id);
            $businessSnapshot = [
                'name' => $storeRecord?->name ?: $profile->name,
                'legal_name' => ($storeRecord && $storeRecord->name !== $profile->name) ? null : $profile->legal_name,
                'store_code' => $storeRecord?->code,
                'phone' => $storeRecord?->phone,
                'address' => $storeRecord?->address,
                'timezone' => $profile->timezone,
                'locale' => $profile->locale,
            ];
            $invoiceId = DB::table('invoices')->insertGetId(['store_id' => $account->store_id, 'customer_id' => $account->customer_id, 'weekly_account_id' => $id,
                'invoice_number' => OrderInvoice::number($account->store_id), 'status' => 'issued', 'currency' => $account->currency,
                ...array_intersect_key($review['totals'], array_flip(['subtotal', 'discount_total', 'tax_total', 'shipping_total', 'grand_total'])),
                'business_snapshot' => json_encode($businessSnapshot, JSON_THROW_ON_ERROR), 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ($orders->whereIn('id', $included) as $order) {
                abort_unless($order->currency === $account->currency && $order->customer_id === $account->customer_id && $order->store_id === $account->store_id, 409);
                $items = $order->items()->orderBy('id')->lockForUpdate()->get();
                abort_unless($items->isNotEmpty(), 422, 'Hay un pedido sin artículos.');
                DB::table('invoice_orders')->insert(['invoice_id' => $invoiceId, 'order_id' => $order->id, 'weekly_account_id' => $id, 'store_id' => $account->store_id, 'customer_id' => $account->customer_id, 'currency' => $account->currency]);
                foreach ($items as $item) {
                    DB::table('invoice_items')->insert(['invoice_id' => $invoiceId, 'order_item_id' => $item->id, 'order_id' => $order->id, 'variant_id' => $item->variant_id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'description' => $item->product_name_snapshot, 'sku_snapshot' => $item->sku_snapshot, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'discount_amount' => $item->line_discount, 'tax_amount' => $item->line_tax, 'line_total' => $item->line_total, 'created_at' => now()]);
                }
                $order->customer_snapshot = $account->customer_snapshot;
                $order->closed_at = now();
                if ($order->status === 'reserved') {
                    $order->status = 'confirmed';
                } $order->lock_version++;
                $order->save();
                $this->sales->allocatePayments($order, $invoiceId);
            }
            DB::table('invoice_documents')->insert(['invoice_id' => $invoiceId, 'version' => 1, 'provider' => 'local', 'bucket_name' => 'private_invoices', 'status' => 'pending', 'created_at' => now()]);
            $this->audit->record('weekly.closed', $account, after: ['invoice_id' => $invoiceId, 'order_ids' => $included, 'totals' => $review['totals']], actor: $actor);

            return ['account_id' => $id];
        });

        return ['account' => $this->present($this->find($context, $id)), 'replay' => $result['replay']];
    }
}
