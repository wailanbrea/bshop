<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Business, Store } from '../types';

const props = defineProps<{
    business: Business;
    store?: Store;
}>();

const search = ref('');
const activeCategory = ref('all');
const copiedUrl = ref(false);

const catalogUrl = computed(() => (props.store?.id ? `${window.location.origin}/catalogo/${props.store.id}` : ''));

async function copyCatalogUrl() {
    if (!catalogUrl.value) return;
    try {
        await navigator.clipboard.writeText(catalogUrl.value);
        copiedUrl.value = true;
        setTimeout(() => { copiedUrl.value = false; }, 2200);
    } catch {
        /* clipboard unavailable */
    }
}

type HelpItem = {
    id: string;
    category: 'whatsapp' | 'ventas' | 'catalogo' | 'admin';
    badge: string;
    title: string;
    summary: string;
    steps: string[];
    tip?: string;
};

const categories = [
    { key: 'all', label: 'Todo' },
    { key: 'whatsapp', label: 'WhatsApp y CRM' },
    { key: 'ventas', label: 'Apartados y Ventas' },
    { key: 'catalogo', label: 'Productos e Inventario' },
    { key: 'admin', label: 'Facturas y Equipo' },
];

const guides: HelpItem[] = [
    {
        id: 'catalogo-publico',
        category: 'whatsapp',
        badge: 'Catálogo en vivo',
        title: 'Catálogo Público con fotos y enlace directo',
        summary: 'Comparte todas tus prendas disponibles con fotos, tallas, precios y stock real en un solo enlace para tu grupo de WhatsApp o Instagram.',
        steps: [
            'Ve a Productos → Catálogo y toca «🌐 Catálogo con fotos» para copiar el enlace público de tu sucursal.',
            'Pégalo en tu grupo de WhatsApp, estados o biografía de Instagram.',
            'Cada clienta entra, ve todas las fotos reales y toca «Apartar ahora» (1 prenda) o «+ Lista» (varias prendas juntas) para enviar los códigos a tu WhatsApp oficial.',
            'También puedes tocar el ícono «🔗» en cualquier prenda individual o «📋 Lista para grupo» para copiar la lista en texto con enlaces directos.',
        ],
        tip: 'Si una prenda se agota en almacén, el catálogo público la marca como «Agotado» automáticamente para evitar sobreventas.',
    },
    {
        id: 'auto-apartado',
        category: 'whatsapp',
        badge: 'Automático',
        title: 'Auto-apartado por código (K-...) en WhatsApp',
        summary: 'Cuando una clienta escribe el código de una prenda (ej. K-222) o su SKU al WhatsApp oficial, el sistema la agrega automáticamente a su apartado.',
        steps: [
            'Cada producto tiene un código corto visible en su tarjeta (ej. K-222).',
            'La clienta envía «K-222» (o «K-222 x2», o varios separados por coma/salto de línea desde el Catálogo Público).',
            'Si la prenda tiene varias tallas o colores, el bot le envía botones interactivos en WhatsApp para elegir su talla.',
            'El sistema agrega la prenda a su Apartado abierto (o crea clienta + apartado nuevo si es primera vez), reserva el stock al instante y le confirma el total gratis dentro de la ventana de 24 h.',
        ],
        tip: 'Puedes activar o pausar esta función por sucursal en Configuración → Automatizaciones WhatsApp → Auto-apartado por código.',
    },
    {
        id: 'crm-whatsapp',
        category: 'whatsapp',
        badge: 'CRM',
        title: 'Mensajes WhatsApp, Etiquetas, Notas internas y Embudo Kanban',
        summary: 'Atiende todos los mensajes en un solo lugar, clasifica clientas y organiza tus ventas por etapas.',
        steps: [
            'Filtros rápidos: usa «Todos», «No leídos», «Leídos», «24h gratis» y filtra por etiqueta o vendedor asignado.',
            'Etiquetas: toca «+ VIP», «+ Mayorista» o «+ Por despachar» en el chat, o crea nuevas etiquetas con color en «🏷️ Etiquetas».',
            'Asignación y Notas internas: asigna el chat a un vendedor y usa el botón «🔒 Nota interna» para dejar comentarios en amarillo que solo ve tu equipo (nunca se envían a la clienta).',
            'Embudo Kanban: cambia a «📊 Embudo Kanban» para ver y arrastrar clientas entre Nuevo lead → Cotizando → Apartado → Por pagar → Cerrado.',
            'Respuestas rápidas: escribe «/» en el chat (ej. /envio, /zelle, /horario) o toca «⚡» para responder en 1 segundo.',
        ],
    },
    {
        id: 'apartados',
        category: 'ventas',
        badge: 'Operación diaria',
        title: 'Apartados, filtros y fecha de apertura',
        summary: 'Controla canastas reservadas, depósitos parciales, facturas y entregas desde una sola cuadrícula.',
        steps: [
            'Usa los filtros superiores: «Sin depósito», «Abierto», «Facturado», «Factura enviada», «Pagado», «Enviado» y «Todos».',
            'Al abrir un apartado verás la fecha y hora exacta en que se abrió debajo del teléfono de la clienta.',
            'Puedes agregar o quitar prendas mientras el apartado esté abierto; el inventario se reserva o libera automáticamente.',
            'Al generar factura se crea el PDF histórico con fotos y el bloque unificado (Total factura, Abonado y Total pendiente).',
            'Si necesitas seguir agregando prendas a un pedido ya facturado pero no enviado, usa «Devolver a apartados».',
        ],
    },
    {
        id: 'envio-masivo',
        category: 'ventas',
        badge: 'Cobros',
        title: 'Envío masivo de facturas por WhatsApp',
        summary: 'Envía recordatorios o facturas PDF a múltiples clientas en lote o de forma programada.',
        steps: [
            'En Apartados, abre el botón de envío masivo de WhatsApp.',
            'Selecciona las clientas con factura generada y elige envío automático por API (requiere plantilla aprobada por Meta para clientas fuera de ventana 24 h) o envío rápido manual con botón wa.me.',
            'El sistema protege contra envíos duplicados y normaliza teléfonos de 10 dígitos (+1) automáticamente.',
        ],
    },
    {
        id: 'cuentas-semanales-cxc',
        category: 'ventas',
        badge: 'Finanzas',
        title: 'Cuentas por cobrar (CxC) y Cuentas semanales',
        summary: 'Lleva el control exacto de saldos pendientes y agrupa varios apartados de una misma clienta en una sola factura semanal.',
        steps: [
            'En Cuentas por cobrar ves todas las clientas con saldo pendiente, abonos realizados y botón para recordar pago.',
            'En Cuentas semanales puedes agrupar varios apartados de la misma clienta durante la semana y cerrar una sola factura PDF consolidada al final del período.',
        ],
    },
    {
        id: 'productos-inventario',
        category: 'catalogo',
        badge: 'Stock FIFO',
        title: 'Productos, Variantes, Fotos y Stock / Kardex',
        summary: 'Administra tu catálogo visual con fotos por color/talla y controla entradas, salidas y costos reales FIFO.',
        steps: [
            'Usa «+ Nuevo producto» para crear 1 artículo o «+ Varias fotos» para subir lotes rápidos desde el celular.',
            'En la pestaña «Stock y Kardex» (dentro de Productos) registras entradas de inventario, ajustes, traslados entre almacenes y consultas el historial completo de movimientos.',
            'Las fotos asignadas a cada variante se reflejan automáticamente en Apartados, Catálogo Público y Facturas PDF.',
        ],
    },
    {
        id: 'vista-cliente',
        category: 'ventas',
        badge: 'Enlaces privados',
        title: 'Vista del cliente (/s/...)',
        summary: 'Genera enlaces privados y temporales para que una clienta específica vea un catálogo personalizado o el detalle de su pedido.',
        steps: [
            'Ve a «Vista del cliente» para crear un enlace privado con productos seleccionados y vencimiento (1 h a 7 días).',
            'Cuando la clienta confirma desde ese enlace, su pedido entra directo a tu sucursal respetando el stock disponible.',
        ],
    },
    {
        id: 'facturas-config',
        category: 'admin',
        badge: 'Marca y Equipo',
        title: 'Diseño de Factura PDF, Métodos de pago, Vendedores y Equipo',
        summary: 'Personaliza la imagen de tu tienda y controla permisos de cada persona del equipo.',
        steps: [
            'En Configuración → Diseño de factura PDF sube tu logo, elige tu color principal, pie de página y activa/desactiva fotos de productos.',
            'En Configuración → Métodos de pago activa Zelle, Cash App, Transferencia, Efectivo o crea métodos personalizados.',
            'En Vendedores y Equipo invita colaboradores con roles y permisos limitados por sucursal.',
            'En Configuración → WhatsApp conectas el número oficial de cada sucursal de forma 100% aislada.',
        ],
    },
];

const filteredGuides = computed(() => {
    const q = search.value.trim().toLowerCase();
    return guides.filter((g) => {
        if (activeCategory.value !== 'all' && g.category !== activeCategory.value) return false;
        if (!q) return true;
        return (
            g.title.toLowerCase().includes(q)
            || g.summary.toLowerCase().includes(q)
            || g.badge.toLowerCase().includes(q)
            || g.steps.some((s) => s.toLowerCase().includes(q))
        );
    });
});
</script>

<template>
    <section class="help-shell" aria-label="Centro de ayuda y guía de uso">
        <header class="panel help-hero">
            <div class="help-hero-top">
                <div>
                    <span class="eyebrow">GUÍA DE USO · {{ store?.name || business.name }}</span>
                    <h2>Centro de ayuda de KeylaShop</h2>
                    <p class="muted">
                        Encuentra paso a paso cómo funciona cada módulo de la app: Catálogo Público, Auto-apartado por WhatsApp, CRM, Facturas y Stock.
                    </p>
                </div>
                <div v-if="catalogUrl" class="help-catalog-box">
                    <small class="muted">Enlace de tu Catálogo Público con fotos:</small>
                    <code>{{ catalogUrl }}</code>
                    <div class="help-catalog-actions">
                        <button type="button" class="button primary" @click="copyCatalogUrl">
                            {{ copiedUrl ? '✓ Enlace copiado' : 'Copiar enlace' }}
                        </button>
                        <a :href="catalogUrl" target="_blank" rel="noopener" class="button secondary">Abrir catálogo ↗</a>
                    </div>
                </div>
            </div>

            <div class="help-controls">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Buscar tema (ej. auto-apartado, kanban, factura, zelle, stock)…"
                    aria-label="Buscar en el centro de ayuda"
                />
                <div class="help-chips" role="tablist" aria-label="Categorías de ayuda">
                    <button
                        v-for="cat in categories"
                        :key="cat.key"
                        type="button"
                        role="tab"
                        class="help-chip"
                        :class="{ active: activeCategory === cat.key }"
                        :aria-selected="activeCategory === cat.key"
                        @click="activeCategory = cat.key"
                    >
                        {{ cat.label }}
                    </button>
                </div>
            </div>
        </header>

        <p v-if="!filteredGuides.length" class="panel empty">
            No encontramos guías para «{{ search }}». Prueba con otra palabra clave.
        </p>

        <div v-else class="help-grid">
            <article v-for="item in filteredGuides" :key="item.id" class="panel help-card">
                <div class="help-card-head">
                    <span class="badge">{{ item.badge }}</span>
                    <h3>{{ item.title }}</h3>
                    <p class="muted">{{ item.summary }}</p>
                </div>
                <ol class="help-steps">
                    <li v-for="(step, idx) in item.steps" :key="idx">{{ step }}</li>
                </ol>
                <p v-if="item.tip" class="help-tip">
                    <strong>Consejo:</strong> {{ item.tip }}
                </p>
            </article>
        </div>
    </section>
</template>

<style scoped>
.help-shell {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.help-hero {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.help-hero-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}
.help-hero-top h2 {
    margin: 4px 0 6px;
}
.help-catalog-box {
    background: #faf9ff;
    border: 1px solid #ddd6fe;
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: min(320px, 100%);
}
.help-catalog-box code {
    font-size: 0.8rem;
    color: #4c1d95;
    word-break: break-all;
}
.help-catalog-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 2px;
}
.help-catalog-actions .button {
    min-height: 36px;
    padding: 6px 12px;
    font-size: 0.82rem;
    white-space: nowrap;
}
.help-controls {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.help-controls input {
    width: 100%;
    min-height: 44px;
}
.help-chips {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}
.help-chip {
    padding: 6px 13px;
    border-radius: 999px;
    border: 1px solid #e2dff2;
    background: #f8f7fc;
    color: #4b5563;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}
.help-chip.active {
    background: #6d28d9;
    border-color: #6d28d9;
    color: #fff;
}
.help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr));
    gap: 14px;
}
.help-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.help-card-head h3 {
    margin: 8px 0 4px;
    font-size: 1.04rem;
}
.help-card-head p {
    margin: 0;
    font-size: 0.88rem;
}
.help-steps {
    margin: 0;
    padding-left: 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.88rem;
    line-height: 1.45;
    color: #1f2937;
}
.help-tip {
    margin: auto 0 0;
    padding: 9px 11px;
    border-radius: 10px;
    background: #f5f3ff;
    border: 1px solid #e9d5ff;
    font-size: 0.82rem;
    color: #4c1d95;
}
</style>
