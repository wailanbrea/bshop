export interface StockRow {
    variant_id: number; warehouse_id: number; sku: string; barcode: string | null; product_name: string; product_id: number; unit_code: string;
    image_url?: string | null; active: boolean; attributes: { name: string; value: string }[]; on_hand: string; reserved: string; available: string;
    cost_price?: string | null; sale_price?: string | null; invested_total?: string | null; sale_total?: string | null; version: number;
}
export interface InventoryValuation {
    currency: string; invested_cost: string | null; sale_value: string; estimated_profit: string | null; units_on_hand: string; units_available: string;
}
export interface Movement { id: number; variant_id: number; sku: string; product_name: string; movement_type: string; on_hand_delta: string; reserved_delta: string; on_hand_after: string | null; reserved_after: string | null; note: string; occurred_at: string; actor_name: string | null }
export const movementNames: Record<string, string> = { customer_return: 'Mercancía devuelta', expire: 'Reserva vencida', receipt: 'Entrada', issue: 'Salida', count: 'Conteo físico', transfer_out: 'Transferencia enviada', transfer_in: 'Transferencia recibida' };
export function quantity(value: string | null) { return value == null ? '—' : value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value; }
