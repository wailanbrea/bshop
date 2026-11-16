<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OrderViewLinks
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    public function order(StoreContext $context, int $id): SalesOrder
    {
        return SalesOrder::where('store_id', $context->store()->id)->findOrFail($id);
    }

    private function token(User $actor, int $store, int $order, string $key): string
    {
        // Domain-separated PRF: retries reproduce the secret without persisting it.
        return hash_hmac('sha256', 'order-view:v1|'.$actor->id.'|'.$store.'|'.$order.'|'.strtolower($key), config('app.key'));
    }

    public function present(object $link): array
    {
        return ['id' => (int) $link->id, 'expires_at' => CarbonImmutable::parse($link->expires_at)->toIso8601String(),
            'status' => $link->revoked_at ? 'revoked' : (CarbonImmutable::parse($link->expires_at)->isPast() ? 'expired' : 'active')];
    }

    public function create(StoreContext $context, int $id, User $actor, array $data): array
    {
        $store = $context->store()->id;
        $token = $this->token($actor, $store, $id, $data['request_key']);
        $result = $this->once->run('order-view-links.create', $data['request_key'], [$actor->id, $store, $id], ['hours' => (int) $data['hours']],
            fn () => $this->sales->authorize($context, $actor), function () use ($context, $id, $actor, $data, $token) {
                $order = $this->order($context, $id);
                $order = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($order->status, ['reserved', 'confirmed', 'fulfilled'], true), 409, 'Guarda el apartado antes de compartirlo. Los pedidos cancelados no se comparten.');
                abort_unless(Store::whereKey($order->store_id)->value('is_active'), 409, 'La sucursal está inactiva.');
                // A locking read observes links committed while this transaction waited on the order.
                abort_if(DB::table('order_view_links')->where('order_id', $id)->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->get(['id'])->count() >= 5, 409, 'Revoca un enlace activo antes de generar otro (máximo 5).');
                $linkId = DB::table('order_view_links')->insertGetId(['order_id' => $id, 'store_id' => $order->store_id, 'created_by_user_id' => $actor->id,
                    'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours((int) $data['hours']), 'created_at' => now(), 'updated_at' => now()]);
                $this->audit->record('order.view_link.created', $order, after: ['link_id' => $linkId, 'hours' => (int) $data['hours']], actor: $actor);

                return ['link_id' => $linkId];
            });
        $link = DB::table('order_view_links')->find($result['link_id']);
        $view = $this->present($link);

        // The idempotency journal and audit contain no bearer token or public URL.
        return ['link' => $view, 'path' => $view['status'] === 'active' && hash_equals($link->token_hash, hash('sha256', $token)) ? '/s/order/'.$token : null, 'replay' => $result['replay']];
    }

    public function revoke(StoreContext $context, int $id, int $linkId, User $actor): array
    {
        return DB::transaction(function () use ($context, $id, $linkId, $actor) {
            $this->sales->authorize($context, $actor);
            $order = $this->order($context, $id);
            SalesOrder::whereKey($id)->lockForUpdate()->firstOrFail();
            $link = DB::table('order_view_links')->where('order_id', $id)->where('store_id', $context->store()->id)->where('id', $linkId)->lockForUpdate()->first();
            abort_unless($link, 404);
            if (! $link->revoked_at) {
                DB::table('order_view_links')->where('id', $linkId)->update(['revoked_at' => now(), 'updated_at' => now()]);
                $this->audit->record('order.view_link.revoked', $order, after: ['link_id' => $linkId], actor: $actor);
            }

            return $this->present(DB::table('order_view_links')->find($linkId));
        }, 3);
    }

    public function publicOrder(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        return DB::transaction(function () use ($token) {
            $link = DB::table('order_view_links')->where('token_hash', hash('sha256', $token))->first();
            if (! $link) {
                return null;
            }
            // Same lock order as revocation; reading never consumes or renews the link.
            $order = SalesOrder::where('id', $link->order_id)->where('store_id', $link->store_id)->sharedLock()->first();
            $link = DB::table('order_view_links')->where('id', $link->id)->sharedLock()->first();
            $store = Store::find($link->store_id);
            if (! $order || ! $store?->is_active || $link->revoked_at || CarbonImmutable::parse($link->expires_at)->lessThanOrEqualTo(now()) || ! in_array($order->status, ['reserved', 'confirmed', 'fulfilled'], true)) {
                return null;
            }
            $paid = $this->sales->paid($order->id);
            $invoice = OrderInvoice::query($order->id)->first();
            $position = OrderSettlement::position($order, $invoice?->id, true);
            $paid = $position['paid_total'];

            return ['number' => $order->basket_number ?: $order->id, 'store_name' => $store->name, 'currency' => $order->currency, 'return_pending' => $invoice ? ReturnReceiptState::pendingInvoice($invoice->id) : false,
                'status' => $order->status, 'shipped' => (bool) $order->shipped_at, 'subtotal' => $order->subtotal,
                'discount' => $order->discount_total, 'tax' => $order->tax_total, 'shipping' => $order->shipping_total,
                'total' => $position['net_total'], 'original_total' => $order->grand_total, 'return_credit_total' => $position['return_credit_total'], 'refunded_total' => $position['refunded_total'], 'credit_available' => $position['credit_available'], 'paid' => $paid, 'pending' => $position['pending_total'],
                'expires_at' => CarbonImmutable::parse($link->expires_at)->toIso8601String(),
                'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => [
                    'name' => $item->product_name_snapshot, 'quantity' => $item->quantity,
                    'price' => $item->unit_price, 'total' => $item->line_total,
                    'variants' => collect($item->variant_snapshot ?? [])->map(fn ($value) => ($value['name'] ?? '').': '.($value['value'] ?? ''))->values()->all(),
                ])->all()];
        }, 3);
    }
}
