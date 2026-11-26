import type { ProductImage } from './catalog';
export interface SalesCustomer { status?: 'active' | 'archived'; lock_version?: number; id: number; full_name: string; phone: string | null; email: string | null; address: string | null; notes: string | null }
export interface CustomerSummary { customer?:SalesCustomer; currency?:string; pending_total:string; purchase_count:number; last_purchase_at:string|null; open_baskets:{id:number;total:string;status:string}[]; pending_returns?:number;credit_available?:string;refunded_total?:string }
export interface SalesVariant { id: number; sku: string; price: string; available: string | null; description?: string | null; image_url?: string | null; thumb_url?: string | null; attributes: { name: string; value: string }[] }
export interface SalesProduct { id: number; name: string; category_id: number | null; track_stock: boolean; unit_code: string; image: ProductImage | null; variants: SalesVariant[] }
export interface CartLine { variant_id: number; product_id: number; name: string; sku: string; quantity: string; price: string; available: string | null; attributes: { name: string; value: string }[]; image: string | null }
export interface SalesQuote { currency: string; subtotal: string; discount_total: string; tax_total: string; shipping_total: string; grand_total: string; quote_hash: string }
export interface SavedOrder {
    reservation_expires_at?: string | null; reservation_expired?: boolean;
    return_pending?: boolean;
    original_total?:string;net_total?:string;return_credit_total?:string;gross_received?:string;refunded_total?:string;credit_available?:string;
    credit_received_total?:string;credit_applied_total?:string;
    order_kind?: 'sale' | 'reservation'; basket_number?: string; created_at?: string | null; closed_at?: string | null; shipped_at?: string | null;
    seller_employee_id?: number | null; seller_name?: string | null;
    customer_live?: SalesCustomer & { lock_version: number };
    whatsapp_delivery?: { id: string; status: string; error_code: string | null } | null;
    id: number; store_id: number; warehouse_id: number; customer_id: number; customer_snapshot: SalesCustomer; status: 'reserved' | 'confirmed' | 'fulfilled' | 'cancelled'; currency: string; lock_version: number;
    subtotal: string; discount_total: string; tax_total: string; shipping_total: string; grand_total: string; paid_total: string; pending_total: string; estimated_delivery: string | null;
    items: { id: number; variant_id: number; product_name_snapshot: string; sku_snapshot: string; quantity: string; unit_price: string; line_total: string; image_url_snapshot: string | null; variant_snapshot: { name: string; value: string }[] }[];
    invoice: { id: number; invoice_number: string; status: string; currency: string } | null;
    reservations: { id: number; order_item_id: number; quantity: string; fulfilled_quantity: string; released_quantity: string; status: string }[];
}
export function units(value: string): bigint | null {
    if (!/^(?:0|[1-9]\d{0,14})(?:\.\d{1,4})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split('.'); return BigInt(whole) * 10000n + BigInt(fraction.padEnd(4, '0'));
}
export function decimal(value: bigint): string { const sign = value < 0 ? '-' : ''; const n = value < 0 ? -value : value; return `${sign}${n / 10000n}.${String(n % 10000n).padStart(4, '0')}`; }
export function pendingAmount(total: string | undefined, paid: string): string | null { const a = units(total || '0'), b = units(paid || '0'); return a === null || b === null || b > a ? null : decimal(a - b); }
