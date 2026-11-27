<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Catálogo · {{ $store->name }}</title>
    <meta property="og:title" content="Catálogo disponible · {{ $store->name }}">
    <meta property="og:description" content="Explora nuestras prendas disponibles con fotos, precios y stock en vivo. Toca cualquier prenda para apartarla al instante por WhatsApp.">
    @if($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="theme-color" content="{{ $accent }}">
    <style>
        :root { --accent: {{ $accent }}; --bg: #f6f5fb; --card: #ffffff; --text: #17152c; --muted: #646982; --border: #e6e3f5; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); padding-bottom: 92px; }
        .topbar { position: sticky; top: 0; z-index: 20; background: #fff; border-bottom: 1px solid var(--border); padding: 12px 16px; }
        .topbar-inner { max-width: 1120px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .brand img { width: 42px; height: 42px; border-radius: 10px; object-fit: cover; border: 1px solid var(--border); flex-shrink: 0; }
        .brand-mark { width: 42px; height: 42px; border-radius: 10px; background: var(--accent); color: #fff; font-weight: 800; display: grid; place-items: center; flex-shrink: 0; }
        .brand h1 { margin: 0; font-size: 1.05rem; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .brand small { color: var(--muted); font-size: 0.78rem; }
        .wa-pill { background: #dcfce7; color: #166534; font-weight: 700; font-size: 0.78rem; padding: 6px 10px; border-radius: 999px; white-space: nowrap; text-decoration: none; }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 14px 14px 24px; }
        .controls { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
        .search-row { display: flex; gap: 8px; align-items: center; }
        .search-row input { flex: 1; padding: 11px 14px; border-radius: 12px; border: 1px solid var(--border); font-size: 0.95rem; background: #fff; }
        .toggle-stock { display: inline-flex; align-items: center; gap: 6px; font-size: 0.82rem; font-weight: 600; color: var(--muted); white-space: nowrap; cursor: pointer; }
        .chips { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px; }
        .chip { border: 1px solid var(--border); background: #fff; color: #374151; padding: 7px 13px; border-radius: 999px; font-size: 0.82rem; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .chip.active { background: var(--accent); border-color: var(--accent); color: #fff; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        @media (min-width: 680px) { .grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; } }
        @media (min-width: 980px) { .grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 2px 6px rgba(23,21,44,0.04); }
        .card.soldout { opacity: 0.62; }
        .photo-box { position: relative; aspect-ratio: 4/5; background: #efeefa; overflow: hidden; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .photo-placeholder { width: 100%; height: 100%; display: grid; place-items: center; font-size: 2rem; font-weight: 800; color: var(--accent); }
        .code-badge { position: absolute; top: 8px; left: 8px; background: rgba(23,21,44,0.82); color: #fff; font-size: 0.72rem; font-weight: 800; padding: 3px 8px; border-radius: 999px; }
        .stock-badge { position: absolute; bottom: 8px; left: 8px; font-size: 0.72rem; font-weight: 700; padding: 3px 8px; border-radius: 999px; }
        .stock-ok { background: #dcfce7; color: #166534; }
        .stock-low { background: #fef3c7; color: #92400e; }
        .stock-out { background: #fee2e2; color: #991b1b; }
        .card-body { padding: 10px 11px 12px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
        .card-title { margin: 0; font-size: 0.92rem; line-height: 1.25; font-weight: 700; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .card-price { font-size: 1rem; font-weight: 800; color: var(--accent); white-space: nowrap; }
        .variants { display: flex; flex-wrap: wrap; gap: 4px; }
        .v-btn { border: 1px solid var(--border); background: #faf9ff; color: var(--text); font-size: 0.72rem; font-weight: 700; padding: 3px 7px; border-radius: 6px; cursor: pointer; }
        .v-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }
        .v-btn:disabled { opacity: 0.4; text-decoration: line-through; cursor: not-allowed; }
        .card-actions { margin-top: auto; display: grid; grid-template-columns: 1fr auto; gap: 6px; padding-top: 4px; }
        .btn-wa { background: #16a34a; color: #fff; border: 0; border-radius: 10px; padding: 9px 8px; font-size: 0.8rem; font-weight: 700; text-align: center; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .btn-add { background: #f3f0ff; color: var(--accent); border: 1px solid #ddd6fe; border-radius: 10px; padding: 9px 10px; font-size: 0.8rem; font-weight: 800; cursor: pointer; white-space: nowrap; }
        .btn-add.added { background: var(--accent); color: #fff; border-color: var(--accent); }
        .cart-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; background: #17152c; color: #fff; padding: 12px 16px calc(12px + env(safe-area-inset-bottom, 0px)); box-shadow: 0 -6px 20px rgba(0,0,0,0.18); display: none; }
        .cart-bar.open { display: block; }
        .cart-inner { max-width: 1120px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .cart-summary { display: flex; flex-direction: column; gap: 2px; }
        .cart-summary strong { font-size: 0.94rem; }
        .cart-summary small { color: #cbd5e1; font-size: 0.78rem; }
        .cart-btns { display: flex; gap: 8px; align-items: center; }
        .btn-clear { background: transparent; color: #cbd5e1; border: 1px solid #475569; border-radius: 10px; padding: 9px 12px; font-size: 0.82rem; font-weight: 600; cursor: pointer; }
        .btn-wa-all { background: #22c55e; color: #fff; border: 0; border-radius: 10px; padding: 10px 16px; font-size: 0.88rem; font-weight: 800; text-decoration: none; white-space: nowrap; }
        .empty { text-align: center; padding: 40px 16px; color: var(--muted); }
        .deposit-banner { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; border-radius: 12px; padding: 10px 14px; font-size: 0.84rem; line-height: 1.45; margin-bottom: 12px; }
        .deposit-banner strong { color: #92400e; }
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            @if($logoUri)
                <img src="{{ $logoUri }}" alt="{{ $store->name }}">
            @else
                <div class="brand-mark">{{ mb_strtoupper(mb_substr($store->name, 0, 1)) }}</div>
            @endif
            <div>
                <h1>{{ $store->name }}</h1>
                <small>Catálogo en vivo · toca una prenda para apartarla</small>
            </div>
        </div>
        @if($waDigits)
            <a class="wa-pill" href="https://wa.me/{{ $waDigits }}" target="_blank" rel="noopener">WhatsApp oficial</a>
        @endif
    </div>
</header>

<main class="wrap">
    @if(!empty($depositPolicy['require_deposit_for_new']))
        @php
            $depLabel = ($depositPolicy['deposit_mode'] ?? 'fixed') === 'percent'
                ? rtrim(rtrim(number_format((float) ($depositPolicy['deposit_value'] ?? 0), 2, '.', ''), '0'), '.') . '% del total'
                : $currencyPrefix . ' ' . number_format((float) ($depositPolicy['deposit_value'] ?? 0), 2, '.', ',');
        @endphp
        <div class="deposit-banner" role="note">
            <strong>¿Cómo funciona el apartado?</strong> Clientas nuevas confirman su apartado con un depósito de <strong style="white-space:nowrap;">{{ $depLabel }}</strong>. Los artículos siguen disponibles hasta confirmar el pago. Clientas habituales pueden apartar directo.
        </div>
    @endif
    <section class="controls">
        <div class="search-row">
            <input id="q" type="search" placeholder="Buscar prenda o código (ej. K-222)..." autocomplete="off">
            <label class="toggle-stock">
                <input id="onlyStock" type="checkbox" checked> Solo disponibles
            </label>
        </div>
        <div id="catChips" class="chips">
            <button type="button" class="chip active" data-cat="">Todas</button>
            @foreach($categories as $cat)
                <button type="button" class="chip" data-cat="{{ $cat }}">{{ $cat }}</button>
            @endforeach
        </div>
    </section>

    <section id="grid" class="grid" aria-label="Productos disponibles"></section>
    <p id="empty" class="empty" style="display:none;">No hay productos disponibles con ese filtro.</p>
</main>

<div id="cartBar" class="cart-bar" role="region" aria-label="Lista de prendas para apartar">
    <div class="cart-inner">
        <div class="cart-summary">
            <strong id="cartCount">0 prendas seleccionadas</strong>
            <small id="cartCodes"></small>
        </div>
        <div class="cart-btns">
            <button type="button" id="clearCart" class="btn-clear">Limpiar</button>
            <a id="waCartLink" class="btn-wa-all" href="#" target="_blank" rel="noopener">{{ !empty($depositPolicy['require_deposit_for_new']) ? 'Solicitar apartado por WhatsApp →' : 'Apartar todo por WhatsApp →' }}</a>
        </div>
    </div>
</div>

<script>
const ITEMS = @json($items);
const WA_DIGITS = @json($waDigits);
const CURRENCY = @json($currencyPrefix);
const REQUIRE_DEPOSIT = @json(!empty($depositPolicy['require_deposit_for_new']));

let activeCat = '';
let selectedVariantByProduct = {};
let cart = [];

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function waUrl(text) {
    return WA_DIGITS ? `https://wa.me/${WA_DIGITS}?text=${encodeURIComponent(text)}` : '#';
}

function commandForItem(item) {
    const vId = selectedVariantByProduct[item.id];
    const v = item.variants.find(x => x.id === vId) || item.variants.find(x => x.in_stock) || item.variants[0];
    if (v && v.label && item.variants.length > 1) {
        return `${item.code} ${v.label}`;
    }
    return item.code;
}

function renderCart() {
    const bar = document.getElementById('cartBar');
    if (!cart.length) { bar.classList.remove('open'); return; }
    bar.classList.add('open');
    document.getElementById('cartCount').textContent = `${cart.length} ${cart.length === 1 ? 'prenda seleccionada' : 'prendas seleccionadas'}`;
    document.getElementById('cartCodes').textContent = cart.map(c => c.cmd).join(', ');
    document.getElementById('waCartLink').href = waUrl(cart.map(c => c.cmd).join('\n'));
}

function toggleCart(itemId) {
    const item = ITEMS.find(x => x.id === itemId);
    if (!item || !item.in_stock) return;
    const cmd = commandForItem(item);
    const idx = cart.findIndex(c => c.cmd === cmd);
    if (idx >= 0) cart.splice(idx, 1);
    else if (cart.length < 10) cart.push({ id: item.id, cmd, name: item.name });
    renderCart();
    renderGrid();
}

function selectVariant(itemId, variantId) {
    selectedVariantByProduct[itemId] = variantId;
    renderGrid();
}

function renderGrid() {
    const q = (document.getElementById('q').value || '').trim().toLowerCase();
    const onlyStock = document.getElementById('onlyStock').checked;
    const filtered = ITEMS.filter(it => {
        if (onlyStock && !it.in_stock) return false;
        if (activeCat && it.category !== activeCat) return false;
        if (q && !it.name.toLowerCase().includes(q) && !it.code.toLowerCase().includes(q)) return false;
        return true;
    });

    const grid = document.getElementById('grid');
    const empty = document.getElementById('empty');
    if (!filtered.length) {
        grid.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';

    grid.innerHTML = filtered.map(it => {
        const chosenId = selectedVariantByProduct[it.id] || (it.variants.find(v => v.in_stock)?.id ?? it.variants[0]?.id);
        const chosenVar = it.variants.find(v => v.id === chosenId) || it.variants[0];
        const price = chosenVar ? chosenVar.price : it.price;
        const img = it.images.find(im => im.variant_id === chosenId) || it.images[0];
        const cmd = commandForItem(it);
        const inCart = cart.some(c => c.cmd === cmd);

        let stockBadge = '';
        if (!it.in_stock) stockBadge = '<span class="stock-badge stock-out">Agotado</span>';
        else if (it.available !== null && it.available <= 3) stockBadge = `<span class="stock-badge stock-low">Últimas ${it.available}</span>`;
        else stockBadge = '<span class="stock-badge stock-ok">Disponible</span>';

        const variantPills = it.variants.length > 1
            ? `<div class="variants">${it.variants.map(v => `<button type="button" class="v-btn ${v.id === chosenId ? 'active' : ''}" ${v.in_stock ? '' : 'disabled'} onclick="selectVariant(${it.id},${v.id})">${escapeHtml(v.label || v.sku)}</button>`).join('')}</div>`
            : '';

        const actions = it.in_stock && WA_DIGITS
            ? `<div class="card-actions">
                    <a class="btn-wa" href="${waUrl(cmd)}" target="_blank" rel="noopener">${REQUIRE_DEPOSIT ? 'Solicitar apartado' : 'Apartar ahora'}</a>
                    <button type="button" class="btn-add ${inCart ? 'added' : ''}" onclick="toggleCart(${it.id})">${inCart ? '✓ Lista' : '+ Lista'}</button>
               </div>`
            : `<div class="card-actions"><span style="font-size:0.78rem;color:#646982;">${it.in_stock ? 'Código: ' + escapeHtml(it.code) : 'Sin disponibilidad'}</span></div>`;

        return `<article class="card ${it.in_stock ? '' : 'soldout'}">
            <div class="photo-box">
                ${img ? `<img src="${escapeHtml(img.url)}" alt="${escapeHtml(it.name)}" loading="lazy">` : `<div class="photo-placeholder">${escapeHtml(it.name.slice(0,1).toUpperCase())}</div>`}
                <span class="code-badge">${escapeHtml(it.code)}</span>
                ${stockBadge}
            </div>
            <div class="card-body">
                <h2 class="card-title">${escapeHtml(it.name)}</h2>
                <div class="card-price">${escapeHtml(CURRENCY)} ${escapeHtml(price)}</div>
                ${variantPills}
                ${actions}
            </div>
        </article>`;
    }).join('');
}

document.getElementById('q').addEventListener('input', renderGrid);
document.getElementById('onlyStock').addEventListener('change', renderGrid);
document.getElementById('catChips').addEventListener('click', e => {
    const btn = e.target.closest('.chip');
    if (!btn) return;
    document.querySelectorAll('#catChips .chip').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeCat = btn.dataset.cat || '';
    renderGrid();
});
document.getElementById('clearCart').addEventListener('click', () => { cart = []; renderCart(); renderGrid(); });

renderGrid();
</script>
</body>
</html>
