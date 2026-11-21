<?php

namespace App\Domain\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PaymentMethodCatalog
{
    /**
     * Built-in catalog of supported payment methods.
     * `default_enabled` marks the 11 core methods active out-of-the-box when a store has not customized its list yet.
     *
     * @return array<string, array{code:string,label:string,category:string,bg_color:string,fg_color:string,default_enabled:bool,sort_order:int}>
     */
    public static function builtIn(): array
    {
        $items = [
            // Core / Traditional (default enabled)
            ['code' => 'cash', 'label' => 'Efectivo', 'category' => 'traditional', 'bg_color' => '#dcfce7', 'fg_color' => '#15803d', 'default_enabled' => true],
            ['code' => 'bank_transfer', 'label' => 'Transferencia', 'category' => 'traditional', 'bg_color' => '#e0e7ff', 'fg_color' => '#3730a3', 'default_enabled' => true],
            ['code' => 'card', 'label' => 'Tarjeta', 'category' => 'cards', 'bg_color' => '#f3e8ff', 'fg_color' => '#6b21a8', 'default_enabled' => true],
            ['code' => 'zelle', 'label' => 'Zelle', 'category' => 'wallets', 'bg_color' => '#6d1ed4', 'fg_color' => '#ffffff', 'default_enabled' => true],
            ['code' => 'cash_app', 'label' => 'Cash App', 'category' => 'wallets', 'bg_color' => '#00d632', 'fg_color' => '#ffffff', 'default_enabled' => true],
            ['code' => 'paypal', 'label' => 'PayPal', 'category' => 'wallets', 'bg_color' => '#003087', 'fg_color' => '#ffffff', 'default_enabled' => true],
            ['code' => 'venmo', 'label' => 'Venmo', 'category' => 'wallets', 'bg_color' => '#008cff', 'fg_color' => '#ffffff', 'default_enabled' => true],
            ['code' => 'credit', 'label' => 'Crédito', 'category' => 'traditional', 'bg_color' => '#fef3c7', 'fg_color' => '#b45309', 'default_enabled' => true],
            ['code' => 'afterpay', 'label' => 'Afterpay', 'category' => 'bnpl', 'bg_color' => '#b2fce4', 'fg_color' => '#0f172a', 'default_enabled' => true],
            ['code' => 'klarna', 'label' => 'Klarna', 'category' => 'bnpl', 'bg_color' => '#ffb3c7', 'fg_color' => '#18181b', 'default_enabled' => true],
            ['code' => 'apple_pay', 'label' => 'Apple Pay', 'category' => 'wallets', 'bg_color' => '#111827', 'fg_color' => '#ffffff', 'default_enabled' => true],

            // Wallets & P2P (ready to toggle on)
            ['code' => 'google_pay', 'label' => 'Google Pay', 'category' => 'wallets', 'bg_color' => '#1a73e8', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'samsung_pay', 'label' => 'Samsung Pay', 'category' => 'wallets', 'bg_color' => '#1428a0', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'shop_pay', 'label' => 'Shop Pay', 'category' => 'wallets', 'bg_color' => '#5a31f4', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'amazon_pay', 'label' => 'Amazon Pay', 'category' => 'wallets', 'bg_color' => '#232f3e', 'fg_color' => '#ff9900', 'default_enabled' => false],
            ['code' => 'mercado_pago', 'label' => 'Mercado Pago', 'category' => 'wallets', 'bg_color' => '#009ee3', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'wise', 'label' => 'Wise', 'category' => 'wallets', 'bg_color' => '#9fe870', 'fg_color' => '#163300', 'default_enabled' => false],
            ['code' => 'revolut', 'label' => 'Revolut', 'category' => 'wallets', 'bg_color' => '#191c1f', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'payoneer', 'label' => 'Payoneer', 'category' => 'wallets', 'bg_color' => '#ff4800', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'western_union', 'label' => 'Western Union', 'category' => 'wallets', 'bg_color' => '#ffdd00', 'fg_color' => '#111827', 'default_enabled' => false],
            ['code' => 'nubank', 'label' => 'Nubank', 'category' => 'wallets', 'bg_color' => '#820ad1', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'pix', 'label' => 'Pix', 'category' => 'wallets', 'bg_color' => '#32bcad', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'alipay', 'label' => 'Alipay', 'category' => 'wallets', 'bg_color' => '#1677ff', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'wechat_pay', 'label' => 'WeChat Pay', 'category' => 'wallets', 'bg_color' => '#07c160', 'fg_color' => '#ffffff', 'default_enabled' => false],

            // Cards & POS Networks
            ['code' => 'visa', 'label' => 'Visa', 'category' => 'cards', 'bg_color' => '#1a1f71', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'mastercard', 'label' => 'Mastercard', 'category' => 'cards', 'bg_color' => '#eb001b', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'amex', 'label' => 'American Express', 'category' => 'cards', 'bg_color' => '#006fcf', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'discover', 'label' => 'Discover', 'category' => 'cards', 'bg_color' => '#ff6000', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'jcb', 'label' => 'JCB', 'category' => 'cards', 'bg_color' => '#0f4c81', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'diners_club', 'label' => 'Diners Club', 'category' => 'cards', 'bg_color' => '#0079be', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'stripe', 'label' => 'Stripe', 'category' => 'cards', 'bg_color' => '#635bff', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'square', 'label' => 'Square', 'category' => 'cards', 'bg_color' => '#111827', 'fg_color' => '#ffffff', 'default_enabled' => false],

            // BNPL / Installments
            ['code' => 'affirm', 'label' => 'Affirm', 'category' => 'bnpl', 'bg_color' => '#0fa0ea', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'sezzle', 'label' => 'Sezzle', 'category' => 'bnpl', 'bg_color' => '#392659', 'fg_color' => '#ffffff', 'default_enabled' => false],

            // Additional Traditional
            ['code' => 'check', 'label' => 'Cheque', 'category' => 'traditional', 'bg_color' => '#e2e8f0', 'fg_color' => '#334155', 'default_enabled' => false],
            ['code' => 'deposit', 'label' => 'Depósito bancario', 'category' => 'traditional', 'bg_color' => '#dbeafe', 'fg_color' => '#1d4ed8', 'default_enabled' => false],
            ['code' => 'cod', 'label' => 'Contra entrega', 'category' => 'traditional', 'bg_color' => '#ffedd5', 'fg_color' => '#c2410c', 'default_enabled' => false],
            ['code' => 'gift_card', 'label' => 'Tarjeta de regalo', 'category' => 'traditional', 'bg_color' => '#fce7f3', 'fg_color' => '#be185d', 'default_enabled' => false],

            // Crypto
            ['code' => 'bitcoin', 'label' => 'Bitcoin', 'category' => 'crypto', 'bg_color' => '#f7931a', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'usdt', 'label' => 'USDT (Tether)', 'category' => 'crypto', 'bg_color' => '#26a17b', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'ethereum', 'label' => 'Ethereum', 'category' => 'crypto', 'bg_color' => '#3c3c3d', 'fg_color' => '#ffffff', 'default_enabled' => false],
            ['code' => 'binance_pay', 'label' => 'Binance Pay', 'category' => 'crypto', 'bg_color' => '#f3ba2f', 'fg_color' => '#18181b', 'default_enabled' => false],
        ];

        $map = [];
        foreach ($items as $index => $item) {
            $item['sort_order'] = ($index + 1) * 10;
            $map[$item['code']] = $item;
        }

        return $map;
    }

    /**
     * Returns all payment methods (built-in + custom) for a store with their enabled state.
     *
     * @return list<array<string, mixed>>
     */
    public static function forStore(int $storeId): array
    {
        $builtIn = self::builtIn();
        $rows = Schema::hasTable('store_payment_methods')
            ? DB::table('store_payment_methods')
                ->where('store_id', $storeId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->keyBy('code')
            : collect();

        $hasBuiltInOverrides = $rows->contains(fn ($r) => ! $r->is_custom);
        $list = [];

        foreach ($builtIn as $code => $meta) {
            $row = $rows->get($code);
            $enabled = $row
                ? (bool) $row->is_enabled
                : ($hasBuiltInOverrides ? false : $meta['default_enabled']);

            $list[] = [
                'id' => $row?->id,
                'code' => $code,
                'label' => $row?->label ?: $meta['label'],
                'category' => $meta['category'],
                'bg_color' => $row?->bg_color ?: $meta['bg_color'],
                'fg_color' => $row?->fg_color ?: $meta['fg_color'],
                'icon_svg' => $row?->icon_svg,
                'is_custom' => false,
                'is_enabled' => $enabled,
                'sort_order' => $row ? (int) $row->sort_order : $meta['sort_order'],
            ];
        }

        foreach ($rows as $row) {
            if (! $row->is_custom || isset($builtIn[$row->code])) {
                continue;
            }
            $list[] = [
                'id' => (int) $row->id,
                'code' => $row->code,
                'label' => $row->label,
                'category' => 'custom',
                'bg_color' => $row->bg_color ?: '#ede9fe',
                'fg_color' => $row->fg_color ?: '#4c1d95',
                'icon_svg' => $row->icon_svg,
                'is_custom' => true,
                'is_enabled' => (bool) $row->is_enabled,
                'sort_order' => (int) $row->sort_order,
            ];
        }

        usort($list, fn (array $a, array $b) => $a['sort_order'] <=> $b['sort_order'] ?: strcmp($a['label'], $b['label']));

        return $list;
    }

    /**
     * All codes accepted when recording a payment/refund/disbursement for a given store.
     * Includes all built-in codes + any custom codes defined for that store (or globally if storeId is null).
     *
     * @return list<string>
     */
    public static function allowedCodes(?int $storeId = null): array
    {
        $codes = array_keys(self::builtIn());

        if (Schema::hasTable('store_payment_methods')) {
            $query = DB::table('store_payment_methods')->where('is_custom', true);
            if ($storeId !== null) {
                $query->where('store_id', $storeId);
            }
            $customCodes = $query->pluck('code')->all();
            $codes = array_values(array_unique([...$codes, ...$customCodes]));
        }

        return $codes;
    }

    public static function validationRule(?int $storeId = null): \Illuminate\Validation\Rules\In
    {
        return Rule::in(self::allowedCodes($storeId));
    }

    /**
     * Map of code => human label for invoices & exports.
     *
     * @return array<string, string>
     */
    public static function labelsForStore(?int $storeId = null): array
    {
        $labels = [];
        foreach (self::builtIn() as $code => $meta) {
            $labels[$code] = $meta['label'];
        }
        $labels['transfer'] = 'Transferencia';
        $labels['mobile'] = 'Pago móvil';
        $labels['other'] = 'Otro';

        if ($storeId !== null && Schema::hasTable('store_payment_methods')) {
            $rows = DB::table('store_payment_methods')
                ->where('store_id', $storeId)
                ->select('code', 'label')
                ->get();
            foreach ($rows as $row) {
                if (trim((string) $row->label) !== '') {
                    $labels[$row->code] = $row->label;
                }
            }
        }

        return $labels;
    }

    /**
     * Strictly sanitizes a user-supplied SVG string so it cannot execute scripts or load remote resources.
     */
    public static function sanitizeSvg(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $svg = trim($raw);
        if ($svg === '') {
            return null;
        }
        if (strlen($svg) > 32768) {
            throw ValidationException::withMessages(['icon_svg' => 'El SVG es demasiado grande (máximo 32 KB).']);
        }

        // Strip XML prolog, DOCTYPE, comments
        $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? $svg;
        $svg = preg_replace('/<!--.*?-->/s', '', $svg) ?? $svg;
        $svg = trim($svg);

        if (! preg_match('/^<svg\b[^>]*>.*<\/svg>$/is', $svg)) {
            throw ValidationException::withMessages(['icon_svg' => 'La imagen debe ser un código SVG válido (<svg ...>...</svg>).']);
        }

        // Reject dangerous tags outright
        $forbiddenTags = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'base', 'audio', 'video'];
        foreach ($forbiddenTags as $tag) {
            if (preg_match('/<\s*'.$tag.'\b/i', $svg)) {
                throw ValidationException::withMessages(['icon_svg' => 'El archivo SVG contiene etiquetas no permitidas por seguridad.']);
            }
        }

        // Reject inline event handlers (onclick=, onload=, etc.) and javascript:/data: URIs
        if (preg_match('/\bon[a-z]+\s*=/i', $svg) || preg_match('/javascript\s*:/i', $svg) || preg_match('/vbscript\s*:/i', $svg)) {
            throw ValidationException::withMessages(['icon_svg' => 'El archivo SVG contiene eventos o enlaces no permitidos.']);
        }
        if (preg_match('/(?:href|src)\s*=\s*["\']\s*(?:https?:|\/\/|data:)/i', $svg)) {
            throw ValidationException::withMessages(['icon_svg' => 'El SVG no puede referenciar recursos externos.']);
        }

        // Parse with libxml without network access or entity expansion
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            throw ValidationException::withMessages(['icon_svg' => 'No se pudo leer el XML del archivo SVG. Verifica que esté bien formado.']);
        }

        $root = $dom->documentElement;
        if (! $root->hasAttribute('xmlns')) {
            $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }
        if (! $root->hasAttribute('viewBox')) {
            $w = preg_replace('/[^0-9.]/', '', (string) $root->getAttribute('width')) ?: '24';
            $h = preg_replace('/[^0-9.]/', '', (string) $root->getAttribute('height')) ?: '24';
            $root->setAttribute('viewBox', "0 0 {$w} {$h}");
        }
        $root->removeAttribute('width');
        $root->removeAttribute('height');

        return trim((string) $dom->saveXML($root));
    }

    public static function slugifyCode(string $label, int $storeId): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($ascii)) ?? '');
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'metodo';
        }
        $base = substr('c_'.$slug, 0, 26);
        $candidate = $base;
        $builtIn = self::builtIn();
        $suffix = 1;

        while (
            isset($builtIn[$candidate])
            || (Schema::hasTable('store_payment_methods')
                && DB::table('store_payment_methods')->where('store_id', $storeId)->where('code', $candidate)->exists())
        ) {
            $candidate = substr($base, 0, 26).'_'.(++$suffix);
        }

        return substr($candidate, 0, 30);
    }
}
