<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>{{ $order ? 'Resumen del apartado' : 'Enlace no disponible' }} · BShop</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f5f3fa;color:#28213e;font:16px/1.5 system-ui,sans-serif}main{max-width:760px;margin:auto;padding:24px 16px}header,article,.summary,.empty{background:white;border:1px solid #e5dff0;border-radius:16px;padding:20px;margin-bottom:16px}h1{font-size:26px;line-height:1.2}h2{font-size:18px;margin:0}p{overflow-wrap:anywhere}.muted{color:#655c73}.item{display:grid;gap:8px}dl{margin:0;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px}dt,dd{margin:0}dd{font-weight:650;white-space:nowrap}.money{white-space:nowrap}.total{font-size:20px;color:#643cc0}.brand{font-weight:750;color:#643cc0}footer{font-size:14px}.variants{overflow-wrap:anywhere}@media(max-width:360px){.total{font-size:17px}header,article,.summary{padding:16px}}
    </style>
</head>
<body><main>
    <p class="brand">BShop · Pedido compartido</p>
    @if($order)
        @php
            $money = fn ($value) => ($order['currency'] === 'USD' ? 'US$' : $order['currency']).' '.bcadd((string) $value, '0', 2);
            $quantity = fn ($value) => rtrim(rtrim(bcadd((string) $value, '0', 4), '0'), '.') ?: '0';
        @endphp
        <header><p class="muted">{{ $order['store_name'] }}</p><h1>Apartado #{{ $order['number'] }}</h1><p>{{ $order['status'] === 'fulfilled' ? 'Entregado' : ($order['status'] === 'confirmed' ? 'Facturado' : 'Reserva activa') }}{{ $order['shipped'] ? ' · Enviado' : '' }}</p><p class="muted">Resumen actualizado del pedido guardado.</p></header>
        <section aria-label="Artículos del apartado">
        @if($order['return_pending'] ?? false)<p>Hay mercancía devuelta con ajuste económico pendiente. Los importes mostrados corresponden al documento original; coordina la conciliación con la tienda.</p>@endif
        @foreach($order['items'] as $item)
            <article class="item"><h2>{{ $item['name'] }}</h2>
                @if($item['variants'])<span class="variants muted">{{ implode(' · ', $item['variants']) }}</span>@endif
                <span>{{ $quantity($item['quantity']) }} × <span class="money">{{ $money($item['price']) }}</span></span><strong class="money">{{ $money($item['total']) }}</strong>
            </article>
        @endforeach
        </section>
        <section class="summary" aria-label="Resumen de importes"><dl>
            <dt>Subtotal</dt><dd>{{ $money($order['subtotal']) }}</dd>
            <dt>Descuento</dt><dd>{{ $money($order['discount']) }}</dd>
            <dt>Impuestos</dt><dd>{{ $money($order['tax']) }}</dd>
            <dt>Envío</dt><dd>{{ $money($order['shipping']) }}</dd>
            <dt class="total">Total</dt><dd class="total">{{ $money($order['total']) }}</dd>
            @if(($order['return_credit_total'] ?? '0.0000') !== '0.0000')<dt>Total original</dt><dd>{{ $money($order['original_total']) }}</dd><dt>Ajuste por devolución</dt><dd>{{ $money($order['return_credit_total']) }}</dd><dt>Saldo a favor disponible</dt><dd>{{ $money($order['credit_available']) }}</dd><dt>Reembolso registrado</dt><dd>{{ $money($order['refunded_total']) }}</dd>@endif
            <dt>Abonado</dt><dd>{{ $money($order['paid']) }}</dd>
            <dt>Pendiente</dt><dd>{{ $money($order['pending']) }}</dd>
        </dl></section>
        <footer class="muted"><p>Consulta disponible hasta <time datetime="{{ $order['expires_at'] }}">{{ $order['expires_at'] }}</time>. Comparte este enlace únicamente con la persona destinataria.</p><p>Para solicitar cambios o coordinar el pago, contacta con la tienda.</p></footer>
    @else
        <section class="empty"><h1>Enlace no disponible</h1><p>Solicita a la tienda un enlace vigente para consultar tu pedido.</p></section>
    @endif
</main></body></html>
