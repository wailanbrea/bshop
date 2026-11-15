# ER actual — un negocio y sus sucursales

Arquitectura vigente: una instalación para KeylaShop.
63 tablas incluyendo infraestructura; 106 FK y 62 CHECK verificados en MySQL 8.4.13.
No hay gestión multiempresa ni columnas tenant_id. Las tablas no implican módulos terminados.

```mermaid
erDiagram
    attribute_values {
        bigint id PK
        bigint attribute_id
        varchar value
        datetime created_at
        datetime updated_at
        tinyint is_active
    }
    audit_logs {
        bigint id PK
        bigint user_id
        varchar event
        varchar entity_type
        varchar entity_id
        json old_data
        json new_data
        varchar ip_address
        datetime occurred_at
    }
    brands {
        bigint id PK
        varchar name
        datetime created_at
        datetime updated_at
        tinyint is_active
    }
    business_profile {
        tinyint id PK
        varchar name
        varchar legal_name
        char default_currency
        tinyint currency_decimals
        varchar timezone
        varchar locale
        timestamp created_at
        timestamp updated_at
    }
    business_settings {
        tinyint id PK
        tinyint weekly_close_weekday
        decimal sales_tax_rate
        int reserve_expiry_hours
        varchar invoice_prefix
        json configuration
        datetime updated_at
    }
    cache {
        varchar key PK
        mediumtext value
        int expiration
    }
    cache_locks {
        varchar key PK
        varchar owner
        int expiration
    }
    catalog_imports {
        char id PK
        bigint created_by_user_id
        varchar filename
        char source_sha256
        varchar status
        int row_count
        int product_count
        int error_count
        tinyint requires_costs
        longtext payload
        longtext result
        datetime expires_at
        datetime committed_at
        datetime reverted_at
        timestamp created_at
        timestamp updated_at
    }
    categories {
        bigint id PK
        bigint parent_id
        varchar name
        varchar slug
        tinyint is_active
        datetime created_at
        datetime updated_at
    }
    commission_entries {
        bigint id PK
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
        bigint invoice_id
        bigint store_id
        bigint customer_id
    }
    customer_credit_movements {
        bigint id PK
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
        varchar full_name
        varchar email
        varchar phone
        varchar address
        text notes
        varchar status
        datetime created_at
        datetime updated_at
        datetime deleted_at
        bigint lock_version
    }
    document_sequences {
        bigint store_id PK
        varchar document_type PK
        varchar prefix
        bigint next_number
        datetime updated_at
    }
    employee_compensation_plans {
        bigint id PK
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
        bigint employee_id PK
        bigint store_id PK
        datetime created_at
    }
    employees {
        bigint id PK
        bigint user_id
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
    failed_jobs {
        bigint id PK
        varchar uuid
        text connection
        text queue
        longtext payload
        longtext exception
        timestamp failed_at
    }
    idempotency_requests {
        bigint id PK
        varchar operation
        varchar request_key
        char request_hash
        varchar status
        varchar resource_type
        bigint resource_id
        datetime created_at
        datetime completed_at
        json response_data
    }
    inventory_movements {
        bigint id PK
        bigint warehouse_id
        bigint variant_id
        bigint reservation_id
        bigint return_item_id
        varchar movement_type
        decimal on_hand_delta
        decimal reserved_delta
        varchar event_key
        varchar note
        bigint performed_by_user_id
        datetime occurred_at
        datetime created_at
        bigint store_id
        decimal on_hand_after
        decimal reserved_after
        bigint balance_version
    }
    invitation_store_access {
        bigint invitation_id PK
        bigint store_id PK
    }
    invoice_deliveries {
        char id PK
        bigint invoice_id
        bigint store_id
        bigint created_by_user_id
        char request_key
        varchar destination
        varchar sender_phone_id
        varchar status
        varchar provider_message_id
        varchar error_code
        int attempts
        datetime accepted_at
        datetime sent_at
        datetime delivered_at
        datetime read_at
        datetime event_at
        timestamp created_at
        timestamp updated_at
    }
    invoice_documents {
        bigint id PK
        bigint invoice_id
        int version
        varchar provider
        varchar bucket_name
        varchar object_key
        char object_key_hash
        char sha256
        bigint byte_size
        varchar status
        datetime created_at
    }
    invoice_items {
        bigint id PK
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
        bigint store_id
        bigint customer_id
        char currency
        bigint order_id
        bigint variant_id
    }
    invoice_orders {
        bigint invoice_id
        bigint order_id PK
        bigint store_id
        bigint customer_id
        char currency
        bigint direct_order_id
        bigint weekly_account_id
    }
    invoices {
        bigint id PK
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
        json business_snapshot
    }
    job_batches {
        varchar id PK
        varchar name
        int total_jobs
        int pending_jobs
        int failed_jobs
        longtext failed_job_ids
        mediumtext options
        int cancelled_at
        int created_at
        int finished_at
    }
    jobs {
        bigint id PK
        varchar queue
        longtext payload
        tinyint attempts
        int reserved_at
        int available_at
        int created_at
    }
    media_assets {
        bigint id PK
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
        bigint uploaded_by_user_id
        datetime ready_at
        datetime created_at
        datetime updated_at
        datetime deleted_at
        char storage_token
        tinyint cleanup_pending
        datetime cleanup_after
        datetime purged_at
        datetime private_cleaned_at
    }
    media_renditions {
        bigint id PK
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
        bigint media_asset_id
        varchar request_key
        varchar declared_mime
        bigint declared_size
        datetime expires_at
        varchar status
        datetime completed_at
        bigint created_by_user_id
        datetime created_at
        datetime updated_at
        bigint product_id
        bigint variant_id
        bigint reserved_bytes
        char source_sha256
        int attempts
    }
    migrations {
        int id PK
        varchar migration
        int batch
    }
    order_items {
        bigint id PK
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
        datetime removed_at
        varchar image_url_snapshot
    }
    order_payments {
        bigint payment_id PK
        bigint order_id
        bigint store_id
        bigint customer_id
        char currency
        datetime created_at
    }
    orders {
        bigint id PK
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
        bigint lock_version
        json customer_snapshot
        date estimated_delivery
        bigint warehouse_id
        decimal tax_rate_snapshot
        varchar order_kind
        varchar basket_number
        datetime closed_at
        datetime shipped_at
    }
    outbox_events {
        bigint id PK
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
    password_reset_tokens {
        varchar email PK
        varchar token
        timestamp created_at
    }
    payment_allocations {
        bigint id PK
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
        bigint payroll_line_id
        bigint commission_entry_id PK
        bigint employee_id
        char currency
        datetime created_at
    }
    payroll_disbursements {
        bigint id PK
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
        varchar name
        datetime created_at
        datetime updated_at
        tinyint is_active
    }
    product_images {
        bigint id PK
        bigint product_id
        bigint variant_id
        bigint media_asset_id
        varchar alt_text
        tinyint is_primary
        int sort_order
        datetime created_at
        datetime updated_at
        bigint primary_product_id
        datetime deleted_at
    }
    product_variants {
        bigint id PK
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
        bigint lock_version
    }
    products {
        bigint id PK
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
        bigint lock_version
    }
    refunds {
        bigint id PK
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
        bigint store_id
        bigint invoice_id
    }
    role_user {
        bigint user_id PK
        bigint role_id PK
    }
    roles {
        bigint id PK
        varchar code
        varchar label
        json permissions
        timestamp created_at
        timestamp updated_at
    }
    sales_return_items {
        bigint id PK
        bigint sales_return_id
        bigint invoice_id
        bigint invoice_item_id
        decimal quantity
        decimal refund_amount
        tinyint restock
        datetime created_at
        bigint store_id
        bigint variant_id
    }
    sales_returns {
        bigint id PK
        bigint invoice_id
        bigint customer_id
        varchar status
        varchar reason
        datetime created_at
        datetime updated_at
        bigint store_id
        char currency
    }
    sessions {
        varchar id PK
        bigint user_id
        varchar ip_address
        text user_agent
        longtext payload
        int last_activity
    }
    share_links {
        bigint id PK
        bigint store_id
        bigint customer_id
        char token_hash
        varchar status
        datetime expires_at
        datetime used_at
        bigint created_by_user_id
        datetime created_at
        datetime updated_at
    }
    stock_balances {
        bigint warehouse_id PK
        bigint variant_id PK
        decimal on_hand
        decimal reserved
        bigint version
        datetime updated_at
    }
    stock_reservations {
        bigint id PK
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
    storage_quota {
        tinyint id PK
        bigint max_bytes
        bigint bytes_ready
        bigint bytes_pending
        bigint max_single_upload_bytes
        tinyint max_images_per_product
        datetime updated_at
        bigint bytes_retained
    }
    store_user {
        bigint user_id PK
        bigint store_id PK
    }
    stores {
        bigint id PK
        varchar name
        varchar code
        varchar address
        varchar phone
        tinyint is_active
        timestamp created_at
        timestamp updated_at
    }
    team_invitations {
        bigint id PK
        bigint role_id
        bigint invited_by_user_id
        varchar email
        char token_hash
        varchar status
        datetime expires_at
        datetime accepted_at
        bigint accepted_by_user_id
        timestamp created_at
        timestamp updated_at
    }
    users {
        bigint id PK
        varchar name
        varchar email
        varchar password
        varchar phone
        tinyint is_active
        datetime email_verified_at
        varchar remember_token
        timestamp created_at
        timestamp updated_at
    }
    variant_attribute_values {
        bigint variant_id PK
        bigint attribute_id PK
        bigint value_id
    }
    warehouses {
        bigint id PK
        bigint store_id
        varchar name
        tinyint is_default
        tinyint is_active
        timestamp created_at
        timestamp updated_at
        bigint default_store_id
    }
    weekly_accounts {
        bigint id PK
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
    users ||--o{ audit_logs : audit_logs_user_id_foreign
    users ||--o{ catalog_imports : catalog_imports_created_by_user_id_foreign
    categories ||--o{ categories : fk_category_parent
    employees ||--o{ commission_entries : fk_ce_employee
    invoice_items ||--o{ commission_entries : fk_ce_item
    invoice_items ||--o{ commission_entries : fk_ce_item_snapshot
    payment_allocations ||--o{ commission_entries : fk_ce_pa
    employee_compensation_plans ||--o{ commission_entries : fk_ce_plan
    refunds ||--o{ commission_entries : fk_ce_refund
    payment_allocations ||--o{ customer_credit_movements : fk_ccm_allocation
    payments ||--o{ customer_credit_movements : fk_ccm_payment
    refunds ||--o{ customer_credit_movements : fk_ccm_refund
    stores ||--o{ document_sequences : fk_sequence_store
    employees ||--o{ employee_compensation_plans : fk_plan_emp
    employees ||--o{ employee_store_assignments : fk_esa_emp
    stores ||--o{ employee_store_assignments : fk_esa_store
    users ||--o{ employees : fk_emp_member
    stores ||--o{ employees : fk_emp_store
    users ||--o{ inventory_movements : fk_im_actor
    stock_balances ||--o{ inventory_movements : fk_im_balance
    stock_reservations ||--o{ inventory_movements : fk_im_res
    sales_return_items ||--o{ inventory_movements : fk_im_return
    warehouses ||--o{ inventory_movements : fk_movement_store_warehouse
    team_invitations ||--o{ invitation_store_access : invitation_store_access_invitation_id_foreign
    stores ||--o{ invitation_store_access : invitation_store_access_store_id_foreign
    users ||--o{ invoice_deliveries : invoice_deliveries_created_by_user_id_foreign
    invoices ||--o{ invoice_deliveries : invoice_deliveries_invoice_id_foreign
    stores ||--o{ invoice_deliveries : invoice_deliveries_store_id_foreign
    invoices ||--o{ invoice_documents : fk_document_invoice
    invoices ||--o{ invoice_items : fk_ii_invoice
    order_items ||--o{ invoice_items : fk_ii_order_item
    invoice_orders ||--o{ invoice_items : fk_ii_order_source
    employee_compensation_plans ||--o{ invoice_items : fk_ii_plan
    employees ||--o{ invoice_items : fk_ii_seller
    invoices ||--o{ invoice_orders : fk_invoice_order_direct
    invoices ||--o{ invoice_orders : fk_invoice_order_invoice
    orders ||--o{ invoice_orders : fk_invoice_order_order
    invoices ||--o{ invoice_orders : fk_invoice_order_weekly_invoice
    orders ||--o{ invoice_orders : fk_invoice_order_weekly_order
    customers ||--o{ invoices : fk_invoice_customer
    orders ||--o{ invoices : fk_invoice_order
    stores ||--o{ invoices : fk_invoice_store
    weekly_accounts ||--o{ invoices : fk_invoice_wa
    users ||--o{ media_assets : fk_media_uploader
    media_assets ||--o{ media_renditions : fk_rend_media
    users ||--o{ media_upload_sessions : fk_mus_creator
    media_assets ||--o{ media_upload_sessions : fk_mus_media
    products ||--o{ media_upload_sessions : fk_upload_product
    product_variants ||--o{ media_upload_sessions : fk_upload_product_variant
    orders ||--o{ order_items : fk_oi_order
    employee_compensation_plans ||--o{ order_items : fk_oi_plan
    employees ||--o{ order_items : fk_oi_seller
    product_variants ||--o{ order_items : fk_oi_var
    orders ||--o{ order_payments : fk_op_order
    payments ||--o{ order_payments : fk_op_payment
    customers ||--o{ orders : fk_order_customer
    share_links ||--o{ orders : fk_order_link
    employees ||--o{ orders : fk_order_seller
    stores ||--o{ orders : fk_order_store
    warehouses ||--o{ orders : fk_order_warehouse_context
    weekly_accounts ||--o{ orders : fk_order_weekly
    invoices ||--o{ payment_allocations : fk_pa_invoice
    payments ||--o{ payment_allocations : fk_pa_payment
    customers ||--o{ payments : fk_payment_customer
    stores ||--o{ payments : fk_payment_store
    weekly_accounts ||--o{ payments : fk_payment_wa
    commission_entries ||--o{ payroll_commission_entries : fk_pce_entry
    payroll_lines ||--o{ payroll_commission_entries : fk_pce_line
    payroll_lines ||--o{ payroll_disbursements : fk_pd_line
    employees ||--o{ payroll_lines : fk_pl_employee
    payroll_periods ||--o{ payroll_lines : fk_pl_period
    stores ||--o{ payroll_periods : fk_pp_store
    media_assets ||--o{ product_images : fk_img_asset
    products ||--o{ product_images : fk_img_prod
    product_variants ||--o{ product_images : fk_img_var_same_product
    products ||--o{ product_variants : fk_var_product
    brands ||--o{ products : fk_prod_brand
    categories ||--o{ products : fk_prod_category
    payments ||--o{ refunds : fk_ref_payment
    sales_returns ||--o{ refunds : fk_ref_return
    roles ||--o{ role_user : role_user_role_id_foreign
    users ||--o{ role_user : role_user_user_id_foreign
    invoice_items ||--o{ sales_return_items : fk_sri_invoice_always
    invoice_items ||--o{ sales_return_items : fk_sri_invoice_item
    sales_returns ||--o{ sales_return_items : fk_sri_return
    customers ||--o{ sales_returns : fk_sr_customer
    invoices ||--o{ sales_returns : fk_sr_invoice
    users ||--o{ share_links : fk_link_creator
    customers ||--o{ share_links : fk_link_customer
    stores ||--o{ share_links : fk_link_store
    product_variants ||--o{ stock_balances : fk_stock_var
    warehouses ||--o{ stock_balances : fk_stock_wh
    stock_balances ||--o{ stock_reservations : fk_res_balance
    order_items ||--o{ stock_reservations : fk_res_order_item
    warehouses ||--o{ stock_reservations : fk_res_warehouse_store
    stores ||--o{ store_user : store_user_store_id_foreign
    users ||--o{ store_user : store_user_user_id_foreign
    users ||--o{ team_invitations : team_invitations_accepted_by_user_id_foreign
    users ||--o{ team_invitations : team_invitations_invited_by_user_id_foreign
    roles ||--o{ team_invitations : team_invitations_role_id_foreign
    attribute_values ||--o{ variant_attribute_values : fk_vav_value
    product_variants ||--o{ variant_attribute_values : fk_vav_var
    stores ||--o{ warehouses : warehouses_store_id_foreign
    customers ||--o{ weekly_accounts : fk_wa_customer
    stores ||--o{ weekly_accounts : fk_wa_store
```

Las líneas muestran referencias estructurales; nulabilidad y reglas de negocio se detallan en las migraciones.
