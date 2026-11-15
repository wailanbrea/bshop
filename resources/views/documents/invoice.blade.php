<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #191a32; }
h1 { font-size: 24px; color: #5b3fe5; margin: 0 0 8px; } h2 { font-size: 17px; margin-top: 28px; }
.muted { color: #61657d; } .heading { border-bottom: 2px solid #5b3fe5; padding-bottom: 18px; }
table { border-collapse: collapse; width: 100%; margin-top: 22px; } th { background: #f0edfc; text-align: left; padding: 10px 8px; }
td { border-bottom: 1px solid #e4e1ef; padding: 12px 8px; } .right { text-align: right; } .totals { width: 55%; margin-left: 45%; }
.total { font-weight: bold; background: #f0edfc; } footer { margin-top: 40px; color: #61657d; font-size: 9px; }
</style></head><body>
@php
    $money = function ($value) use ($invoice) {
        $parts = explode('.', bcadd((string) $value, '0', 4));
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
        $fraction = str_pad(rtrim($parts[1], '0'), 2, '0');
        return ($invoice->currency === 'USD' ? 'US$' : $invoice->currency).' '.$whole.'.'.$fraction;
    };
@endphp
<div class="heading"><h1>{{ $business['name'] }}</h1><div>{{ $business['legal_name'] ?? '' }}</div><h2>Factura {{ $invoice->invoice_number }}</h2><p class="muted">Emisión: {{ $invoice->issued_at }} · {{ $invoice->weekly_account_id ? 'Cuenta semanal #'.$invoice->weekly_account_id : 'Pedido #'.$invoice->order_id }} · Moneda: {{ $invoice->currency }}</p>@if($weekly ?? null)<p>Período {{ $weekly->period_start }} al {{ $weekly->period_end }} · {{ $weekly->timezone }}</p>@endif</div>
<h2>{{ $customer['full_name'] }}</h2><p>{{ $customer['phone'] ?? '' }} {{ $customer['email'] ?? '' }}</p><p>{{ $customer['address'] ?? '' }}</p>
<table><thead><tr><th>Artículo / SKU</th><th class="right">Cantidad</th><th class="right">Precio</th><th class="right">Importe</th></tr></thead><tbody>
@foreach ($items as $item)<tr><td>{{ $item->description }}<br><span class="muted">{{ $item->sku_snapshot }}@if($invoice->weekly_account_id) · Pedido #{{ $item->order_id }}@endif</span></td><td class="right">{{ bcadd((string) $item->quantity, '0', 4) }}</td><td class="right">{{ $money($item->unit_price) }}</td><td class="right">{{ $money($item->line_total) }}</td></tr>@endforeach
</tbody></table>
<table class="totals"><tr><td>Subtotal</td><td class="right">{{ $money($invoice->subtotal) }}</td></tr><tr><td>Descuento</td><td class="right">{{ $money($invoice->discount_total) }}</td></tr><tr><td>Impuestos</td><td class="right">{{ $money($invoice->tax_total) }}</td></tr><tr><td>Envío</td><td class="right">{{ $money($invoice->shipping_total) }}</td></tr><tr class="total"><td>Total</td><td class="right">{{ $money($invoice->grand_total) }}</td></tr></table>
<footer>Documento histórico de la operación. Los abonos y el saldo actual se consultan en el sistema.<br>{{ $business['name'] }} · BShop</footer>
@if($weekly ?? null)<p>Abonado al cierre: {{ $money($weekly->paid_at_close) }} · Saldo al cierre: {{ $money(bcsub((string)$invoice->grand_total,(string)$weekly->paid_at_close,4)) }}</p>@endif
</body></html>
