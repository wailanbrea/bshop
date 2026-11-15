export interface ImportRow {
    row: number; product_key: string; name: string; sku: string; barcode: string; sale_price: string;
    cost_price?: string | null; product_type: string; errors: { field: string; message: string }[];
}
export interface CatalogImportBatch {
    id: string; filename: string; status: 'preview' | 'committed' | 'reverted'; row_count: number; product_count: number; error_count: number;
    expires_at: string; is_expired: boolean; can_confirm: boolean; can_revert: boolean; committed_at: string | null; reverted_at: string | null;
    products: { id: number; name: string }[]; rows: ImportRow[];
    pagination: { current_page: number; last_page: number; total: number; per_page: number };
}
export interface CatalogImportHistory {
    id: string; filename: string; status: CatalogImportBatch['status']; row_count: number; product_count: number; error_count: number; created_at: string; expires_at: string;
}
