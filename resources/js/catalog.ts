export interface CategoryOption { id: number; name: string; parent_id: number | null; is_active: boolean }
export interface BrandOption { id: number; name: string; is_active: boolean }
export interface AttributeOption { id: number; name: string; is_active: boolean; values: ValueOption[] }
export interface ValueOption { id: number; attribute_id: number; value: string; is_active: boolean }
export interface CatalogOptions { categories: CategoryOption[]; brands: BrandOption[]; attributes: AttributeOption[] }
export interface CatalogProduct {
    images?: ProductImage[];
    id: number; name: string; description: string | null; product_type: 'physical' | 'service'; unit_code: string;
    track_stock: boolean; status: 'active' | 'archived'; version: number;
    category: { id: number; name: string } | null; brand: { id: number; name: string } | null;
    primary_sku: string | null; variants_count: number; active_variants_count: number;
    min_sale_price: string | null; max_sale_price: string | null;
}
export interface ProductImage {
    id: number; variant_id: number | null; alt_text: string | null; is_primary: boolean; sort_order: number;
    renditions: Record<'thumb' | 'card' | 'detail', { url: string | null; width: number; height: number }>;
}
export interface CatalogVariant {
    id: number; product_id: number; sku: string; barcode: string | null; sale_price: string; cost_price?: string | null;
    is_active: boolean; version: number;
    attribute_values: { id: number; attribute_id: number; attribute_name: string; value: string }[];
}
export interface VariantPage { data: CatalogVariant[]; current_page: number; last_page: number; total: number }
export interface VariantDraft { key: number; sku: string; barcode: string; sale_price: string; cost_price: string; is_active: boolean; selections: Record<number, number | ''> }
export function emptyVariant(key: number): VariantDraft { return { key, sku: '', barcode: '', sale_price: '', cost_price: '', is_active: true, selections: {} }; }
export function decimalInput(value: string): string { return value.replace(',', '.'); }
/** Display decimal strings without passing money through JavaScript floating-point arithmetic. */
export function amount(value: string | null | undefined, currency: string): string {
    if (value == null) return '—';
    const [whole, decimal = ''] = value.split('.');
    const fraction = decimal.replace(/0+$/, '').padEnd(2, '0');
    return `${currency === 'USD' ? 'US$' : currency} ${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
}
export function variantPayload(form: VariantDraft, costs: boolean) {
    return { sku: form.sku.trim(), barcode: form.barcode.trim() || null, sale_price: form.sale_price.trim(),
        ...(costs ? { cost_price: form.cost_price.trim() || null } : {}), is_active: form.is_active,
        attribute_value_ids: Object.values(form.selections).filter((id): id is number => typeof id === 'number') };
}
