# Diccionario de referencia — MySQL 8.4.13

Generado desde INFORMATION_SCHEMA del esquema de prueba, no desde producción.
Los tipos/defaults son de referencia; pendientes de convertir a migraciones F2 y de los hallazgos SQL-01…13.

## attribute_values

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| attribute_id | `bigint unsigned` | NO | — |  |
| value | `varchar(120)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## audit_logs

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| membership_id | `bigint unsigned` | YES | — |  |
| event | `varchar(100)` | NO | — |  |
| entity_type | `varchar(80)` | NO | — |  |
| entity_id | `bigint unsigned` | NO | — |  |
| old_data | `json` | YES | — |  |
| new_data | `json` | YES | — |  |
| ip_address | `varchar(45)` | YES | — |  |
| occurred_at | `datetime(6)` | NO | — |  |

## brands

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| name | `varchar(130)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## categories

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| parent_id | `bigint unsigned` | YES | — |  |
| name | `varchar(130)` | NO | — |  |
| slug | `varchar(150)` | NO | — |  |
| is_active | `tinyint(1)` | NO | 1 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## commission_entries

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| employee_id | `bigint unsigned` | NO | — |  |
| compensation_plan_id | `bigint unsigned` | NO | — |  |
| invoice_item_id | `bigint unsigned` | NO | — |  |
| payment_allocation_id | `bigint unsigned` | YES | — |  |
| refund_id | `bigint unsigned` | YES | — |  |
| entry_type | `varchar(20)` | NO | — |  |
| commission_base | `decimal(19,4)` | NO | — |  |
| commission_rate | `decimal(9,4)` | NO | — |  |
| amount | `decimal(19,4)` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| event_key | `varchar(160)` | NO | — |  |
| earned_at | `datetime(6)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## customer_credit_movements

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| payment_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| payment_allocation_id | `bigint unsigned` | YES | — |  |
| refund_id | `bigint unsigned` | YES | — |  |
| event_type | `varchar(20)` | NO | — |  |
| amount_delta | `decimal(19,4)` | NO | — |  |
| event_key | `varchar(160)` | NO | — |  |
| occurred_at | `datetime(6)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## customers

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| full_name | `varchar(180)` | NO | — |  |
| email | `varchar(190)` | YES | — |  |
| phone | `varchar(40)` | YES | — |  |
| address | `varchar(255)` | YES | — |  |
| notes | `text` | YES | — |  |
| status | `varchar(20)` | NO | active |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |
| deleted_at | `datetime(6)` | YES | — |  |

## document_sequences

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| store_id | `bigint unsigned` | NO | — | PRI |
| document_type | `varchar(24)` | NO | — | PRI |
| prefix | `varchar(20)` | NO | — |  |
| next_number | `bigint unsigned` | NO | 1 |  |
| updated_at | `datetime(6)` | YES | — |  |

## employee_compensation_plans

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| employee_id | `bigint unsigned` | NO | — |  |
| pay_type | `varchar(20)` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| fixed_salary | `decimal(19,4)` | YES | — |  |
| salary_period | `varchar(20)` | YES | — |  |
| commission_rate | `decimal(9,4)` | YES | — |  |
| commission_base | `varchar(30)` | YES | net_items |  |
| commission_trigger | `varchar(20)` | YES | payment |  |
| effective_from | `date` | NO | — |  |
| effective_to | `date` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## employee_store_assignments

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| employee_id | `bigint unsigned` | NO | — | PRI |
| store_id | `bigint unsigned` | NO | — | PRI |
| created_at | `datetime(6)` | YES | — |  |

## employees

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| membership_id | `bigint unsigned` | YES | — |  |
| home_store_id | `bigint unsigned` | YES | — |  |
| employee_code | `varchar(40)` | NO | — |  |
| full_name | `varchar(180)` | NO | — |  |
| phone | `varchar(40)` | YES | — |  |
| email | `varchar(190)` | YES | — |  |
| can_sell | `tinyint(1)` | NO | 1 |  |
| status | `varchar(20)` | NO | active |  |
| hired_on | `date` | YES | — |  |
| ended_on | `date` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## idempotency_requests

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| operation | `varchar(80)` | NO | — |  |
| request_key | `varchar(160)` | NO | — |  |
| request_hash | `char(64)` | NO | — |  |
| status | `varchar(20)` | NO | processing |  |
| resource_type | `varchar(60)` | YES | — |  |
| resource_id | `bigint unsigned` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| completed_at | `datetime(6)` | YES | — |  |

## inventory_movements

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| warehouse_id | `bigint unsigned` | NO | — |  |
| variant_id | `bigint unsigned` | NO | — |  |
| reservation_id | `bigint unsigned` | YES | — |  |
| return_item_id | `bigint unsigned` | YES | — |  |
| movement_type | `varchar(32)` | NO | — |  |
| on_hand_delta | `decimal(19,4)` | NO | 0.0000 |  |
| reserved_delta | `decimal(19,4)` | NO | 0.0000 |  |
| event_key | `varchar(160)` | NO | — |  |
| note | `varchar(255)` | YES | — |  |
| performed_by_membership_id | `bigint unsigned` | YES | — |  |
| occurred_at | `datetime(6)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## invoice_items

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| invoice_id | `bigint unsigned` | NO | — |  |
| order_item_id | `bigint unsigned` | YES | — |  |
| seller_employee_id | `bigint unsigned` | YES | — |  |
| description | `varchar(255)` | NO | — |  |
| sku_snapshot | `varchar(90)` | YES | — |  |
| quantity | `decimal(19,4)` | NO | — |  |
| unit_price | `decimal(19,4)` | NO | — |  |
| discount_amount | `decimal(19,4)` | NO | 0.0000 |  |
| tax_amount | `decimal(19,4)` | NO | 0.0000 |  |
| line_total | `decimal(19,4)` | NO | — |  |
| commission_plan_id | `bigint unsigned` | YES | — |  |
| commission_rate_snapshot | `decimal(9,4)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |

## invoices

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| weekly_account_id | `bigint unsigned` | YES | — |  |
| order_id | `bigint unsigned` | YES | — |  |
| invoice_number | `varchar(60)` | NO | — |  |
| status | `varchar(20)` | NO | issued |  |
| currency | `char(3)` | NO | — |  |
| subtotal | `decimal(19,4)` | NO | — |  |
| discount_total | `decimal(19,4)` | NO | 0.0000 |  |
| tax_total | `decimal(19,4)` | NO | 0.0000 |  |
| shipping_total | `decimal(19,4)` | NO | 0.0000 |  |
| grand_total | `decimal(19,4)` | NO | — |  |
| issued_at | `datetime(6)` | NO | — |  |
| due_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## media_assets

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| provider | `varchar(30)` | NO | r2 | MUL |
| bucket_name | `varchar(150)` | NO | — |  |
| staging_provider | `varchar(30)` | NO | local |  |
| staging_bucket_name | `varchar(150)` | NO | — |  |
| staging_key | `varchar(600)` | NO | — |  |
| staging_key_hash | `char(64)` | NO | — |  |
| object_key | `varchar(600)` | YES | — |  |
| object_key_hash | `char(64)` | YES | — |  |
| original_filename | `varchar(255)` | YES | — |  |
| requested_mime | `varchar(100)` | YES | — |  |
| mime_type | `varchar(100)` | YES | — |  |
| byte_size | `bigint unsigned` | YES | — |  |
| width_px | `int unsigned` | YES | — |  |
| height_px | `int unsigned` | YES | — |  |
| sha256 | `char(64)` | YES | — |  |
| visibility | `varchar(12)` | NO | public |  |
| status | `varchar(20)` | NO | pending |  |
| error_code | `varchar(80)` | YES | — |  |
| uploaded_by_membership_id | `bigint unsigned` | YES | — |  |
| ready_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |
| deleted_at | `datetime(6)` | YES | — |  |

## media_renditions

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| media_asset_id | `bigint unsigned` | NO | — |  |
| rendition | `varchar(24)` | NO | — |  |
| provider | `varchar(30)` | NO | r2 | MUL |
| namespace_name | `varchar(150)` | NO | — |  |
| object_key | `varchar(600)` | NO | — |  |
| object_key_hash | `char(64)` | NO | — |  |
| mime_type | `varchar(100)` | NO | — |  |
| width_px | `int unsigned` | NO | — |  |
| height_px | `int unsigned` | NO | — |  |
| byte_size | `bigint unsigned` | NO | — |  |
| sha256 | `char(64)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## media_upload_sessions

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| media_asset_id | `bigint unsigned` | NO | — |  |
| request_key | `varchar(160)` | NO | — |  |
| declared_mime | `varchar(100)` | NO | — |  |
| declared_size | `bigint unsigned` | NO | — |  |
| expires_at | `datetime(6)` | NO | — |  |
| status | `varchar(20)` | NO | issued |  |
| completed_at | `datetime(6)` | YES | — |  |
| created_by_membership_id | `bigint unsigned` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## membership_store_access

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| membership_id | `bigint unsigned` | NO | — | PRI |
| store_id | `bigint unsigned` | NO | — | PRI |
| created_at | `datetime(6)` | YES | — |  |

## order_items

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| order_id | `bigint unsigned` | NO | — |  |
| store_id | `bigint unsigned` | NO | — |  |
| variant_id | `bigint unsigned` | NO | — |  |
| seller_employee_id | `bigint unsigned` | YES | — |  |
| quantity | `decimal(19,4)` | NO | — |  |
| unit_price | `decimal(19,4)` | NO | — |  |
| line_discount | `decimal(19,4)` | NO | 0.0000 |  |
| line_tax | `decimal(19,4)` | NO | 0.0000 |  |
| line_total | `decimal(19,4)` | NO | — |  |
| product_name_snapshot | `varchar(200)` | NO | — |  |
| sku_snapshot | `varchar(90)` | NO | — |  |
| variant_snapshot | `json` | YES | — |  |
| commission_plan_id | `bigint unsigned` | YES | — |  |
| commission_rate_snapshot | `decimal(9,4)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## orders

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| weekly_account_id | `bigint unsigned` | YES | — |  |
| share_link_id | `bigint unsigned` | YES | — |  |
| seller_employee_id | `bigint unsigned` | YES | — |  |
| channel | `varchar(20)` | NO | manual |  |
| status | `varchar(20)` | NO | draft |  |
| currency | `char(3)` | NO | — |  |
| subtotal | `decimal(19,4)` | NO | 0.0000 |  |
| discount_total | `decimal(19,4)` | NO | 0.0000 |  |
| tax_total | `decimal(19,4)` | NO | 0.0000 |  |
| shipping_total | `decimal(19,4)` | NO | 0.0000 |  |
| grand_total | `decimal(19,4)` | NO | 0.0000 |  |
| notes | `text` | YES | — |  |
| confirmed_at | `datetime(6)` | YES | — |  |
| fulfilled_at | `datetime(6)` | YES | — |  |
| cancelled_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## outbox_events

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| event_key | `varchar(160)` | NO | — |  |
| event_type | `varchar(100)` | NO | — |  |
| aggregate_type | `varchar(100)` | NO | — |  |
| aggregate_id | `bigint unsigned` | NO | — |  |
| payload | `json` | NO | — |  |
| status | `varchar(20)` | NO | pending | MUL |
| attempts | `smallint unsigned` | NO | 0 |  |
| available_at | `datetime(6)` | NO | — |  |
| processed_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |

## payment_allocations

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| payment_id | `bigint unsigned` | NO | — |  |
| invoice_id | `bigint unsigned` | NO | — |  |
| store_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| event_key | `varchar(160)` | NO | — |  |
| amount | `decimal(19,4)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## payments

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| weekly_account_id | `bigint unsigned` | YES | — |  |
| payment_method | `varchar(30)` | NO | — |  |
| external_reference | `varchar(160)` | YES | — |  |
| currency | `char(3)` | NO | — |  |
| amount | `decimal(19,4)` | NO | — |  |
| status | `varchar(20)` | NO | pending |  |
| paid_at | `datetime(6)` | YES | — |  |
| notes | `text` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## payroll_commission_entries

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| payroll_line_id | `bigint unsigned` | NO | — |  |
| commission_entry_id | `bigint unsigned` | NO | — | PRI |
| employee_id | `bigint unsigned` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## payroll_disbursements

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| payroll_line_id | `bigint unsigned` | NO | — |  |
| employee_id | `bigint unsigned` | NO | — |  |
| amount | `decimal(19,4)` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| payment_method | `varchar(30)` | NO | — |  |
| reference | `varchar(160)` | YES | — |  |
| event_key | `varchar(160)` | NO | — |  |
| paid_at | `datetime(6)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |

## payroll_lines

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| payroll_period_id | `bigint unsigned` | NO | — |  |
| employee_id | `bigint unsigned` | NO | — |  |
| compensation_type_snapshot | `varchar(20)` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| salary_amount | `decimal(19,4)` | NO | 0.0000 |  |
| commission_amount | `decimal(19,4)` | NO | 0.0000 |  |
| adjustments | `decimal(19,4)` | NO | 0.0000 |  |
| deductions | `decimal(19,4)` | NO | 0.0000 |  |
| total_payable | `decimal(19,4)` | NO | 0.0000 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## payroll_periods

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | YES | — |  |
| scope_key | `varchar(80)` | NO | company |  |
| currency | `char(3)` | NO | — |  |
| starts_on | `date` | NO | — |  |
| ends_on | `date` | NO | — |  |
| status | `varchar(20)` | NO | draft |  |
| approved_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## product_attributes

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| name | `varchar(100)` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## product_images

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| product_id | `bigint unsigned` | NO | — |  |
| variant_id | `bigint unsigned` | YES | — |  |
| media_asset_id | `bigint unsigned` | NO | — |  |
| alt_text | `varchar(255)` | YES | — |  |
| is_primary | `tinyint(1)` | NO | 0 |  |
| sort_order | `int unsigned` | NO | 0 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## product_variants

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| product_id | `bigint unsigned` | NO | — |  |
| sku | `varchar(90)` | NO | — |  |
| barcode | `varchar(100)` | YES | — |  |
| combination_key | `varchar(255)` | NO | default |  |
| sale_price | `decimal(19,4)` | NO | — |  |
| cost_price | `decimal(19,4)` | YES | — |  |
| is_active | `tinyint(1)` | NO | 1 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |
| deleted_at | `datetime(6)` | YES | — |  |

## products

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| category_id | `bigint unsigned` | YES | — |  |
| brand_id | `bigint unsigned` | YES | — |  |
| name | `varchar(200)` | NO | — |  |
| description | `text` | YES | — |  |
| product_type | `varchar(20)` | NO | physical |  |
| unit_code | `varchar(20)` | NO | unit |  |
| track_stock | `tinyint(1)` | NO | 1 |  |
| status | `varchar(20)` | NO | active |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |
| deleted_at | `datetime(6)` | YES | — |  |

## refunds

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| customer_id | `bigint unsigned` | NO | — |  |
| sales_return_id | `bigint unsigned` | YES | — |  |
| original_payment_id | `bigint unsigned` | NO | — |  |
| refund_kind | `varchar(25)` | NO | — |  |
| status | `varchar(20)` | NO | pending |  |
| amount | `decimal(19,4)` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| method | `varchar(30)` | NO | — |  |
| event_key | `varchar(160)` | NO | — |  |
| external_reference | `varchar(160)` | YES | — |  |
| refunded_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## saas_plans

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| code | `varchar(45)` | NO | — | UNI |
| name | `varchar(120)` | NO | — |  |
| billing_cycle | `varchar(20)` | NO | — |  |
| price | `decimal(19,4)` | NO | — |  |
| currency | `char(3)` | NO | — |  |
| max_stores | `smallint unsigned` | YES | — |  |
| max_users | `smallint unsigned` | YES | — |  |
| features | `json` | NO | — |  |
| is_active | `tinyint(1)` | NO | 1 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## sales_return_items

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| sales_return_id | `bigint unsigned` | NO | — |  |
| invoice_id | `bigint unsigned` | NO | — |  |
| invoice_item_id | `bigint unsigned` | NO | — |  |
| quantity | `decimal(19,4)` | NO | — |  |
| refund_amount | `decimal(19,4)` | NO | — |  |
| restock | `tinyint(1)` | NO | 1 |  |
| created_at | `datetime(6)` | YES | — |  |

## sales_returns

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| invoice_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| status | `varchar(20)` | NO | approved |  |
| reason | `varchar(255)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## share_links

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | YES | — |  |
| token_hash | `char(64)` | NO | — | UNI |
| status | `varchar(20)` | NO | active | MUL |
| expires_at | `datetime(6)` | NO | — |  |
| used_at | `datetime(6)` | YES | — |  |
| created_by_membership_id | `bigint unsigned` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## stock_balances

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| warehouse_id | `bigint unsigned` | NO | — | PRI |
| variant_id | `bigint unsigned` | NO | — | PRI |
| on_hand | `decimal(19,4)` | NO | 0.0000 |  |
| reserved | `decimal(19,4)` | NO | 0.0000 |  |
| version | `bigint unsigned` | NO | 0 |  |
| updated_at | `datetime(6)` | YES | — |  |

## stock_reservations

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| order_item_id | `bigint unsigned` | NO | — |  |
| store_id | `bigint unsigned` | NO | — |  |
| warehouse_id | `bigint unsigned` | NO | — |  |
| variant_id | `bigint unsigned` | NO | — |  |
| quantity | `decimal(19,4)` | NO | — |  |
| fulfilled_quantity | `decimal(19,4)` | NO | 0.0000 |  |
| released_quantity | `decimal(19,4)` | NO | 0.0000 |  |
| status | `varchar(20)` | NO | active | MUL |
| expires_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## stores

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| name | `varchar(160)` | NO | — |  |
| code | `varchar(32)` | NO | — |  |
| address | `varchar(255)` | YES | — |  |
| phone | `varchar(40)` | YES | — |  |
| is_active | `tinyint(1)` | NO | 1 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## tenant_membership_roles

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| membership_id | `bigint unsigned` | NO | — | PRI |
| role_id | `bigint unsigned` | NO | — | PRI |
| created_at | `datetime(6)` | YES | — |  |

## tenant_memberships

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| user_id | `bigint unsigned` | NO | — | MUL |
| status | `varchar(20)` | NO | active |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## tenant_roles

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| code | `varchar(50)` | NO | — |  |
| label | `varchar(100)` | NO | — |  |
| permissions | `json` | NO | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## tenant_settings

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| weekly_close_weekday | `tinyint unsigned` | NO | 4 |  |
| sales_tax_rate | `decimal(9,4)` | NO | 0.0000 |  |
| reserve_expiry_hours | `int unsigned` | YES | — |  |
| invoice_prefix | `varchar(20)` | NO | FAC |  |
| configuration | `json` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## tenant_storage_quotas

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| max_bytes | `bigint unsigned` | NO | 2147483648 |  |
| bytes_ready | `bigint unsigned` | NO | 0 |  |
| bytes_pending | `bigint unsigned` | NO | 0 |  |
| max_single_upload_bytes | `bigint unsigned` | NO | 10485760 |  |
| max_images_per_product | `tinyint unsigned` | NO | 5 |  |
| updated_at | `datetime(6)` | YES | — |  |

## tenant_subscriptions

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| saas_plan_id | `bigint unsigned` | NO | — | MUL |
| plan_name_snapshot | `varchar(120)` | YES | — |  |
| amount_snapshot | `decimal(19,4)` | YES | — |  |
| currency_snapshot | `char(3)` | YES | — |  |
| billing_cycle_snapshot | `varchar(20)` | YES | — |  |
| status | `varchar(20)` | NO | trial |  |
| started_at | `datetime(6)` | NO | — |  |
| renews_at | `datetime(6)` | YES | — |  |
| ends_at | `datetime(6)` | YES | — |  |
| external_reference | `varchar(160)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## tenants

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| name | `varchar(160)` | NO | — |  |
| slug | `varchar(120)` | NO | — | UNI |
| legal_name | `varchar(200)` | YES | — |  |
| default_currency | `char(3)` | NO | USD |  |
| currency_decimals | `tinyint unsigned` | NO | 2 |  |
| timezone | `varchar(80)` | NO | America/Santo_Domingo |  |
| locale | `varchar(12)` | NO | es_DO |  |
| status | `varchar(20)` | NO | active |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## users

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| name | `varchar(160)` | NO | — |  |
| email | `varchar(190)` | NO | — | UNI |
| password | `varchar(255)` | NO | — |  |
| phone | `varchar(40)` | YES | — |  |
| is_active | `tinyint(1)` | NO | 1 |  |
| email_verified_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## variant_attribute_values

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| tenant_id | `bigint unsigned` | NO | — | PRI |
| variant_id | `bigint unsigned` | NO | — | PRI |
| attribute_id | `bigint unsigned` | NO | — | PRI |
| value_id | `bigint unsigned` | NO | — |  |

## warehouses

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | NO | — |  |
| name | `varchar(160)` | NO | — |  |
| is_default | `tinyint(1)` | NO | 0 |  |
| is_active | `tinyint(1)` | NO | 1 |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |

## weekly_accounts

| Columna | Tipo | NULL | Default | Índice de columna |
|---|---|---|---|---|
| id | `bigint unsigned` | NO | — | PRI |
| tenant_id | `bigint unsigned` | NO | — | MUL |
| store_id | `bigint unsigned` | NO | — |  |
| customer_id | `bigint unsigned` | NO | — |  |
| period_start | `date` | NO | — |  |
| period_end | `date` | NO | — |  |
| status | `varchar(20)` | NO | open |  |
| closed_at | `datetime(6)` | YES | — |  |
| created_at | `datetime(6)` | YES | — |  |
| updated_at | `datetime(6)` | YES | — |  |
