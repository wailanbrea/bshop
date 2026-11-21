<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 34px 38px; }
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 10.5px;
        line-height: 1.45;
        color: #18192b;
        margin: 0;
        padding: 0;
        background: #ffffff;
    }
    .nowrap { white-space: nowrap; }
    .right { text-align: right; }
    .center { text-align: center; }
    .muted { color: #646982; }
    .small { font-size: 9px; }

    /* Header */
    .header-table {
        width: 100%;
        border-collapse: collapse;
        border-bottom: 2px solid #4f46e5;
        padding-bottom: 14px;
        margin-bottom: 18px;
    }
    .header-table td { vertical-align: top; padding: 0 0 14px 0; }
    .brand-table { border-collapse: collapse; }
    .brand-table td { padding: 0; vertical-align: middle; }
    .brand-mark {
        width: 38px;
        height: 38px;
        background: #4f46e5;
        color: #ffffff;
        font-size: 18px;
        font-weight: bold;
        text-align: center;
        line-height: 38px;
        border-radius: 8px;
    }
    .brand-info { padding-left: 11px !important; }
    .brand-name {
        font-size: 19px;
        font-weight: bold;
        color: #18192b;
        margin: 0;
        line-height: 1.15;
    }
    .brand-sub {
        font-size: 9.5px;
        color: #646982;
        margin-top: 2px;
    }
    .doc-eyebrow {
        font-size: 8.5px;
        font-weight: bold;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        color: #4f46e5;
        margin: 0 0 2px 0;
    }
    .doc-number {
        font-size: 18px;
        font-weight: bold;
        color: #18192b;
        margin: 0 0 6px 0;
        line-height: 1.15;
    }
    .status-pill {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 999px;
        font-size: 8.5px;
        font-weight: bold;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
    .status-paid { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .status-partial { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .status-pending { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }

    /* Info cards (Client + Invoice details) */
    .cards-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 18px;
    }
    .cards-table > tbody > tr > td {
        vertical-align: top;
        width: 50%;
    }
    .card-left-wrap { padding-right: 8px; }
    .card-right-wrap { padding-left: 8px; }
    .info-card {
        background: #f8f8fc;
        border: 1px solid #e5e7f0;
        border-radius: 8px;
        padding: 12px 14px;
    }
    .card-title {
        font-size: 8.5px;
        font-weight: bold;
        letter-spacing: 0.9px;
        text-transform: uppercase;
        color: #4f46e5;
        margin: 0 0 8px 0;
        padding-bottom: 5px;
        border-bottom: 1px solid #e5e7f0;
    }
    .client-name {
        font-size: 13px;
        font-weight: bold;
        color: #18192b;
        margin: 0 0 6px 0;
    }
    .kv-table { width: 100%; border-collapse: collapse; }
    .kv-table td { padding: 2.5px 0; vertical-align: top; font-size: 9.8px; }
    .kv-label { color: #646982; width: 78px; white-space: nowrap; padding-right: 6px; }
    .kv-value { color: #18192b; }

    /* Items table */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 18px;
    }
    .items-table thead th {
        background: #eef2ff;
        color: #312e81;
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 9px 10px;
        text-align: left;
        border-top: 1px solid #c7d2fe;
        border-bottom: 1px solid #c7d2fe;
    }
    .items-table thead th.right { text-align: right; }
    .items-table tbody td {
        padding: 10px 10px;
        border-bottom: 1px solid #eceef6;
        vertical-align: top;
    }
    .items-table tbody tr:nth-child(even) td { background: #fbfbfe; }
    .item-title { font-weight: bold; color: #18192b; font-size: 10.5px; }
    .item-sku { font-size: 8.8px; color: #646982; margin-top: 2px; }

    /* Bottom summary (Payment + Totals) */
    .summary-table { width: 100%; border-collapse: collapse; margin-top: 4px; }
    .summary-table > tbody > tr > td { vertical-align: top; }
    .payment-box-wrap { width: 48%; padding-right: 12px; }
    .totals-box-wrap { width: 52%; padding-left: 12px; }

    .payment-card {
        background: #f8f8fc;
        border: 1px solid #e5e7f0;
        border-radius: 8px;
        padding: 11px 13px;
    }
    .totals-table { width: 100%; border-collapse: collapse; }
    .totals-table td {
        padding: 6px 10px;
        border-bottom: 1px solid #eceef6;
        font-size: 10.5px;
    }
    .totals-table tr.grand-total td {
        background: #4f46e5;
        color: #ffffff;
        font-weight: bold;
        font-size: 12px;
        padding: 9px 10px;
        border-bottom: none;
    }
    .balance-ok { color: #166534; font-weight: bold; }
    .balance-due { color: #b45309; font-weight: bold; }

    /* Footer */
    .doc-footer {
        margin-top: 32px;
        padding-top: 12px;
        border-top: 1px solid #e5e7f0;
        color: #646982;
        font-size: 8.8px;
        text-align: center;
    }
</style>
</head>
<body>
@php
    $money = function ($value) use ($invoice) {
        $normalized = bcadd((string) ($value ?? '0'), '0', 4);
        $negative = str_starts_with($normalized, '-');
        $abs = ltrim($normalized, '-');
        $parts = explode('.', $abs);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
        $fraction = str_pad(rtrim($parts[1] ?? '00', '0'), 2, '0');
        $prefix = $invoice->currency === 'USD' ? 'US$' : $invoice->currency;
        return ($negative ? '-' : '').$prefix.' '.$whole.'.'.$fraction;
    };
    $qty = function ($value) {
        $normalized = bcadd((string) ($value ?? '0'), '0', 4);
        $trimmed = rtrim(rtrim($normalized, '0'), '.');
        return $trimmed === '' ? '0' : $trimmed;
    };
    $tz = $business['timezone'] ?? 'America/Santo_Domingo';
    try {
        $issuedFormatted = \Carbon\Carbon::parse($invoice->issued_at)->timezone($tz)->format('d/m/Y · h:i a');
    } catch (\Throwable) {
        $issuedFormatted = (string) $invoice->issued_at;
    }

    $brandName = trim((string) ($business['name'] ?? 'BShop'));
    $brandInitial = mb_strtoupper(mb_substr($brandName, 0, 1));
    $paidTotal = $settlement['paid_total'] ?? (($weekly ?? null) ? $weekly->paid_at_close : '0.0000');
    $pendingTotal = $settlement['pending_total'] ?? bcsub((string) $invoice->grand_total, (string) $paidTotal, 4);
    if (bccomp((string) $pendingTotal, '0', 4) < 0) {
        $pendingTotal = '0.0000';
    }
    $isPaid = bccomp((string) $pendingTotal, '0', 4) === 0 && bccomp((string) $invoice->grand_total, '0', 4) > 0;
    $isPartial = ! $isPaid && bccomp((string) $paidTotal, '0', 4) > 0;
    $statusLabel = $isPaid ? 'Pagada' : ($isPartial ? 'Pago parcial' : 'Pendiente');
    $statusClass = $isPaid ? 'status-paid' : ($isPartial ? 'status-partial' : 'status-pending');

    $methodLabels = array_merge([
        'cash' => 'Efectivo',
        'bank_transfer' => 'Transferencia',
        'transfer' => 'Transferencia',
        'card' => 'Tarjeta',
        'zelle' => 'Zelle',
        'cash_app' => 'Cash App',
        'paypal' => 'PayPal',
        'venmo' => 'Venmo',
        'credit' => 'Crédito',
        'afterpay' => 'Afterpay',
        'klarna' => 'Klarna',
        'apple_pay' => 'Apple Pay',
        'mobile' => 'Pago móvil',
        'other' => 'Otro',
    ], $paymentMethodLabels ?? []);
    $formattedMethods = collect($paymentMethods ?? [])
        ->map(fn ($m) => $methodLabels[$m] ?? ucfirst((string) $m))
        ->filter()
        ->unique()
        ->implode(', ');

    $hasDiscount = bccomp((string) ($invoice->discount_total ?? '0'), '0', 4) > 0;
    $hasTax = bccomp((string) ($invoice->tax_total ?? '0'), '0', 4) > 0;
    $hasShipping = bccomp((string) ($invoice->shipping_total ?? '0'), '0', 4) > 0;
@endphp

<table class="header-table">
    <tr>
        <td style="width: 60%;">
            <table class="brand-table">
                <tr>
                    <td><div class="brand-mark">{{ $brandInitial }}</div></td>
                    <td class="brand-info">
                        <div class="brand-name">{{ $brandName }}</div>
                        @if(!empty($business['legal_name']) && $business['legal_name'] !== $brandName)
                            <div class="brand-sub">{{ $business['legal_name'] }}</div>
                        @endif
                        @if(!empty($business['address']) || !empty($business['phone']))
                            <div class="brand-sub">
                                {{ implode(' · ', array_filter([$business['address'] ?? null, $business['phone'] ?? null])) }}
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        <td class="right" style="width: 40%;">
            <div class="doc-eyebrow">Factura</div>
            <div class="doc-number nowrap">{{ $invoice->invoice_number }}</div>
            <span class="status-pill {{ $statusClass }}">{{ $statusLabel }}</span>
        </td>
    </tr>
</table>

<table class="cards-table">
    <tr>
        <td class="card-left-wrap">
            <div class="info-card">
                <div class="card-title">Facturar a (Cliente)</div>
                <div class="client-name">{{ $customer['full_name'] ?? 'Cliente' }}</div>
                <table class="kv-table">
                    @if(!empty($customer['phone']))
                        <tr>
                            <td class="kv-label">Teléfono:</td>
                            <td class="kv-value nowrap">{{ $customer['phone'] }}</td>
                        </tr>
                    @endif
                    @if(!empty($customer['email']))
                        <tr>
                            <td class="kv-label">Correo:</td>
                            <td class="kv-value">{{ $customer['email'] }}</td>
                        </tr>
                    @endif
                    @if(!empty($customer['address']))
                        <tr>
                            <td class="kv-label">Dirección:</td>
                            <td class="kv-value">{{ $customer['address'] }}</td>
                        </tr>
                    @endif
                    @if(empty($customer['phone']) && empty($customer['email']) && empty($customer['address']))
                        <tr>
                            <td class="muted small" colspan="2">Sin datos de contacto adicionales registrados.</td>
                        </tr>
                    @endif
                </table>
            </div>
        </td>
        <td class="card-right-wrap">
            <div class="info-card">
                <div class="card-title">Detalles de la factura</div>
                <table class="kv-table">
                    <tr>
                        <td class="kv-label">Emisión:</td>
                        <td class="kv-value nowrap">{{ $issuedFormatted }}</td>
                    </tr>
                    <tr>
                        <td class="kv-label">Referencia:</td>
                        <td class="kv-value nowrap">{{ $invoice->weekly_account_id ? 'Cuenta semanal #'.$invoice->weekly_account_id : 'Pedido #'.$invoice->order_id }}</td>
                    </tr>
                    <tr>
                        <td class="kv-label">Sucursal:</td>
                        <td class="kv-value">{{ $brandName }}</td>
                    </tr>
                    <tr>
                        <td class="kv-label">Moneda:</td>
                        <td class="kv-value">{{ $invoice->currency }}</td>
                    </tr>
                    @if($weekly ?? null)
                        <tr>
                            <td class="kv-label">Período:</td>
                            <td class="kv-value">{{ $weekly->period_start }} al {{ $weekly->period_end }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>

<table class="items-table">
    <thead>
        <tr>
            <th style="width: 52%;">Descripción / SKU</th>
            <th class="right" style="width: 12%;">Cant.</th>
            <th class="right" style="width: 18%;">Precio unit.</th>
            <th class="right" style="width: 18%;">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td>
                    <div class="item-title">{{ $item->description }}</div>
                    <div class="item-sku">SKU: {{ $item->sku_snapshot }}@if($invoice->weekly_account_id) · Pedido #{{ $item->order_id }}@endif</div>
                </td>
                <td class="right nowrap">{{ $qty($item->quantity) }}</td>
                <td class="right nowrap">{{ $money($item->unit_price) }}</td>
                <td class="right nowrap"><strong>{{ $money($item->line_total) }}</strong></td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="summary-table">
    <tr>
        <td class="payment-box-wrap">
            <div class="payment-card">
                <div class="card-title">Resumen de pago</div>
                <table class="kv-table">
                    <tr>
                        <td class="kv-label">Estado:</td>
                        <td class="kv-value"><strong>{{ $statusLabel }}</strong></td>
                    </tr>
                    <tr>
                        <td class="kv-label">Abonado:</td>
                        <td class="kv-value nowrap">{{ $money($paidTotal) }}</td>
                    </tr>
                    <tr>
                        <td class="kv-label">Pendiente:</td>
                        <td class="kv-value nowrap {{ $isPaid ? 'balance-ok' : 'balance-due' }}">{{ $money($pendingTotal) }}</td>
                    </tr>
                    @if($formattedMethods !== '')
                        <tr>
                            <td class="kv-label">Método:</td>
                            <td class="kv-value">{{ $formattedMethods }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        </td>
        <td class="totals-box-wrap">
            <table class="totals-table">
                <tr>
                    <td class="muted">Subtotal</td>
                    <td class="right nowrap">{{ $money($invoice->subtotal) }}</td>
                </tr>
                @if($hasDiscount)
                    <tr>
                        <td class="muted">Descuento</td>
                        <td class="right nowrap">-{{ $money($invoice->discount_total) }}</td>
                    </tr>
                @endif
                @if($hasTax)
                    <tr>
                        <td class="muted">Impuestos</td>
                        <td class="right nowrap">{{ $money($invoice->tax_total) }}</td>
                    </tr>
                @endif
                @if($hasShipping)
                    <tr>
                        <td class="muted">Envío</td>
                        <td class="right nowrap">{{ $money($invoice->shipping_total) }}</td>
                    </tr>
                @endif
                <tr class="grand-total">
                    <td>TOTAL</td>
                    <td class="right nowrap">{{ $money($invoice->grand_total) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="doc-footer">
    Gracias por su preferencia · Documento histórico de la operación.<br>
    <strong>{{ $brandName }}</strong> · Emitido con BShop
</div>
</body>
</html>
