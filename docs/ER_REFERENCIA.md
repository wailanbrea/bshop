# ER de referencia — comprobado en MySQL 8.4.13

Origen: SQL histórico v3; esquema aislado `bshop_testing_reference` en 127.0.0.1:33078.
Generado con `scripts/document-reference-schema.php`; no representa todos los módulos ya implementados.
51 entidades; 100 FK comparadas por tabla, columnas y destino; 136 índices explícitos comparados.
La cardinalidad representa nulabilidad de FK; las reglas de negocio adicionales siguen en servicios.

```mermaid
erDiagram
    attribute_values {
        bigint id PK
        bigint tenant_id
        bigint attribute_id
        varchar value
        datetime created_at
        datetime updated_at
    }
    audit_logs {
        bigint id PK
        bigint tenant_id
        bigint membership_id
        varchar event
        varchar entity_type
        bigint entity_id
        json old_data
        json new_data
        varchar ip_address
        datetime occurred_at
    }
    brands {
        bigint id PK
        bigint tenant_id
        varchar name
        datetime created_at
        datetime updated_at
    }
    categories {
        bigint id PK
        bigint tenant_id
        bigint parent_id
        varchar name
        varchar slug
        tinyint is_active
        datetime created_at
        datetime updated_at
    }
    commission_entries {
        bigint id PK
        bigint tenant_id
        bigint employee_id
        bigint compensation_plan_id
        bigint invoice_item_id
        bigint payment_allocation_id
        bigint refund_id
        varchar entry_type
        decimal commission_base
        decimal commission_rate
        decimal amount
        char currency
        varchar event_key
        datetime earned_at
        datetime created_at
    }
    customer_credit_movements {
        bigint id PK
        bigint tenant_id
        bigint payment_id
        bigint customer_id
        char currency
        bigint payment_allocation_id
        bigint refund_id
        varchar event_type
        decimal amount_delta
        varchar event_key
        datetime occurred_at
        datetime created_at
    }
    customers {
        bigint id PK
        bigint tenant_id
        varchar full_name
        varchar email
        varchar phone
        varchar address
        text notes
        varchar status
        datetime created_at
        datetime updated_at
        datetime deleted_at
    }
    document_sequences {
        bigint tenant_id PK
        bigint store_id PK
        varchar document_type PK
        varchar prefix
        bigint next_number
        datetime updated_at
    }
    employee_compensation_plans {
        bigint id PK
        bigint tenant_id
        bigint employee_id
        varchar pay_type
        char currency
        decimal fixed_salary
        varchar salary_period
        decimal commission_rate
        varchar commission_base
        varchar commission_trigger
        date effective_from
        date effective_to
        datetime created_at
        datetime updated_at
    }
    employee_store_assignments {
        bigint tenant_id PK
        bigint employee_id PK
        bigint store_id PK
        datetime created_at
    }
    employees {
        bigint id PK
        bigint tenant_id
        bigint membership_id
        bigint home_store_id
        varchar employee_code
        varchar full_name
        varchar phone
        varchar email
        tinyint can_sell
        varchar status
        date hired_on
        date ended_on
        datetime created_at
        datetime updated_at
    }
    idempotency_requests {
        bigint id PK
        bigint tenant_id
        varchar operation
        varchar request_key
        char request_hash
        varchar status
        varchar resource_type
        bigint resource_id
        datetime created_at
        datetime completed_at
    }
    inventory_movements {
        bigint id PK
        bigint tenant_id
        bigint warehouse_id
        bigint variant_id
        bigint reservation_id
        bigint return_item_id
        varchar movement_type
        decimal on_hand_delta
        decimal reserved_delta
        varchar event_key
        varchar note
        bigint performed_by_membership_id
        datetime occurred_at
        datetime created_at
    }
    invoice_items {
        bigint id PK
        bigint tenant_id
        bigint invoice_id
        bigint order_item_id
        bigint seller_employee_id
        varchar description
        varchar sku_snapshot
        decimal quantity
        decimal unit_price
        decimal discount_amount
        decimal tax_amount
        decimal line_total
        bigint commission_plan_id
        decimal commission_rate_snapshot
        datetime created_at
    }
    invoices {
        bigint id PK
        bigint tenant_id
        bigint store_id
        bigint customer_id
        bigint weekly_account_id
        bigint order_id
        varchar invoice_number
        varchar status
        char currency
        decimal subtotal
        decimal discount_total
        decimal tax_total
        decimal shipping_total
        decimal grand_total
        datetime issued_at
        datetime due_at
        datetime created_at
        datetime updated_at
    }
    media_assets {
        bigint id PK
        bigint tenant_id
        varchar provider
        varchar bucket_name
        varchar staging_provider
        varchar staging_bucket_name
        varchar staging_key
        char staging_key_hash
        varchar object_key
        char object_key_hash
        varchar original_filename
        varchar requested_mime
        varchar mime_type
        bigint byte_size
        int width_px
        int height_px
        char sha256
        varchar visibility
        varchar status
        varchar error_code
        bigint uploaded_by_membership_id
        datetime ready_at
        datetime created_at
        datetime updated_at
        datetime deleted_at
    }
    media_renditions {
        bigint id PK
        bigint tenant_id
        bigint media_asset_id
        varchar rendition
        varchar provider
        varchar namespace_name
        varchar object_key
        char object_key_hash
        varchar mime_type
        int width_px
        int height_px
        bigint byte_size
        char sha256
        datetime created_at
    }
    media_upload_sessions {
        bigint id PK
        bigint tenant_id
        bigint media_asset_id
        varchar request_key
        varchar declared_mime
        bigint declared_size
        datetime expires_at
        varchar status
        datetime completed_at
        bigint created_by_membership_id
        datetime created_at
        datetime updated_at
    }
    membership_store_access {
        bigint tenant_id PK
        bigint membership_id PK
        bigint store_id PK
        datetime created_at
    }
    order_items {
        bigint id PK
        bigint tenant_id
        bigint order_id
        bigint store_id
        bigint variant_id
        bigint seller_employee_id
        decimal quantity
        decimal unit_price
        decimal line_discount
        decimal line_tax
        decimal line_total
        varchar product_name_snapshot
        varchar sku_snapshot
        json variant_snapshot
        bigint commission_plan_id
        decimal commission_rate_snapshot
        datetime created_at
        datetime updated_at
    }
    orders {
        bigint id PK
        bigint tenant_id
        bigint store_id
        bigint customer_id
        bigint weekly_account_id
        bigint share_link_id
        bigint seller_employee_id
        varchar channel
        varchar status
        char currency
        decimal subtotal
        decimal discount_total
        decimal tax_total
        decimal shipping_total
        decimal grand_total
        text notes
        datetime confirmed_at
        datetime fulfilled_at
        datetime cancelled_at
        datetime created_at
        datetime updated_at
    }
    outbox_events {
        bigint id PK
        bigint tenant_id
        varchar event_key
        varchar event_type
        varchar aggregate_type
        bigint aggregate_id
        json payload
        varchar status
        smallint attempts
        datetime available_at
        datetime processed_at
        datetime created_at
    }
    payment_allocations {
        bigint id PK
        bigint tenant_id
        bigint payment_id
        bigint invoice_id
        bigint store_id
        bigint customer_id
        char currency
        varchar event_key
        decimal amount
        datetime created_at
    }
    payments {
        bigint id PK
        bigint tenant_id
        bigint store_id
        bigint customer_id
        bigint weekly_account_id
        varchar payment_method
        varchar external_reference
        char currency
        decimal amount
        varchar status
        datetime paid_at
        text notes
        datetime created_at
        datetime updated_at
    }
    payroll_commission_entries {
        bigint tenant_id PK
        bigint payroll_line_id
        bigint commission_entry_id PK
        bigint employee_id
        char currency
        datetime created_at
    }
    payroll_disbursements {
        bigint id PK
        bigint tenant_id
        bigint payroll_line_id
        bigint employee_id
        decimal amount
        char currency
        varchar payment_method
        varchar reference
        varchar event_key
        datetime paid_at
        datetime created_at
    }
    payroll_lines {
        bigint id PK
        bigint tenant_id
        bigint payroll_period_id
        bigint employee_id
        varchar compensation_type_snapshot
        char currency
        decimal salary_amount
        decimal commission_amount
        decimal adjustments
        decimal deductions
        decimal total_payable
        datetime created_at
        datetime updated_at
    }
    payroll_periods {
        bigint id PK
        bigint tenant_id
        bigint store_id
        varchar scope_key
        char currency
        date starts_on
        date ends_on
        varchar status
        datetime approved_at
        datetime created_at
        datetime updated_at
    }
    product_attributes {
        bigint id PK
        bigint tenant_id
        varchar name
        datetime created_at
        datetime updated_at
    }
    product_images {
        bigint id PK
        bigint tenant_id
        bigint product_id
        bigint variant_id
        bigint media_asset_id
        varchar alt_text
        tinyint is_primary
        int sort_order
        datetime created_at
        datetime updated_at
    }
    product_variants {
        bigint id PK
        bigint tenant_id
        bigint product_id
        varchar sku
        varchar barcode
        varchar combination_key
        decimal sale_price
        decimal cost_price
        tinyint is_active
        datetime created_at
        datetime updated_at
        datetime deleted_at
    }
    products {
        bigint id PK
        bigint tenant_id
        bigint category_id
        bigint brand_id
        varchar name
        text description
        varchar product_type
        varchar unit_code
        tinyint track_stock
        varchar status
        datetime created_at
        datetime updated_at
        datetime deleted_at
    }
    refunds {
        bigint id PK
        bigint tenant_id
        bigint customer_id
        bigint sales_return_id
        bigint original_payment_id
        varchar refund_kind
        varchar status
        decimal amount
        char currency
        varchar method
        varchar event_key
        varchar external_reference
        datetime refunded_at
        datetime created_at
        datetime updated_at
    }
    saas_plans {
        bigint id PK
        varchar code
        varchar name
        varchar billing_cycle
        decimal price
        char currency
        smallint max_stores
        smallint max_users
        json features
        tinyint is_active
        datetime created_at
        datetime updated_at
    }
    sales_return_items {
        bigint id PK
        bigint tenant_id
        bigint sales_return_id
        bigint invoice_id
        bigint invoice_item_id
        decimal quantity
        decimal refund_amount
        tinyint restock
        datetime created_at
    }
    sales_returns {
        bigint id PK
        bigint tenant_id
        bigint invoice_id
        bigint customer_id
        varchar status
        varchar reason
        datetime created_at
        datetime updated_at
    }
    share_links {
        bigint id PK
        bigint tenant_id
        bigint store_id
        bigint customer_id
        char token_hash
        varchar status
        datetime expires_at
        datetime used_at
        bigint created_by_membership_id
        datetime created_at
        datetime updated_at
    }
    stock_balances {
        bigint tenant_id PK
        bigint warehouse_id PK
        bigint variant_id PK
        decimal on_hand
        decimal reserved
        bigint version
        datetime updated_at
    }
    stock_reservations {
        bigint id PK
        bigint tenant_id
        bigint order_item_id
        bigint store_id
        bigint warehouse_id
        bigint variant_id
        decimal quantity
        decimal fulfilled_quantity
        decimal released_quantity
        varchar status
        datetime expires_at
        datetime created_at
        datetime updated_at
    }
    stores {
        bigint id PK
        bigint tenant_id
        varchar name
        varchar code
        varchar address
        varchar phone
        tinyint is_active
        datetime created_at
        datetime updated_at
    }
    tenant_membership_roles {
        bigint tenant_id PK
        bigint membership_id PK
        bigint role_id PK
        datetime created_at
    }
    tenant_memberships {
        bigint id PK
        bigint tenant_id
        bigint user_id
        varchar status
        datetime created_at
        datetime updated_at
    }
    tenant_roles {
        bigint id PK
        bigint tenant_id
        varchar code
        varchar label
        json permissions
        datetime created_at
        datetime updated_at
    }
    tenant_settings {
        bigint tenant_id PK
        tinyint weekly_close_weekday
        decimal sales_tax_rate
        int reserve_expiry_hours
        varchar invoice_prefix
        json configuration
        datetime updated_at
    }
    tenant_storage_quotas {
        bigint tenant_id PK
        bigint max_bytes
        bigint bytes_ready
        bigint bytes_pending
        bigint max_single_upload_bytes
        tinyint max_images_per_product
        datetime updated_at
    }
    tenant_subscriptions {
        bigint id PK
        bigint tenant_id
        bigint saas_plan_id
        varchar plan_name_snapshot
        decimal amount_snapshot
        char currency_snapshot
        varchar billing_cycle_snapshot
        varchar status
        datetime started_at
        datetime renews_at
        datetime ends_at
        varchar external_reference
        datetime created_at
        datetime updated_at
    }
    tenants {
        bigint id PK
        varchar name
        varchar slug
        varchar legal_name
        char default_currency
        tinyint currency_decimals
        varchar timezone
        varchar locale
        varchar status
        datetime created_at
        datetime updated_at
    }
    users {
        bigint id PK
        varchar name
        varchar email
        varchar password
        varchar phone
        tinyint is_active
        datetime email_verified_at
        datetime created_at
        datetime updated_at
    }
    variant_attribute_values {
        bigint tenant_id PK
        bigint variant_id PK
        bigint attribute_id PK
        bigint value_id
    }
    warehouses {
        bigint id PK
        bigint tenant_id
        bigint store_id
        varchar name
        tinyint is_default
        tinyint is_active
        datetime created_at
        datetime updated_at
    }
    weekly_accounts {
        bigint id PK
        bigint tenant_id
        bigint store_id
        bigint customer_id
        date period_start
        date period_end
        varchar status
        datetime closed_at
        datetime created_at
        datetime updated_at
    }
    product_attributes ||--o{ attribute_values : fk_av_attr
    tenant_memberships |o--o{ audit_logs : fk_audit_member
    tenants ||--o{ audit_logs : fk_audit_tenant
    tenants ||--o{ brands : fk_brand_tenant
    categories |o--o{ categories : fk_category_parent
    tenants ||--o{ categories : fk_category_tenant
    employees ||--o{ commission_entries : fk_ce_employee
    invoice_items ||--o{ commission_entries : fk_ce_item
    payment_allocations |o--o{ commission_entries : fk_ce_pa
    employee_compensation_plans ||--o{ commission_entries : fk_ce_plan
    refunds |o--o{ commission_entries : fk_ce_refund
    payment_allocations |o--o{ customer_credit_movements : fk_ccm_allocation
    payments ||--o{ customer_credit_movements : fk_ccm_payment
    refunds |o--o{ customer_credit_movements : fk_ccm_refund
    tenants ||--o{ customers : fk_customer_tenant
    stores ||--o{ document_sequences : fk_sequence_store
    employees ||--o{ employee_compensation_plans : fk_plan_emp
    employees ||--o{ employee_store_assignments : fk_esa_emp
    stores ||--o{ employee_store_assignments : fk_esa_store
    tenant_memberships |o--o{ employees : fk_emp_member
    stores |o--o{ employees : fk_emp_store
    tenants ||--o{ idempotency_requests : fk_ir_tenant
    tenant_memberships |o--o{ inventory_movements : fk_im_actor
    stock_balances ||--o{ inventory_movements : fk_im_balance
    stock_reservations |o--o{ inventory_movements : fk_im_res
    sales_return_items |o--o{ inventory_movements : fk_im_return
    invoices ||--o{ invoice_items : fk_ii_invoice
    order_items |o--o{ invoice_items : fk_ii_order_item
    employee_compensation_plans |o--o{ invoice_items : fk_ii_plan
    employees |o--o{ invoice_items : fk_ii_seller
    customers ||--o{ invoices : fk_invoice_customer
    orders |o--o{ invoices : fk_invoice_order
    stores ||--o{ invoices : fk_invoice_store
    weekly_accounts |o--o{ invoices : fk_invoice_wa
    tenants ||--o{ media_assets : fk_media_tenant
    tenant_memberships |o--o{ media_assets : fk_media_uploader
    media_assets ||--o{ media_renditions : fk_rend_media
    tenant_memberships ||--o{ media_upload_sessions : fk_mus_creator
    media_assets ||--o{ media_upload_sessions : fk_mus_media
    tenant_memberships ||--o{ membership_store_access : fk_msa_membership
    stores ||--o{ membership_store_access : fk_msa_store
    orders ||--o{ order_items : fk_oi_order
    employee_compensation_plans |o--o{ order_items : fk_oi_plan
    employees |o--o{ order_items : fk_oi_seller
    product_variants ||--o{ order_items : fk_oi_var
    customers ||--o{ orders : fk_order_customer
    share_links |o--o{ orders : fk_order_link
    employees |o--o{ orders : fk_order_seller
    stores ||--o{ orders : fk_order_store
    weekly_accounts |o--o{ orders : fk_order_weekly
    tenants ||--o{ outbox_events : fk_outbox_tenant
    invoices ||--o{ payment_allocations : fk_pa_invoice
    payments ||--o{ payment_allocations : fk_pa_payment
    customers ||--o{ payments : fk_payment_customer
    stores ||--o{ payments : fk_payment_store
    weekly_accounts |o--o{ payments : fk_payment_wa
    commission_entries ||--o{ payroll_commission_entries : fk_pce_entry
    payroll_lines ||--o{ payroll_commission_entries : fk_pce_line
    payroll_lines ||--o{ payroll_disbursements : fk_pd_line
    employees ||--o{ payroll_lines : fk_pl_employee
    payroll_periods ||--o{ payroll_lines : fk_pl_period
    stores |o--o{ payroll_periods : fk_pp_store
    tenants ||--o{ payroll_periods : fk_pp_tenant
    tenants ||--o{ product_attributes : fk_attr_tenant
    media_assets ||--o{ product_images : fk_img_asset
    products ||--o{ product_images : fk_img_prod
    product_variants |o--o{ product_images : fk_img_var_same_product
    products ||--o{ product_variants : fk_var_product
    brands |o--o{ products : fk_prod_brand
    categories |o--o{ products : fk_prod_category
    tenants ||--o{ products : fk_prod_tenant
    payments ||--o{ refunds : fk_ref_payment
    sales_returns |o--o{ refunds : fk_ref_return
    invoice_items ||--o{ sales_return_items : fk_sri_invoice_item
    sales_returns ||--o{ sales_return_items : fk_sri_return
    customers ||--o{ sales_returns : fk_sr_customer
    invoices ||--o{ sales_returns : fk_sr_invoice
    tenant_memberships |o--o{ share_links : fk_link_creator
    customers |o--o{ share_links : fk_link_customer
    stores ||--o{ share_links : fk_link_store
    product_variants ||--o{ stock_balances : fk_stock_var
    warehouses ||--o{ stock_balances : fk_stock_wh
    stock_balances ||--o{ stock_reservations : fk_res_balance
    order_items ||--o{ stock_reservations : fk_res_order_item
    warehouses ||--o{ stock_reservations : fk_res_warehouse_store
    tenants ||--o{ stores : fk_stores_tenant
    tenant_memberships ||--o{ tenant_membership_roles : fk_mr_membership
    tenant_roles ||--o{ tenant_membership_roles : fk_mr_role
    tenants ||--o{ tenant_memberships : fk_member_tenant
    users ||--o{ tenant_memberships : fk_member_user
    tenants ||--o{ tenant_roles : fk_roles_tenant
    tenants ||--o{ tenant_settings : fk_settings_tenant
    tenants ||--o{ tenant_storage_quotas : fk_storage_tenant
    saas_plans ||--o{ tenant_subscriptions : fk_sub_plan
    tenants ||--o{ tenant_subscriptions : fk_sub_tenant
    attribute_values ||--o{ variant_attribute_values : fk_vav_value
    product_variants ||--o{ variant_attribute_values : fk_vav_var
    stores ||--o{ warehouses : fk_wh_store
    customers ||--o{ weekly_accounts : fk_wa_customer
    stores ||--o{ weekly_accounts : fk_wa_store
```

## Verificación

- 100/100 FK coinciden con la referencia SQL, incluidas todas las columnas compuestas.
- 136 índices explícitos coinciden en nombre, columnas/orden y unicidad; INFORMATION_SCHEMA contiene 246 índices en total, incluidos primarios/implícitos.
- CHECK negativos ejecutados: decimales de moneda, reservas sobre físico y stock negativo.
