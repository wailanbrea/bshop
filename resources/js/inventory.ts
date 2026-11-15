export interface StockRow {
    variant_id: number; warehouse_id: number; sku: string; barcode: string | null; product_name: string; product_id: number; unit_code: string;
    active: boolean; attributes: { name: string; value: string }[]; on_hand: string; reserved: string; available: string; version: number;
}
export interface Movement { id: number; variant_id: number; sku: string; product_name: string; movement_type: string; on_hand_delta: string; reserved_delta: string; on_hand_after: string | null; reserved_after: string | null; note: string; occurred_at: string; actor_name: string | null }
export const movementNames: Record<string, string> = { customer_return: 'Mercancía devuelta', expire: 'Reserva vencida', receipt: 'Entrada', issue: 'Salida', count: 'Conteo físico', transfer_out: 'Transferencia enviada', transfer_in: 'Transferencia recibida' };
export function quantity(value: string | null) { return value == null ? '—' : value.includes('.') ? value.replace(/0+$/, '').replace(/\.$/, '') : value; }
