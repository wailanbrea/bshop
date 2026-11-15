<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Rules\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CreditApplications
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function describe(SalesOrder $order, object $invoice, bool $lock = false): array
    {
        $balance = OrderSettlement::position($order, $invoice->id, $lock);
        $held = ReturnReceiptState::pendingInvoice($invoice->id, $lock);
        $allowed = ! $held && $order->status !== 'cancelled' && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true);
        $sources = [];
        $q = DB::table('payment_allocation_releases as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->join('order_payments as op', 'op.payment_id', '=', 'p.id')->where('p.store_id', $order->store_id)->where('p.customer_id', $order->customer_id)->where('p.currency', $order->currency)->where('p.status', 'confirmed')->orderBy('r.id');
        if ($lock) {
            $q->lockForUpdate();
        }
        $ids = $q->get(['p.id'])->pluck('id')->unique();
        foreach ($ids as $id) {
            $fund = OrderSettlement::payment($id, $lock);
            $rows = array_values(array_filter(CreditPools::rows($id, $lock), fn ($row) => $row['order_id'] !== $order->id && bccomp($row['available'], '0', 4) > 0));
            $total = OrderSettlement::sum(array_column($rows, 'available'));
            if (bccomp($total, $fund['available'], 4) > 0) {
                $total = $fund['available'];
            }
            $pending = DB::table('refunds')->where('original_payment_id', $id)->where('status', 'pending');
            if ($lock) {
                $pending->lockForUpdate();
            }$uncertain = (bool) $pending->first();
            if ($uncertain || bccomp($total, '0', 4) <= 0) {
                continue;
            }
            $origin = DB::table('order_payments')->where('payment_id', $id)->firstOrFail();
            $originOrder = SalesOrder::findOrFail($origin->order_id);
            $sources[] = ['payment_id' => (int) $id, 'origin_order_id' => (int) $origin->order_id, 'origin_number' => $originOrder->basket_number ?: $originOrder->id, 'method' => $fund['payment']->payment_method, 'available' => $total, 'pools' => $rows];
        }
        $result = ['order_id' => $order->id, 'version' => $order->lock_version, 'currency' => $order->currency, 'invoice_number' => $invoice->invoice_number, 'balance' => $balance, 'application_allowed' => $allowed, 'return_pending' => $held, 'sources' => $sources];
        $result['review_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function info(StoreContext $context, int $id): array
    {
        return DB::transaction(function () use ($context, $id) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();
            $order = SalesOrder::where('store_id', $context->store()->id)->sharedLock()->findOrFail($id);
            $invoice = OrderInvoice::query($id)->sharedLock()->first();
            abort_unless($invoice, 409, 'Factura el pedido antes de aplicar saldo a favor.');
            $result = $this->describe($order, $invoice);
            $result['history'] = DB::table('credit_applications')->where('target_order_id', $id)->orderByDesc('id')->limit(20)->get(['id', 'payment_id', 'origin_order_id', 'amount', 'reason', 'actor_name', 'created_at']);
            foreach ($result['history'] as $entry) {
                $entry->amount = bcadd((string) $entry->amount, '0', 4);
                $entry->reversal = DB::table('credit_application_reversals')->where('credit_application_id', $entry->id)->first(['id', 'reason', 'actor_name', 'created_at']);
            }

            return $result;
        });
    }

    public function apply(StoreContext $context, int $id, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'payment_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', new DecimalAmount], 'reason' => ['required', 'string', 'min:5', 'max:255']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $data['payment_id'] = (int) $data['payment_id'];
        $data['amount'] = Money::round(DecimalAmount::normalize($data['amount']));
        abort_unless(bccomp($data['amount'], '0', 4) > 0, 422, 'El crédito a aplicar debe ser positivo.');
        $result = $this->once->run('credits.apply', $key, [$actor->id, $context->store()->id, $id, $data['payment_id']], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'credits.apply');
        }, function () use ($context, $id, $actor, $data, $key) {
            $targetRef = SalesOrder::where('store_id', $context->store()->id)->findOrFail($id);
            $paymentRef = DB::table('payments')->where('id', $data['payment_id'])->where('store_id', $context->store()->id)->where('customer_id', $targetRef->customer_id)->where('currency', $targetRef->currency)->firstOrFail();
            $origin = DB::table('order_payments')->where('payment_id', $paymentRef->id)->firstOrFail();
            $orders = DB::table('payment_allocation_releases')->where('payment_id', $paymentRef->id)->pluck('order_id')->push($origin->order_id)->push($id)->unique()->sort()->values()->all();
            $variantIds = DB::table('order_items')->whereIn('order_id', $orders)->whereNull('removed_at')->pluck('variant_id')->unique();
            $variants = ProductVariant::withTrashed()->whereIn('id', $variantIds)->get();
            Product::withTrashed()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('payment_allocation_releases')->where('payment_id', $paymentRef->id)->orderBy('id')->lockForUpdate()->get()->pluck('order_id')->push($origin->order_id)->push($id)->unique()->all();
            abort_if(array_diff($current, $orders), 409, 'El origen del crédito cambió. Actualiza la revisión.');
            $lockedOrders = SalesOrder::whereIn('id', $orders)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $order = $lockedOrders[$id];
            abort_unless($order->lock_version === $data['version'] && $order->status !== 'cancelled', 409, 'El pedido cambió o está cancelado.');
            foreach ($lockedOrders as $row) {
                abort_unless($row->store_id === $order->store_id && $row->customer_id === $order->customer_id && $row->currency === $order->currency, 409, 'El crédito cruza cliente, moneda o sucursal.');
            }
            $map = DB::table('invoice_orders')->whereIn('order_id', $orders)->orderBy('order_id')->lockForUpdate()->get();
            $invoices = DB::table('invoices')->whereIn('id', $map->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $targetSource = $map->firstWhere('order_id', $id);
            abort_unless($targetSource, 409, 'El destino requiere una factura.');
            $invoice = $invoices[$targetSource->invoice_id];
            abort_if(DB::table('commission_entries')->where('invoice_id', $invoice->id)->lockForUpdate()->first(), 409, 'La factura tiene comisiones pendientes de conciliación.');
            $review = $this->describe($order, $invoice, true);
            abort_unless($review['application_allowed'], 409, 'El destino está retenido por devolución o requiere conciliación.');
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, 'El saldo o el origen del crédito cambió. Revisa los importes.');
            $source = collect($review['sources'])->firstWhere('payment_id', $data['payment_id']);
            abort_unless($source, 409, 'El crédito ya no está disponible para este pedido.');
            abort_unless(bccomp($data['amount'], $source['available'], 4) <= 0 && bccomp($data['amount'], $review['balance']['pending_total'], 4) <= 0, 422, 'El importe excede el saldo a favor o la deuda neta del destino.');
            $fund = OrderSettlement::payment($data['payment_id'], true);
            abort_unless($fund['payment']->status === 'confirmed' && bccomp($fund['available'], $data['amount'], 4) >= 0 && bccomp(bcadd(bcadd($fund['allocated'], $fund['legacy_credit'], 4), $fund['refunded'], 4), (string) $fund['payment']->amount, 4) === 0, 409, 'El pago y su crédito requieren conciliación.');
            $allocation = DB::table('payment_allocations')->insertGetId(['payment_id' => $data['payment_id'], 'invoice_id' => $invoice->id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'event_key' => 'credit-apply:'.strtolower($key), 'amount' => $data['amount'], 'created_at' => now()]);
            $application = DB::table('credit_applications')->insertGetId(['payment_allocation_id' => $allocation, 'payment_id' => $data['payment_id'], 'origin_order_id' => $origin->order_id, 'target_order_id' => $id, 'invoice_id' => $invoice->id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'amount' => $data['amount'], 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'created_at' => now()]);
            $remaining = $data['amount'];
            $owners = [];
            foreach ($source['pools'] as $pool) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }$take = bccomp($pool['available'], $remaining, 4) > 0 ? $remaining : $pool['available'];
                if (bccomp($take, '0', 4) <= 0) {
                    continue;
                }DB::table('credit_application_sources')->insert(['credit_application_id' => $application, 'release_id' => $pool['release_id'], 'amount' => $take]);
                $owners[] = $pool['order_id'];
                $remaining = bcsub($remaining, $take, 4);
            }
            abort_unless(bccomp($remaining, '0', 4) === 0, 409, 'Las liberaciones del crédito no cubren el importe.');
            DB::table('customer_credit_movements')->insert(['payment_id' => $data['payment_id'], 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'payment_allocation_id' => $allocation, 'event_type' => 'allocation', 'amount_delta' => bcsub('0', $data['amount'], 4), 'event_key' => 'credit-application:'.$application, 'occurred_at' => now(), 'created_at' => now()]);
            foreach (array_unique([...$owners, $id]) as $orderId) {
                $row = $lockedOrders[$orderId];
                $row->lock_version++;
                $row->save();
            }
            $this->sales->refreshInvoiceBalance($invoice->id);
            app(FinancialDocument::class)->capture('credit', $application);
            $this->audit->record('sales.credit.applied', $order, after: ['credit_application_id' => $application, 'payment_id' => $data['payment_id'], 'origin_order_id' => $origin->order_id, 'target_order_id' => $id, 'amount' => $data['amount'], 'reason' => $data['reason']], actor: $actor);

            return ['order_id' => $id, 'credit_application_id' => $application];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($id)), 'credit_application_id' => $result['credit_application_id'], 'replay' => $result['replay']];
    }
}
