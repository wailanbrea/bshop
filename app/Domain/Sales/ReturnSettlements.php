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
use Illuminate\Validation\ValidationException;

final class ReturnSettlements
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function locks(StoreContext $context, int $orderId): void
    {
        $ref = SalesOrder::where('store_id', $context->store()->id)->findOrFail($orderId);
        $ids = $ref->items()->pluck('variant_id');
        $variants = ProductVariant::withTrashed()->whereIn('id', $ids)->get();
        Product::withTrashed()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
        ProductVariant::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
    }

    private function scope(StoreContext $context, int $orderId, int $returnId, bool $write): array
    {
        $query = SalesOrder::where('store_id', $context->store()->id);
        $write ? $query->lockForUpdate() : $query->sharedLock();
        $order = $query->findOrFail($orderId);
        $query = OrderInvoice::query($orderId);
        $write ? $query->lockForUpdate() : $query->sharedLock();
        $invoice = $query->firstOrFail();
        DB::table('sales_return_receipts')->where('sales_return_id', $returnId)->where('order_id', $orderId)->where('invoice_id', $invoice->id)->where('store_id', $context->store()->id)->firstOrFail();
        $query = DB::table('sales_returns')->where('id', $returnId)->where('invoice_id', $invoice->id);
        $write ? $query->lockForUpdate() : $query->sharedLock();
        $receipt = $query->firstOrFail();
        abort_unless($order->currency === $invoice->currency && $receipt->currency === $order->currency, 409, 'Los documentos requieren conciliación de moneda.');

        return [$order, $invoice, $receipt];
    }

    private function proposal(SalesOrder $order, object $invoice, object $receipt, string $shipping, bool $lock): array
    {
        $q = DB::table('invoice_items')->where('invoice_id', $invoice->id)->where('order_id', $order->id)->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }$items = $q->get();
        $raw = OrderSettlement::sum($items->pluck('line_total'));
        $target = bcadd(bcsub($order->subtotal, $order->discount_total, 4), $order->tax_total, 4);
        abort_unless(bccomp($raw, $order->subtotal, 4) === 0 && bccomp($target, '0', 4) >= 0 && (bccomp($raw, '0', 4) > 0 || bccomp($target, '0', 4) === 0), 409, 'Los importes originales requieren conciliación.');
        abort_unless(bccomp(bcadd($target, $order->shipping_total, 4), $order->grand_total, 4) === 0, 409, 'El total original no coincide con sus componentes.');
        $entitlements = [];
        $cumulative = '0.0000';
        $previous = '0.0000';
        foreach ($items as $item) {
            $cumulative = bcadd($cumulative, (string) $item->line_total, 4);
            $next = bccomp($raw, '0', 4) > 0 ? Money::round(bcdiv(bcmul($target, $cumulative, 12), $raw, 12)) : '0.0000';
            $entitlements[$item->id] = ['item' => $item, 'amount' => bcsub($next, $previous, 4)];
            $previous = $next;
        }
        $q = DB::table('sales_return_items')->where('sales_return_id', $receipt->id)->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }$details = [];
        $merchandise = '0.0000';
        foreach ($q->get() as $line) {
            abort_unless(bccomp((string) $line->refund_amount, '0', 4) === 0, 409, 'La recepción ya tiene importes económicos que requieren conciliación.');
            abort_unless(isset($entitlements[$line->invoice_item_id]), 409, 'La recepción contiene un artículo ajeno a este pedido.');
            $original = $entitlements[$line->invoice_item_id];
            $item = $original['item'];
            $settled = OrderSettlement::total(AdjustmentState::effective(DB::table('return_adjustment_revision_items as ri')->join('return_adjustments as a', 'a.id', '=', 'ri.adjustment_id')->where('ri.invoice_item_id', $item->id)->orderBy('ri.adjustment_id')->orderBy('ri.sales_return_item_id'), 'a', $lock), 'ri.quantity', $lock);
            $after = bcadd($settled, (string) $line->quantity, 4);
            abort_unless(bccomp((string) $item->quantity, '0', 4) > 0 && bccomp($after, (string) $item->quantity, 4) <= 0, 409, 'Las cantidades económicas exceden el artículo facturado.');
            $beforeAmount = Money::round(bcdiv(bcmul($original['amount'], $settled, 12), (string) $item->quantity, 12));
            $afterAmount = Money::round(bcdiv(bcmul($original['amount'], $after, 12), (string) $item->quantity, 12));
            $amount = bcsub($afterAmount, $beforeAmount, 4);
            $merchandise = bcadd($merchandise, $amount, 4);
            $details[] = ['sales_return_item_id' => (int) $line->id, 'invoice_item_id' => (int) $item->id, 'name' => $item->description, 'quantity' => bcadd((string) $line->quantity, '0', 4), 'credit_amount' => $amount, 'item_entitlement' => $original['amount']];
        }
        abort_unless(count($details) > 0, 422, 'La recepción no contiene artículos.');
        $priorShipping = OrderSettlement::total(AdjustmentState::effective(DB::table('return_adjustments')->where('order_id', $order->id)->orderBy('id'), 'return_adjustments', $lock), 'shipping_credit', $lock);
        $shippingAvailable = bcsub($order->shipping_total, $priorShipping, 4);
        if (bccomp($shipping, $shippingAvailable, 4) > 0) {
            throw ValidationException::withMessages(['shipping_credit' => 'El envío a acreditar excede el envío original pendiente.']);
        }
        $before = OrderSettlement::position($order, $invoice->id, $lock);
        $amount = bcadd($merchandise, $shipping, 4);
        $net = bcsub($before['net_total'], $amount, 4);
        abort_if(bccomp($net, '0', 4) < 0, 422, 'El ajuste excede el total original pendiente de acreditar.');
        $needed = bcsub($before['paid_total'], $net, 4);
        if (bccomp($needed, '0', 4) < 0) {
            $needed = '0.0000';
        }abort_if(bccomp($needed, $amount, 4) > 0, 409, 'Las asignaciones previas requieren conciliación.');
        $release = [];
        $remaining = $needed;
        $q = CreditPools::allocations($order->id, $invoice->id, $lock)->orderByDesc('p.id')->orderByDesc('a.id');
        if ($lock) {
            $q->lockForUpdate();
        }
        foreach ($q->get() as $allocation) {
            $fund = OrderSettlement::payment($allocation->payment_id, $lock);
            abort_unless(bccomp($fund['available'], '0', 4) >= 0 && bccomp(bcadd(bcadd($fund['allocated'], $fund['legacy_credit'], 4), $fund['historical_refunded'], 4), (string) $fund['payment']->amount, 4) === 0, 409, 'El crédito del pago requiere conciliación.');
            if (bccomp($remaining, '0', 4) <= 0) {
                continue;
            }
            $prior = OrderSettlement::total(AdjustmentState::releases(DB::table('payment_allocation_releases')->where('payment_allocation_id', $allocation->id)->orderBy('id'), 'payment_allocation_releases', $lock), 'amount', $lock);
            $available = bcsub((string) $allocation->amount, $prior, 4);
            abort_if(bccomp($available, '0', 4) < 0, 409, 'Una asignación tiene liberaciones inconsistentes.');
            $take = bccomp($available, $remaining, 4) > 0 ? $remaining : $available;
            if (bccomp($take, '0', 4) > 0) {
                $release[] = ['payment_allocation_id' => (int) $allocation->id, 'payment_id' => (int) $allocation->payment_id, 'amount' => $take];
                $remaining = bcsub($remaining, $take, 4);
            }
        }
        abort_unless(bccomp($remaining, '0', 4) === 0, 409, 'No hay asignaciones suficientes para liberar el crédito.');
        $result = ['sales_return_id' => (int) $receipt->id, 'order_id' => $order->id, 'version' => $order->lock_version, 'invoice_number' => $invoice->invoice_number, 'currency' => $order->currency, 'lines' => $details, 'shipping_credit' => $shipping, 'shipping_available' => $shippingAvailable, 'merchandise_credit' => $merchandise, 'amount' => $amount, 'net_after' => $net, 'credit_released' => $needed, 'debt_reduction' => bcsub($amount, $needed, 4), 'before' => $before, 'releases' => $release];
        $result['review_hash'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    private function state(SalesOrder $order, object $invoice, object $receipt): array
    {
        $adjustment = AdjustmentState::current($receipt->id);
        abort_unless($adjustment, 409, __('adjustment_reversal.no_current'));
        $sources = [];
        foreach (DB::table('payment_allocation_releases')->where('adjustment_id', $adjustment->id)->orderBy('id')->get() as $release) {
            $id = (int) $release->payment_id;
            $sources[$id] ??= ['payment_id' => $id, 'released' => '0.0000'];
            $sources[$id]['released'] = bcadd($sources[$id]['released'], (string) $release->amount, 4);
        }
        foreach ($sources as $id => &$source) {
            $fund = OrderSettlement::payment($id);
            $refunded = OrderSettlement::total(RefundState::effective(DB::table('refunds')->where('sales_return_id', $receipt->id)->where('original_payment_id', $id)->where('status', 'confirmed')->orderBy('id')), 'amount');
            $spent = '0.0000';
            foreach (CreditPools::rows($id) as $pool) {
                if ($pool['adjustment_id'] === (int) $adjustment->id) {
                    $spent = bcadd($spent, $pool['used'], 4);
                }
            }
            $remaining = bcsub(bcsub($source['released'], $spent, 4), $refunded, 4);
            $pending = DB::table('refunds')->where('original_payment_id', $id)->where('status', 'pending')->exists();
            $max = bccomp($remaining, $fund['available'], 4) > 0 ? $fund['available'] : $remaining;
            if ($pending || bccomp($max, '0', 4) < 0) {
                $max = '0.0000';
            }$source = [...$source, 'applied_credit' => $spent, 'refunded' => $refunded, 'available_credit' => $fund['available'], 'max_refund' => $max, 'method' => $fund['payment']->payment_method, 'pending_refund' => $pending];
        }unset($source);
        $history = DB::table('refunds as r')->join('refund_confirmations as c', 'c.refund_id', '=', 'r.id')->where('r.sales_return_id', $receipt->id)->orderByDesc('r.id')->get(['r.id', 'r.amount', 'r.method', 'r.external_reference', 'r.refunded_at', 'c.actor_name', 'c.reason']);
        foreach ($history as $entry) {
            $entry->amount = bcadd((string) $entry->amount, '0', 4);
            $entry->reversal = DB::table('refund_reversals')->where('refund_id', $entry->id)->first(['id', 'reason', 'actor_name', 'created_at']);
        }

        return ['state' => 'adjusted', 'version' => $order->lock_version, 'currency' => $order->currency, 'invoice_number' => $invoice->invoice_number, 'adjustment' => ['id' => $adjustment->id, 'amount' => (string) $adjustment->amount, 'shipping_credit' => (string) $adjustment->shipping_credit, 'credit_released' => (string) $adjustment->credit_released, 'reason' => $adjustment->reason, 'actor_name' => $adjustment->actor_name, 'created_at' => $adjustment->created_at, 'snapshot' => json_decode($adjustment->snapshot, true, flags: JSON_THROW_ON_ERROR)], 'balance' => OrderSettlement::position($order, $invoice->id), 'refund_sources' => array_values($sources), 'refunds' => $history];
    }

    public function info(StoreContext $context, int $orderId, int $returnId, array $input): array
    {
        $data = Validator::make($input, ['shipping_credit' => ['nullable', new DecimalAmount]])->validate();
        $shipping = Money::round(DecimalAmount::normalize($data['shipping_credit'] ?? '0'));

        return DB::transaction(function () use ($context, $orderId, $returnId, $shipping) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();
            [$order,$invoice,$receipt] = $this->scope($context, $orderId, $returnId, false);
            $history = DB::table('return_adjustments')->where('sales_return_id', $returnId)->orderByDesc('id')->get(['id', 'amount', 'shipping_credit', 'reason', 'actor_name', 'created_at']);
            foreach ($history as $row) {
                $row->amount = bcadd((string) $row->amount, '0', 4);
                $row->reversal = DB::table('return_adjustment_reversals')->where('adjustment_id', $row->id)->first(['id', 'reason', 'actor_name', 'created_at']);
            }
            if (AdjustmentState::current($returnId)) {
                return [...$this->state($order, $invoice, $receipt), 'history' => $history];
            }abort_unless($receipt->status === 'received', 409, 'La recepción no está pendiente de ajuste.');

            return ['state' => 'received', 'proposal' => $this->proposal($order, $invoice, $receipt, $shipping, false), 'history' => $history];
        });
    }

    public function adjust(StoreContext $context, int $orderId, int $returnId, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'shipping_credit' => ['required', new DecimalAmount], 'reason' => ['required', 'string', 'min:5', 'max:255']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $data['shipping_credit'] = Money::round(DecimalAmount::normalize($data['shipping_credit']));
        $result = $this->once->run('returns.adjust', $key, [$actor->id, $context->store()->id, $orderId, $returnId], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'returns.adjust');
        }, function () use ($context, $orderId, $returnId, $actor, $data) {
            $this->locks($context, $orderId);
            [$order,$invoice,$receipt] = $this->scope($context, $orderId, $returnId, true);
            DB::table('return_adjustment_heads')->insertOrIgnore(['sales_return_id' => $returnId, 'active_adjustment_id' => null]);
            $head = DB::table('return_adjustment_heads')->where('sales_return_id', $returnId)->lockForUpdate()->firstOrFail();
            abort_unless($order->lock_version === $data['version'] && $receipt->status === 'received' && ! $head->active_adjustment_id && ! AdjustmentState::current($returnId, true), 409, 'La devolución cambió o ya tiene un ajuste.');
            abort_unless(in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true), 409, 'La factura tiene otros ajustes que requieren conciliación.');
            abort_if(DB::table('commission_entries')->where('invoice_id', $invoice->id)->lockForUpdate()->first(), 409, 'La factura tiene comisiones que requieren conciliación.');
            abort_if(DB::table('refunds')->where('invoice_id', $invoice->id)->where('status', 'pending')->lockForUpdate()->first(), 409, 'Hay un reembolso pendiente de resultado.');
            $proposal = $this->proposal($order, $invoice, $receipt, $data['shipping_credit'], true);
            abort_unless(hash_equals($proposal['review_hash'], $data['review_hash']), 409, 'Los importes o abonos cambiaron. Revisa la propuesta actualizada.');
            $id = DB::table('return_adjustments')->insertGetId(['sales_return_id' => $returnId, 'order_id' => $orderId, 'invoice_id' => $invoice->id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'amount' => $proposal['amount'], 'merchandise_credit' => $proposal['merchandise_credit'], 'shipping_credit' => $proposal['shipping_credit'], 'net_after' => $proposal['net_after'], 'credit_released' => $proposal['credit_released'], 'snapshot' => json_encode($proposal, JSON_THROW_ON_ERROR), 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'created_at' => now()]);
            foreach ($proposal['lines'] as $line) {
                $detail = ['sales_return_item_id' => $line['sales_return_item_id'], 'adjustment_id' => $id, 'invoice_item_id' => $line['invoice_item_id'], 'quantity' => $line['quantity'], 'credit_amount' => $line['credit_amount']];
                DB::table('return_adjustment_revision_items')->insert($detail);
                // Preserve the first historical representation for legacy readers.
                DB::table('return_adjustment_items')->insertOrIgnore($detail);
                DB::table('sales_return_items')->where('id', $line['sales_return_item_id'])->update(['refund_amount' => $line['credit_amount']]);
            }
            foreach ($proposal['releases'] as $release) {
                DB::table('payment_allocation_releases')->insert([...$release, 'adjustment_id' => $id, 'order_id' => $orderId, 'invoice_id' => $invoice->id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'created_at' => now()]);
            }
            DB::table('sales_returns')->where('id', $returnId)->update(['status' => 'adjusted', 'updated_at' => now()]);
            DB::table('return_adjustment_heads')->where('sales_return_id', $returnId)->update(['active_adjustment_id' => $id]);
            $order->lock_version++;
            $order->save();
            $this->sales->refreshInvoiceBalance($invoice->id);
            $this->audit->record('sales.return.adjusted', $order, after: ['adjustment_id' => $id, 'sales_return_id' => $returnId, 'amount' => $proposal['amount'], 'credit_released' => $proposal['credit_released'], 'reason' => $data['reason']], actor: $actor);
            app(FinancialDocument::class)->capture('adjustment', $id);

            return ['order_id' => $orderId, 'sales_return_id' => $returnId, 'adjustment_id' => $id];
        });

        return ['order' => $this->sales->present(SalesOrder::findOrFail($orderId)), 'sales_return_id' => $returnId, 'adjustment_id' => $result['adjustment_id'], 'replay' => $result['replay']];
    }

    public function refund(StoreContext $context, int $orderId, int $returnId, User $actor, array $input): array
    {
        foreach (['reason', 'external_reference'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'payment_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', new DecimalAmount], 'method' => ['required', 'in:cash,bank_transfer,card'], 'external_reference' => ['nullable', 'string', 'max:160'], 'reason' => ['required', 'string', 'min:5', 'max:255']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $data['payment_id'] = (int) $data['payment_id'];
        $data['amount'] = Money::round(DecimalAmount::normalize($data['amount']));
        $data['external_reference'] = ($data['external_reference'] ?? '') === '' ? null : $data['external_reference'];
        if ($data['method'] !== 'cash' && $data['external_reference'] === null) {
            throw ValidationException::withMessages(['external_reference' => 'Registra la referencia del reembolso ya realizado.']);
        }abort_unless(bccomp($data['amount'], '0', 4) > 0, 422, 'El reembolso debe ser positivo.');
        $result = $this->once->run('returns.refund', $key, [$actor->id, $context->store()->id, $orderId, $returnId, $data['payment_id']], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'refunds.record');
        }, function () use ($context, $orderId, $returnId, $actor, $data, $key) {
            $this->locks($context, $orderId);
            [$order,$invoice,$receipt] = $this->scope($context, $orderId, $returnId, true);
            abort_unless($order->lock_version === $data['version'] && $receipt->status === 'adjusted', 409, 'La devolución cambió o no tiene ajuste confirmado.');
            $adjustment = AdjustmentState::current($returnId, true);
            abort_unless($adjustment, 409, __('adjustment_reversal.no_current'));
            abort_unless(in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true), 409, 'La factura requiere conciliación de otros ajustes.');
            abort_if(DB::table('commission_entries')->where('invoice_id', $invoice->id)->lockForUpdate()->first(), 409, 'Las comisiones requieren conciliación antes de reembolsar.');
            DB::table('payment_allocation_releases')->where('adjustment_id', $adjustment->id)->where('payment_id', $data['payment_id'])->lockForUpdate()->firstOrFail();
            $fund = OrderSettlement::payment($data['payment_id'], true);
            abort_unless($fund['payment']->status === 'confirmed' && $fund['payment']->store_id === $order->store_id && $fund['payment']->customer_id === $order->customer_id && $fund['payment']->currency === $order->currency, 409, 'El pago original requiere conciliación.');
            abort_unless(bccomp($fund['available'], '0', 4) >= 0 && bccomp($fund['released'], $fund['allocated'], 4) <= 0 && bccomp(bcadd(bcadd($fund['allocated'], $fund['legacy_credit'], 4), $fund['historical_refunded'], 4), (string) $fund['payment']->amount, 4) === 0, 409, 'El crédito del pago no está conciliado.');
            abort_if(DB::table('refunds')->where('original_payment_id', $data['payment_id'])->where('status', 'pending')->lockForUpdate()->first(), 409, 'Hay un reembolso sin resultado confirmado.');
            $released = OrderSettlement::total(DB::table('payment_allocation_releases')->where('adjustment_id', $adjustment->id)->where('payment_id', $data['payment_id'])->orderBy('id'), 'amount', true);
            $refunded = OrderSettlement::total(RefundState::effective(DB::table('refunds')->where('sales_return_id', $returnId)->where('original_payment_id', $data['payment_id'])->where('status', 'confirmed')->orderBy('id'), 'refunds', true), 'amount', true);
            $spent = '0.0000';
            foreach (CreditPools::rows($data['payment_id'], true) as $pool) {
                if ($pool['adjustment_id'] === (int) $adjustment->id) {
                    $spent = bcadd($spent, $pool['used'], 4);
                }
            }
            $remaining = bcsub(bcsub($released, $spent, 4), $refunded, 4);
            abort_unless(bccomp($data['amount'], $remaining, 4) <= 0 && bccomp($data['amount'], $fund['available'], 4) <= 0, 422, 'El reembolso excede el crédito disponible de esta devolución y pago.');
            $reference = $data['external_reference'] !== null ? hash('sha256', $order->store_id.'|'.$data['method'].'|'.$data['external_reference']) : null;
            abort_if($reference && DB::table('refund_confirmations')->where('reference_hash', $reference)->lockForUpdate()->first(), 409, 'Esta referencia de reembolso ya está registrada.');
            $id = DB::table('refunds')->insertGetId(['customer_id' => $order->customer_id, 'sales_return_id' => $returnId, 'original_payment_id' => $data['payment_id'], 'refund_kind' => 'return', 'status' => 'confirmed', 'amount' => $data['amount'], 'currency' => $order->currency, 'method' => $data['method'], 'event_key' => 'return-refund:'.strtolower($key), 'external_reference' => $data['external_reference'], 'refunded_at' => now(), 'created_at' => now(), 'updated_at' => now(), 'store_id' => $order->store_id, 'invoice_id' => $invoice->id]);
            DB::table('refund_confirmations')->insert(['refund_id' => $id, 'adjustment_id' => $adjustment->id, 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'reason' => $data['reason'], 'reference_hash' => $reference, 'created_at' => now()]);
            DB::table('customer_credit_movements')->insert(['payment_id' => $data['payment_id'], 'customer_id' => $order->customer_id, 'currency' => $order->currency, 'refund_id' => $id, 'event_type' => 'unused_refund', 'amount_delta' => bcsub('0', $data['amount'], 4), 'event_key' => 'return-refund-credit:'.$id, 'occurred_at' => now(), 'created_at' => now()]);
            $order->lock_version++;
            $order->save();
            $this->sales->refreshInvoiceBalance($invoice->id);
            $this->audit->record('sales.return.refunded', $order, after: ['refund_id' => $id, 'adjustment_id' => $adjustment->id, 'amount' => $data['amount'], 'method' => $data['method'], 'reason' => $data['reason']], actor: $actor);

            return ['order_id' => $orderId, 'sales_return_id' => $returnId, 'refund_id' => $id];
        });

        return ['order' => $this->sales->present(SalesOrder::findOrFail($orderId)), 'sales_return_id' => $returnId, 'refund_id' => $result['refund_id'], 'replay' => $result['replay']];
    }
}
