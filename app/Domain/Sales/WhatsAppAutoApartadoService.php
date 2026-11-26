<?php

namespace App\Domain\Sales;

use App\Domain\Business\StoreContext;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class WhatsAppAutoApartadoService
{
    public function __construct(
        private WhatsAppTemplateDispatcher $dispatcher,
        private WhatsAppInboxService $inbox,
        private BasketService $baskets,
    ) {}

    public function isEnabled(int $storeId): bool
    {
        $automations = $this->dispatcher->getAutomations($storeId);

        return (bool) ($automations['rules']['auto_apartado']['enabled'] ?? false);
    }

    /**
     * Parses an inbound WhatsApp message for an auto-apartado command:
     *  - Interactive reply: AUTO_ADD:{variantId}:{qty}
     *  - Short product code: K-42, K42, #42, P-42, COD 42 (with optional variant text + qty like "K-42 M x2")
     *  - Exact SKU or barcode: BRZ-01, VESTIDO-M (must contain at least one letter or hyphen, min 3 chars)
     *
     * @return array{kind: 'variant_id'|'product_id'|'sku', value: int|string, qualifier: string, quantity: int}|null
     */
    public static function parseInboundCommand(array $msg): ?array
    {
        $type = (string) ($msg['type'] ?? '');

        if ($type === 'interactive') {
            $replyId = (string) ($msg['interactive']['button_reply']['id'] ?? $msg['interactive']['list_reply']['id'] ?? '');
            if (preg_match('/^AUTO_ADD:(\d+):(\d+)$/', $replyId, $m)) {
                return [
                    'kind' => 'variant_id',
                    'value' => (int) $m[1],
                    'qualifier' => '',
                    'quantity' => max(1, min(20, (int) $m[2])),
                ];
            }

            return null;
        }

        if ($type !== 'text') {
            return null;
        }

        $raw = trim((string) ($msg['text']['body'] ?? ''));
        if ($raw === '' || mb_strlen($raw) > 120) {
            return null;
        }

        // Normalize whitespace
        $clean = preg_replace('/\s+/u', ' ', $raw) ?? $raw;

        // Strip common polite lead-ins (e.g. "Quiero K-42 M", "Apártame BRZ-01")
        $clean = preg_replace(
            '/^(?:hola[,!.\s]+)?(?:por\s+favor[,.\s]+|porfa[,.\s]+)?(?:quiero|ap[aá]rtame|aparta|agr[eé]game|agrega|dame|ponme|me\s+interesa|rese[rv]a(?:me)?)\s+/iu',
            '',
            $clean
        ) ?? $clean;
        $clean = trim($clean, " \t\n\r\0\x0B.,!?");
        if ($clean === '') {
            return null;
        }

        // Extract optional quantity suffix: "x2", "x 2", "*2", "cant 2", "cantidad 2"
        $qty = 1;
        if (preg_match('/(?:^|\s)(?:x|\*|cant(?:idad)?\.?)\s*(\d{1,2})\s*$/iu', $clean, $qm)) {
            $qty = max(1, min(20, (int) $qm[1]));
            $clean = trim(substr($clean, 0, -strlen($qm[0])));
        }

        // Pattern A: explicit short product code (K-42, K42, #42, P-42, COD 42, CODIGO 42)
        if (preg_match('/^(?:(?:K|P|COD(?:IGO|IGO)?|C[ÓO]DIGO)[-\s#]?|#)(\d{1,7})(?:\s+(.*))?$/iu', $clean, $pm)) {
            return [
                'kind' => 'product_id',
                'value' => (int) $pm[1],
                'qualifier' => trim((string) ($pm[2] ?? '')),
                'quantity' => $qty,
            ];
        }

        // Pattern B: explicit SKU / barcode token (first token >= 3 chars, must contain at least one letter or hyphen)
        $parts = preg_split('/\s+/u', $clean, 2);
        $token = trim((string) ($parts[0] ?? ''), ".,!?#");
        $rest = trim((string) ($parts[1] ?? ''));
        if (
            strlen($token) >= 3
            && strlen($token) <= 64
            && preg_match('/^[A-Za-z0-9._\/-]+$/', $token)
            && preg_match('/[A-Za-z-]/', $token)
        ) {
            return [
                'kind' => 'sku',
                'value' => $token,
                'qualifier' => $rest,
                'quantity' => $qty,
            ];
        }

        return null;
    }

    /**
     * Handles a newly recorded inbound WhatsApp message if auto-apartado is enabled for $storeId.
     *
     * @param  array{inserted: bool, conversation_id: int, wa_phone: string, profile_name: ?string, message_id: ?int}  $recorded
     */
    public function handleInbound(int $storeId, array $msg, array $recorded): void
    {
        if (! ($recorded['inserted'] ?? false)) {
            return;
        }
        if (! $this->isEnabled($storeId)) {
            return;
        }

        $cmd = self::parseInboundCommand($msg);
        if (! $cmd) {
            return;
        }

        $store = Store::whereKey($storeId)->where('is_active', true)->first();
        if (! $store) {
            return;
        }

        $warehouse = Warehouse::where('store_id', $storeId)->where('is_active', true)->orderBy('id')->first();
        if (! $warehouse) {
            return;
        }

        $conversationId = (int) $recorded['conversation_id'];
        $waPhone = (string) $recorded['wa_phone'];
        $profileName = $recorded['profile_name'] ?? null;
        $providerMsgId = (string) ($msg['id'] ?? Str::uuid()->toString());
        $qty = $cmd['quantity'];

        // Resolve candidate product / variant strictly scoped to $storeId
        $resolvedVariant = null;
        $product = null;

        if ($cmd['kind'] === 'variant_id') {
            $resolvedVariant = ProductVariant::with(['product', 'attributeValues.attribute'])
                ->where('id', (int) $cmd['value'])
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId)->where('status', 'active'))
                ->first();
            if (! $resolvedVariant) {
                $this->inbox->sendAutomatedReply($storeId, $conversationId, $waPhone, 'Esa opción ya no está disponible en el catálogo.');

                return;
            }
            $product = $resolvedVariant->product;
        } elseif ($cmd['kind'] === 'product_id') {
            $product = Product::where('store_id', $storeId)
                ->where('status', 'active')
                ->where('id', (int) $cmd['value'])
                ->first();
            if (! $product) {
                $this->inbox->sendAutomatedReply(
                    $storeId,
                    $conversationId,
                    $waPhone,
                    'No encontré el código K-'.$cmd['value'].' en el catálogo activo. Verifica el número e inténtalo de nuevo.'
                );

                return;
            }
        } elseif ($cmd['kind'] === 'sku') {
            $needle = mb_strtolower((string) $cmd['value']);
            $resolvedVariant = ProductVariant::with(['product', 'attributeValues.attribute'])
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->where('store_id', $storeId)->where('status', 'active'))
                ->where(function ($q) use ($needle) {
                    $q->whereRaw('LOWER(sku) = ?', [$needle])
                        ->orWhereRaw('LOWER(barcode) = ?', [$needle]);
                })
                ->first();
            if (! $resolvedVariant) {
                // Silent ignore for arbitrary words (e.g. "Hola", "Gracias", "Brazalete")
                return;
            }
            $product = $resolvedVariant->product;
        }

        if (! $product) {
            return;
        }

        // If matched by product_id, resolve which variant to add (or ask interactively)
        if (! $resolvedVariant) {
            $variants = ProductVariant::with(['attributeValues.attribute'])
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            if ($variants->isEmpty()) {
                $this->inbox->sendAutomatedReply($storeId, $conversationId, $waPhone, "Lo siento, *{$product->name}* no tiene variantes activas en este momento.");

                return;
            }

            $stockMap = $this->availableStockMap($warehouse->id, $variants, (bool) $product->track_stock);

            // Narrow by qualifier if customer typed e.g. "K-42 M" or "K-42 Rojo"
            if ($cmd['qualifier'] !== '') {
                $qNorm = $this->normalizeText($cmd['qualifier']);
                $matched = $variants->filter(function (ProductVariant $v) use ($qNorm) {
                    $label = $this->normalizeText($this->variantLabel($v));
                    $sku = $this->normalizeText($v->sku);

                    return $label === $qNorm
                        || str_contains($label, $qNorm)
                        || $sku === $qNorm
                        || $v->attributeValues->contains(fn ($av) => $this->normalizeText($av->value) === $qNorm);
                })->values();

                if ($matched->count() === 1) {
                    $resolvedVariant = $matched->first();
                } elseif ($matched->count() > 1) {
                    $variants = $matched;
                }
            }

            if (! $resolvedVariant) {
                $inStock = $variants->filter(fn (ProductVariant $v) => $stockMap[$v->id] >= $qty)->values();

                if ($inStock->isEmpty()) {
                    $this->inbox->sendAutomatedReply(
                        $storeId,
                        $conversationId,
                        $waPhone,
                        "Lo siento, *{$product->name}* (K-{$product->id}) se acaba de agotar 😔."
                    );

                    return;
                }

                if ($inStock->count() === 1) {
                    $resolvedVariant = $inStock->first();
                } else {
                    $this->sendVariantPicker($storeId, $conversationId, $waPhone, $product, $inStock, $qty);

                    return;
                }
            }
        }

        // Check stock before touching the basket
        $avail = $this->availableStockMap($warehouse->id, collect([$resolvedVariant]), (bool) $product->track_stock)[$resolvedVariant->id] ?? 0;
        if ($product->track_stock && $avail < $qty) {
            $label = $this->variantLabel($resolvedVariant);
            $variantText = $label !== '' ? " ({$label})" : '';
            $otherInStock = ProductVariant::with(['attributeValues.attribute'])
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->where('id', '!=', $resolvedVariant->id)
                ->get();
            $otherMap = $this->availableStockMap($warehouse->id, $otherInStock, true);
            $alts = $otherInStock->filter(fn ($v) => ($otherMap[$v->id] ?? 0) >= 1)->values();

            if ($alts->isNotEmpty()) {
                $this->sendVariantPicker(
                    $storeId,
                    $conversationId,
                    $waPhone,
                    $product,
                    $alts,
                    1,
                    "Lo siento, *{$product->name}*{$variantText} ya se agotó. Estas opciones aún están disponibles:"
                );
            } else {
                $this->inbox->sendAutomatedReply(
                    $storeId,
                    $conversationId,
                    $waPhone,
                    "Lo siento, *{$product->name}*{$variantText} se acaba de agotar 😔."
                );
            }

            return;
        }

        // Reserve inside the customer's open Apartado (creating customer & apartado if needed)
        $actor = $this->resolveStoreActor($storeId);
        if (! $actor) {
            return;
        }

        $customer = $this->findOrCreateCustomer($storeId, $conversationId, $waPhone, $profileName);
        $context = new StoreContext;
        $context->useStore($store);

        try {
            $order = $this->findOrCreateOpenBasket($context, $storeId, $warehouse->id, $customer->id, $actor, $providerMsgId);
            $updated = $this->addVariantToBasket($context, $order, $resolvedVariant->id, $qty, $actor, $providerMsgId);
        } catch (HttpExceptionInterface $e) {
            $msgText = $e->getMessage() ?: 'No se pudo reservar el artículo en este momento.';
            $this->inbox->sendAutomatedReply($storeId, $conversationId, $waPhone, "No pudimos agregar *{$product->name}*: {$msgText}");

            return;
        } catch (\Throwable) {
            $this->inbox->sendAutomatedReply($storeId, $conversationId, $waPhone, "Ocurrió un inconveniente al apartar *{$product->name}*. Nuestro equipo revisará tu mensaje enseguida.");

            return;
        }

        $currency = ($updated['currency'] ?? 'USD') === 'USD' ? 'US$' : (string) ($updated['currency'] ?? 'USD');
        $unitPrice = number_format((float) $resolvedVariant->sale_price, 2, '.', ',');
        $grandTotal = number_format((float) ($updated['grand_total'] ?? 0), 2, '.', ',');
        $basketNo = $updated['basket_number'] ?: ('#'.$updated['id']);
        $vLabel = $this->variantLabel($resolvedVariant);
        $itemTitle = $product->name.($vLabel !== '' ? " ({$vLabel})" : '');

        $confirm = "✅ ¡Listo! Agregado a tu *Apartado #{$basketNo}*:\n"
            ."• {$itemTitle} × {$qty} — {$currency} {$unitPrice}\n\n"
            ."🧾 Total de tu apartado: *{$currency} {$grandTotal}*\n"
            .'Si deseas agregar otra prenda, solo envía su código.';

        $this->inbox->sendAutomatedReply($storeId, $conversationId, $waPhone, $confirm);
    }

    /**
     * @param  Collection<int, ProductVariant>  $variants
     * @return array<int, float>
     */
    private function availableStockMap(int $warehouseId, Collection $variants, bool $trackStock): array
    {
        $map = [];
        if (! $trackStock) {
            foreach ($variants as $v) {
                $map[$v->id] = 999999.0;
            }

            return $map;
        }

        $rows = DB::table('stock_balances')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('variant_id', $variants->pluck('id')->all())
            ->get(['variant_id', 'on_hand', 'reserved'])
            ->keyBy('variant_id');

        foreach ($variants as $v) {
            $b = $rows->get($v->id);
            $avail = $b ? (float) bcsub((string) $b->on_hand, (string) $b->reserved, 4) : 0.0;
            $map[$v->id] = max(0.0, $avail);
        }

        return $map;
    }

    private function sendVariantPicker(
        int $storeId,
        int $conversationId,
        string $waPhone,
        Product $product,
        Collection $variants,
        int $qty,
        ?string $customIntro = null,
    ): void {
        $currency = 'US$';
        $intro = $customIntro ?: "✨ *{$product->name}* (Código K-{$product->id})\nElige la opción que deseas apartar:";
        $limited = $variants->take(10)->values();

        if ($limited->count() <= 3) {
            $buttons = [];
            foreach ($limited as $v) {
                $label = $this->variantLabel($v) ?: $v->sku;
                $buttons[] = [
                    'type' => 'reply',
                    'reply' => [
                        'id' => "AUTO_ADD:{$v->id}:{$qty}",
                        'title' => Str::limit($label, 20, ''),
                    ],
                ];
            }
            $interactive = [
                'type' => 'button',
                'body' => ['text' => $intro],
                'action' => ['buttons' => $buttons],
            ];
        } else {
            $rows = [];
            foreach ($limited as $v) {
                $label = $this->variantLabel($v) ?: $v->sku;
                $price = number_format((float) $v->sale_price, 2, '.', ',');
                $rows[] = [
                    'id' => "AUTO_ADD:{$v->id}:{$qty}",
                    'title' => Str::limit($label, 24, ''),
                    'description' => "{$currency} {$price}",
                ];
            }
            $interactive = [
                'type' => 'list',
                'body' => ['text' => $intro],
                'action' => [
                    'button' => 'Elegir opción',
                    'sections' => [[
                        'title' => 'Disponibles',
                        'rows' => $rows,
                    ]],
                ],
            ];
        }

        $fallbackSummary = $intro."\n".$limited->map(fn ($v) => '• '.($this->variantLabel($v) ?: $v->sku))->implode("\n");
        $this->inbox->sendAutomatedReply($storeId, $conversationId, $waPhone, $fallbackSummary, $interactive);
    }

    private function findOrCreateCustomer(int $storeId, int $conversationId, string $waPhone, ?string $profileName): Customer
    {
        $convCustId = DB::table('whatsapp_conversations')->where('id', $conversationId)->value('customer_id');
        if ($convCustId) {
            $existing = Customer::where('store_id', $storeId)->where('status', 'active')->find($convCustId);
            if ($existing) {
                return $existing;
            }
        }

        $digits = ltrim($waPhone, '+');
        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;
        $candidates = Customer::where('store_id', $storeId)
            ->where('status', 'active')
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.substr($last10, -4).'%')
            ->get();
        foreach ($candidates as $cust) {
            $cDigits = preg_replace('/\D+/', '', (string) $cust->phone);
            if ($cDigits === $digits || (strlen($last10) === 10 && strlen($cDigits) >= 10 && substr($cDigits, -10) === $last10)) {
                DB::table('whatsapp_conversations')->where('id', $conversationId)->update(['customer_id' => $cust->id, 'updated_at' => now()]);

                return $cust;
            }
        }

        $name = trim((string) ($profileName ?: ''));
        if ($name === '') {
            $name = 'Cliente '.$waPhone;
        }

        $customer = Customer::create([
            'store_id' => $storeId,
            'full_name' => Str::limit($name, 120, ''),
            'phone' => $waPhone,
            'status' => 'active',
        ]);

        DB::table('whatsapp_conversations')->where('id', $conversationId)->update([
            'customer_id' => $customer->id,
            'updated_at' => now(),
        ]);

        return $customer;
    }

    private function resolveStoreActor(int $storeId): ?User
    {
        $userIds = DB::table('store_user')->where('store_id', $storeId)->orderBy('user_id')->pluck('user_id');
        foreach ($userIds as $uid) {
            $u = User::find($uid);
            if ($u && $u->is_active && $u->permits('sales.create')) {
                return $u;
            }
        }

        return User::where('is_active', true)->orderBy('id')->first();
    }

    private function findOrCreateOpenBasket(
        StoreContext $context,
        int $storeId,
        int $warehouseId,
        int $customerId,
        User $actor,
        string $providerMsgId,
    ): SalesOrder {
        $existing = SalesOrder::where('store_id', $storeId)
            ->where('customer_id', $customerId)
            ->where('order_kind', 'reservation')
            ->whereNull('closed_at')
            ->whereIn('status', ['draft', 'reserved'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoice_orders')->whereColumn('invoice_orders.order_id', 'orders.id'))
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $created = $this->baskets->create($context, [
            'request_key' => $this->deterministicUuid('wa-basket-create:'.$storeId.':'.$providerMsgId),
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
        ], $actor);

        return SalesOrder::where('store_id', $storeId)->findOrFail($created['order']['id']);
    }

    private function addVariantToBasket(
        StoreContext $context,
        SalesOrder $order,
        int $variantId,
        int $qty,
        User $actor,
        string $providerMsgId,
    ): array {
        $order->refresh();
        $existingItems = $order->items()->orderBy('id')->get();
        $lines = [];
        $found = false;

        foreach ($existingItems as $item) {
            $q = (string) $item->quantity;
            if ((int) $item->variant_id === $variantId) {
                $q = bcadd($q, (string) $qty, 4);
                $found = true;
            }
            $lines[] = [
                'id' => (int) $item->id,
                'variant_id' => (int) $item->variant_id,
                'quantity' => $q,
            ];
        }

        if (! $found) {
            $lines[] = [
                'variant_id' => $variantId,
                'quantity' => (string) $qty,
            ];
        }

        $baseInput = [
            'version' => (int) $order->lock_version,
            'lines' => $lines,
            'shipping' => (string) $order->shipping_total,
            'discount' => (string) $order->discount_total,
        ];

        $preview = $this->baskets->preview($context, $order->id, $baseInput, $actor);

        $saved = $this->baskets->save($context, $order->id, [
            ...$baseInput,
            'request_key' => $this->deterministicUuid('wa-basket-save:'.$order->id.':'.$providerMsgId),
            'quote_hash' => $preview['quote_hash'],
        ], $actor);

        return $saved['order'];
    }

    private function variantLabel(ProductVariant $v): string
    {
        if (! $v->relationLoaded('attributeValues') || $v->attributeValues->isEmpty()) {
            return '';
        }

        return $v->attributeValues
            ->map(fn ($av) => trim((string) $av->value))
            ->filter()
            ->implode(' / ');
    }

    private function normalizeText(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? $s));
    }

    private function deterministicUuid(string $seed): string
    {
        $hex = md5($seed);

        return sprintf(
            '%s-%s-4%s-a%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            substr($hex, 17, 3),
            substr($hex, 20, 12)
        );
    }
}
