import axios from 'axios';
import { computed, onMounted, onUnmounted, ref, watch, type Ref } from 'vue';
import { api, errorMessage } from '../api';
import { readPending, savePending, clearPending } from '../lib/pendingRequests';
import { decimal, units, type CartLine, type SalesCustomer, type SalesProduct, type SalesQuote, type SalesVariant, type SavedOrder } from '../sales';

export function useSalesDraft(root: Ref<string>, warehouseId: Ref<number | ''>, journalKey: string) {
    const customer = ref<SalesCustomer | null>(null); const lines = ref<CartLine[]>([]); const mode = ref<'sale' | 'reservation'>('sale');
    const discount = ref('0'); const shipping = ref('0'); const paid = ref('0'); const method = ref('cash'); const delivery = ref(''); const notes = ref('');
    const quote = ref<SalesQuote | null>(null); const quoting = ref(false); const busy = ref(false); const error = ref(''); const notice = ref('');
    const saved = ref<SavedOrder | null>(null); const uncertain = ref<Record<string, unknown> | null>(null); const documentState = ref('');
    let timer: ReturnType<typeof setTimeout> | undefined; let sequence = 0; let controller: AbortController | undefined;
    const payload = computed(() => ({ warehouse_id: warehouseId.value, customer_id: customer.value?.id || null, lines: lines.value.map(line => ({ variant_id: line.variant_id, quantity: line.quantity })), discount: discount.value || '0', shipping: shipping.value || '0', notes: notes.value, estimated_delivery: delivery.value || null }));
    function add(product: SalesProduct, variant: SalesVariant) {
        if (saved.value || uncertain.value) { error.value = 'Inicia una operación nueva antes de añadir productos.'; return; }
        const existing = lines.value.find(line => line.variant_id === variant.id); const next = (existing ? units(existing.quantity)! : 0n) + 10000n;
        if (variant.available !== null && next > units(variant.available)!) { error.value = `Disponible para ${variant.sku}: ${variant.available}. No puedes añadir más unidades.`; return; }
        error.value = ''; if (existing) existing.quantity = decimal(next);
        else lines.value.push({ variant_id: variant.id, product_id: product.id, name: product.name, sku: variant.sku, quantity: '1.0000', price: variant.price, available: variant.available, attributes: variant.attributes, image: product.image?.renditions.thumb.url || null });
    }
    function changeQuantity(variantId: number, step: number) {
        if (uncertain.value) return; const line = lines.value.find(line => line.variant_id === variantId); if (!line) return;
        const value = units(line.quantity)! + BigInt(step) * 10000n;
        if (value <= 0n) { lines.value = lines.value.filter(l => l.variant_id !== variantId); return; }
        if (line.available !== null && value > units(line.available)!) { error.value = `Stock disponible insuficiente para ${line.sku}.`; return; }
        line.quantity = decimal(value); error.value = '';
    }
    async function calculate() {
        if (saved.value || !lines.value.length || !warehouseId.value || uncertain.value) return;
        const token = ++sequence; controller?.abort(); controller = new AbortController(); quoting.value = true;
        try { const result = await api.post(`${root.value}/sales/quote`, payload.value, { signal: controller.signal }); if (token === sequence) { quote.value = result.data.data; for (const item of result.data.data.lines || []) { const line = lines.value.find(l => l.variant_id === item.variant_id); if (line) line.price = item.unit_price; } error.value = ''; } }
        catch (e) { if (token === sequence && !axios.isCancel(e)) error.value = errorMessage(e); }
        finally { if (token === sequence) quoting.value = false; }
    }
    watch(payload, () => {
        if (saved.value || uncertain.value) return;
        clearTimeout(timer); ++sequence; controller?.abort(); quote.value = null;
        quoting.value = !!lines.value.length; if (lines.value.length) timer = setTimeout(calculate, 200); else quoting.value = false;
    }, { deep: true, flush: 'sync' });
    async function submit(target: 'sale' | 'reservation') {
        if (busy.value || (!uncertain.value && (!quote.value || !customer.value || customer.value.status === 'archived' || quoting.value))) return;
        busy.value = true; error.value = ''; notice.value = '';
        uncertain.value ??= { ...payload.value, request_key: crypto.randomUUID(), quote_hash: quote.value!.quote_hash, mode: target, initial_payment: paid.value || '0', payment_method: method.value };
        try { savePending(journalKey, uncertain.value); } catch { uncertain.value = null; busy.value = false; error.value = 'El navegador no pudo conservar la recuperación. No se envió la operación.'; return; }
        try {
            const result = (await api.post(`${root.value}/sales/orders`, uncertain.value, { timeout: 60000 })).data.data;
            let order = result.order; if (result.replay) { try { order = (await api.get(`${root.value}/sales/orders/${result.order.id}`)).data.data; } catch { /* The mutation response already proves this request committed. */ } }
            saved.value = order; documentState.value = result.document?.status || ''; uncertain.value = null; clearPending(journalKey);
            notice.value = result.replay ? 'Operación recuperada sin duplicados. Se muestra el estado actual del pedido.' : result.order.status === 'reserved' ? 'Apartado guardado. La reserva ya está activa en el servidor.' : 'Venta registrada y factura emitida. El envío se realiza manualmente.';
        } catch (e) { error.value = errorMessage(e); if (axios.isAxiosError(e) && e.response && [409, 422].includes(e.response.status)) { uncertain.value = null; clearPending(journalKey); await calculate(); } }
        finally { busy.value = false; }
    }
    function reset(target: 'sale' | 'reservation' = 'sale') {
        if (busy.value || uncertain.value) return;
        clearTimeout(timer); ++sequence; controller?.abort(); saved.value = null; lines.value = []; quote.value = null; customer.value = null; mode.value = target; discount.value = '0'; shipping.value = '0'; paid.value = '0'; delivery.value = ''; notes.value = ''; error.value = ''; notice.value = ''; documentState.value = '';
    }
    function restore(order: SavedOrder) { saved.value = order; mode.value = 'reservation'; error.value = ''; notice.value = ''; }
    onMounted(async () => {
        const recovery = readPending(journalKey); if (!recovery || typeof recovery.request_key !== 'string') return;
        uncertain.value = recovery; mode.value = recovery.mode === 'reservation' ? 'reservation' : 'sale'; busy.value = true;
        try { const result = (await api.get(`${root.value}/sales/requests/${recovery.request_key}`)).data.data; saved.value = result.order; documentState.value = result.document?.status || ''; uncertain.value = null; clearPending(journalKey); notice.value = 'Operación recuperada tras recargar. No se generaron duplicados.'; }
        catch (e) { if (!axios.isAxiosError(e) || e.response?.status !== 404) error.value = errorMessage(e); else notice.value = 'Hay una operación sin confirmación. Reintenta exactamente la misma petición.'; }
        finally { busy.value = false; }
    });
    onUnmounted(() => { ++sequence; controller?.abort(); clearTimeout(timer); });
    return { customer, lines, mode, discount, shipping, paid, method, delivery, notes, quote, quoting, busy, error, notice, saved, uncertain, documentState, add, changeQuantity, calculate, submit, reset, restore };
}
