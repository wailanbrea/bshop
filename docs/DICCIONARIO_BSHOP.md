# Diccionario actual — negocio único

Generado desde bshop_testing_single en MySQL 8.4.13, 127.0.0.1:33078.
Perfil único protegido en base de datos. Los usuarios y sucursales pertenecen a esta instalación.

## attribute_values

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| attribute_id | `bigint unsigned` | NO | MUL |
| value | `varchar(120)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| is_active | `tinyint(1)` | NO |  |

## audit_logs

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| user_id | `bigint unsigned` | YES | MUL |
| event | `varchar(100)` | NO |  |
| entity_type | `varchar(80)` | NO | MUL |
| entity_id | `varchar(64)` | NO |  |
| old_data | `json` | YES |  |
| new_data | `json` | YES |  |
| ip_address | `varchar(45)` | YES |  |
| occurred_at | `datetime(6)` | NO |  |

## brands

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| name | `varchar(130)` | NO | UNI |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| is_active | `tinyint(1)` | NO |  |

## business_profile

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `tinyint unsigned` | NO | PRI |
| name | `varchar(160)` | NO |  |
| legal_name | `varchar(200)` | YES |  |
| default_currency | `char(3)` | NO |  |
| currency_decimals | `tinyint unsigned` | NO |  |
| timezone | `varchar(80)` | NO |  |
| locale | `varchar(12)` | NO |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## business_settings

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `tinyint unsigned` | NO | PRI |
| weekly_close_weekday | `tinyint unsigned` | NO |  |
| sales_tax_rate | `decimal(9,4)` | NO |  |
| reserve_expiry_hours | `int unsigned` | YES |  |
| invoice_prefix | `varchar(20)` | NO |  |
| configuration | `json` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## cache

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| key | `varchar(255)` | NO | PRI |
| value | `mediumtext` | NO |  |
| expiration | `int` | NO |  |

## cache_locks

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| key | `varchar(255)` | NO | PRI |
| owner | `varchar(255)` | NO |  |
| expiration | `int` | NO |  |

## catalog_imports

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `char(26)` | NO | PRI |
| created_by_user_id | `bigint unsigned` | NO | MUL |
| filename | `varchar(180)` | NO |  |
| source_sha256 | `char(64)` | NO |  |
| status | `varchar(20)` | NO |  |
| row_count | `int unsigned` | NO |  |
| product_count | `int unsigned` | NO |  |
| error_count | `int unsigned` | NO |  |
| requires_costs | `tinyint(1)` | NO |  |
| payload | `longtext` | NO |  |
| result | `longtext` | YES |  |
| expires_at | `datetime(6)` | NO |  |
| committed_at | `datetime(6)` | YES |  |
| reverted_at | `datetime(6)` | YES |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## categories

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| parent_id | `bigint unsigned` | YES | MUL |
| name | `varchar(130)` | NO |  |
| slug | `varchar(150)` | NO | UNI |
| is_active | `tinyint(1)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## commission_entries

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| employee_id | `bigint unsigned` | NO | MUL |
| compensation_plan_id | `bigint unsigned` | NO | MUL |
| invoice_item_id | `bigint unsigned` | NO | MUL |
| payment_allocation_id | `bigint unsigned` | YES | MUL |
| refund_id | `bigint unsigned` | YES | MUL |
| entry_type | `varchar(20)` | NO |  |
| commission_base | `decimal(19,4)` | NO |  |
| commission_rate | `decimal(9,4)` | NO |  |
| amount | `decimal(19,4)` | NO |  |
| currency | `char(3)` | NO |  |
| event_key | `varchar(160)` | NO | UNI |
| earned_at | `datetime(6)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| invoice_id | `bigint unsigned` | NO |  |
| store_id | `bigint unsigned` | NO |  |
| customer_id | `bigint unsigned` | NO |  |

## customer_credit_movements

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| payment_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | NO | MUL |
| currency | `char(3)` | NO |  |
| payment_allocation_id | `bigint unsigned` | YES | UNI |
| refund_id | `bigint unsigned` | YES | UNI |
| event_type | `varchar(20)` | NO |  |
| amount_delta | `decimal(19,4)` | NO |  |
| event_key | `varchar(160)` | NO | UNI |
| occurred_at | `datetime(6)` | NO |  |
| created_at | `datetime(6)` | YES |  |

## customers

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| full_name | `varchar(180)` | NO | MUL |
| email | `varchar(190)` | YES |  |
| phone | `varchar(40)` | YES | MUL |
| address | `varchar(255)` | YES |  |
| notes | `text` | YES |  |
| status | `varchar(20)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| deleted_at | `datetime(6)` | YES |  |
| lock_version | `bigint unsigned` | NO |  |

## document_sequences

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| store_id | `bigint unsigned` | NO | PRI |
| document_type | `varchar(24)` | NO | PRI |
| prefix | `varchar(20)` | NO |  |
| next_number | `bigint unsigned` | NO |  |
| updated_at | `datetime(6)` | YES |  |

## employee_compensation_plans

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| employee_id | `bigint unsigned` | NO | MUL |
| pay_type | `varchar(20)` | NO |  |
| currency | `char(3)` | NO |  |
| fixed_salary | `decimal(19,4)` | YES |  |
| salary_period | `varchar(20)` | YES |  |
| commission_rate | `decimal(9,4)` | YES |  |
| commission_base | `varchar(30)` | YES |  |
| commission_trigger | `varchar(20)` | YES |  |
| effective_from | `date` | NO |  |
| effective_to | `date` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## employee_store_assignments

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| employee_id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | PRI |
| created_at | `datetime(6)` | YES |  |

## employees

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| user_id | `bigint unsigned` | YES | UNI |
| home_store_id | `bigint unsigned` | YES | MUL |
| employee_code | `varchar(40)` | NO | UNI |
| full_name | `varchar(180)` | NO |  |
| phone | `varchar(40)` | YES |  |
| email | `varchar(190)` | YES |  |
| can_sell | `tinyint(1)` | NO |  |
| status | `varchar(20)` | NO |  |
| hired_on | `date` | YES |  |
| ended_on | `date` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## failed_jobs

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| uuid | `varchar(255)` | NO | UNI |
| connection | `text` | NO |  |
| queue | `text` | NO |  |
| payload | `longtext` | NO |  |
| exception | `longtext` | NO |  |
| failed_at | `timestamp` | NO |  |

## idempotency_requests

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| operation | `varchar(80)` | NO | MUL |
| request_key | `varchar(160)` | NO |  |
| request_hash | `char(64)` | NO |  |
| status | `varchar(20)` | NO |  |
| resource_type | `varchar(60)` | YES |  |
| resource_id | `bigint unsigned` | YES |  |
| created_at | `datetime(6)` | YES |  |
| completed_at | `datetime(6)` | YES |  |
| response_data | `json` | YES |  |

## inventory_movements

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| warehouse_id | `bigint unsigned` | NO | MUL |
| variant_id | `bigint unsigned` | NO | MUL |
| reservation_id | `bigint unsigned` | YES | MUL |
| return_item_id | `bigint unsigned` | YES | MUL |
| movement_type | `varchar(32)` | NO |  |
| on_hand_delta | `decimal(19,4)` | NO |  |
| reserved_delta | `decimal(19,4)` | NO |  |
| event_key | `varchar(160)` | NO | UNI |
| note | `varchar(255)` | YES |  |
| performed_by_user_id | `bigint unsigned` | YES | MUL |
| occurred_at | `datetime(6)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| store_id | `bigint unsigned` | NO | MUL |
| on_hand_after | `decimal(19,4)` | YES |  |
| reserved_after | `decimal(19,4)` | YES |  |
| balance_version | `bigint unsigned` | YES |  |

## invitation_store_access

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| invitation_id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | PRI |

## invoice_deliveries

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `char(36)` | NO | PRI |
| invoice_id | `bigint unsigned` | NO | MUL |
| store_id | `bigint unsigned` | NO | MUL |
| created_by_user_id | `bigint unsigned` | NO | MUL |
| request_key | `char(36)` | NO | UNI |
| destination | `varchar(32)` | NO |  |
| sender_phone_id | `varchar(64)` | YES |  |
| status | `varchar(20)` | NO |  |
| provider_message_id | `varchar(192)` | YES | UNI |
| error_code | `varchar(80)` | YES |  |
| attempts | `int unsigned` | NO |  |
| accepted_at | `datetime(6)` | YES |  |
| sent_at | `datetime(6)` | YES |  |
| delivered_at | `datetime(6)` | YES |  |
| read_at | `datetime(6)` | YES |  |
| event_at | `datetime(6)` | YES |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## invoice_documents

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| invoice_id | `bigint unsigned` | NO | MUL |
| version | `int unsigned` | NO |  |
| provider | `varchar(30)` | NO | MUL |
| bucket_name | `varchar(150)` | NO |  |
| object_key | `varchar(600)` | YES |  |
| object_key_hash | `char(64)` | YES |  |
| sha256 | `char(64)` | YES |  |
| byte_size | `bigint unsigned` | YES |  |
| status | `varchar(20)` | NO |  |
| created_at | `datetime(6)` | YES |  |

## invoice_items

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| invoice_id | `bigint unsigned` | NO | MUL |
| order_item_id | `bigint unsigned` | YES | UNI |
| seller_employee_id | `bigint unsigned` | YES | MUL |
| description | `varchar(255)` | NO |  |
| sku_snapshot | `varchar(90)` | YES |  |
| quantity | `decimal(19,4)` | NO |  |
| unit_price | `decimal(19,4)` | NO |  |
| discount_amount | `decimal(19,4)` | NO |  |
| tax_amount | `decimal(19,4)` | NO |  |
| line_total | `decimal(19,4)` | NO |  |
| commission_plan_id | `bigint unsigned` | YES | MUL |
| commission_rate_snapshot | `decimal(9,4)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| store_id | `bigint unsigned` | NO |  |
| customer_id | `bigint unsigned` | NO |  |
| currency | `char(3)` | NO |  |
| order_id | `bigint unsigned` | YES |  |
| variant_id | `bigint unsigned` | YES |  |

## invoice_orders

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| invoice_id | `bigint unsigned` | NO | MUL |
| order_id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO |  |
| customer_id | `bigint unsigned` | NO |  |
| currency | `char(3)` | NO |  |
| direct_order_id | `bigint unsigned` | YES |  |
| weekly_account_id | `bigint unsigned` | YES |  |

## invoices

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | NO | MUL |
| weekly_account_id | `bigint unsigned` | YES | UNI |
| order_id | `bigint unsigned` | YES | UNI |
| invoice_number | `varchar(60)` | NO | UNI |
| status | `varchar(20)` | NO |  |
| currency | `char(3)` | NO |  |
| subtotal | `decimal(19,4)` | NO |  |
| discount_total | `decimal(19,4)` | NO |  |
| tax_total | `decimal(19,4)` | NO |  |
| shipping_total | `decimal(19,4)` | NO |  |
| grand_total | `decimal(19,4)` | NO |  |
| issued_at | `datetime(6)` | NO |  |
| due_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| business_snapshot | `json` | YES |  |

## job_batches

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `varchar(255)` | NO | PRI |
| name | `varchar(255)` | NO |  |
| total_jobs | `int` | NO |  |
| pending_jobs | `int` | NO |  |
| failed_jobs | `int` | NO |  |
| failed_job_ids | `longtext` | NO |  |
| options | `mediumtext` | YES |  |
| cancelled_at | `int` | YES |  |
| created_at | `int` | NO |  |
| finished_at | `int` | YES |  |

## jobs

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| queue | `varchar(255)` | NO | MUL |
| payload | `longtext` | NO |  |
| attempts | `tinyint unsigned` | NO |  |
| reserved_at | `int unsigned` | YES |  |
| available_at | `int unsigned` | NO |  |
| created_at | `int unsigned` | NO |  |

## media_assets

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| provider | `varchar(30)` | NO | MUL |
| bucket_name | `varchar(150)` | NO |  |
| staging_provider | `varchar(30)` | NO | MUL |
| staging_bucket_name | `varchar(150)` | NO |  |
| staging_key | `varchar(600)` | NO |  |
| staging_key_hash | `char(64)` | NO |  |
| object_key | `varchar(600)` | YES |  |
| object_key_hash | `char(64)` | YES |  |
| original_filename | `varchar(255)` | YES |  |
| requested_mime | `varchar(100)` | YES |  |
| mime_type | `varchar(100)` | YES |  |
| byte_size | `bigint unsigned` | YES |  |
| width_px | `int unsigned` | YES |  |
| height_px | `int unsigned` | YES |  |
| sha256 | `char(64)` | YES |  |
| visibility | `varchar(12)` | NO |  |
| status | `varchar(20)` | NO | MUL |
| error_code | `varchar(80)` | YES |  |
| uploaded_by_user_id | `bigint unsigned` | YES | MUL |
| ready_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| deleted_at | `datetime(6)` | YES |  |
| storage_token | `char(36)` | YES |  |
| cleanup_pending | `tinyint(1)` | NO |  |
| cleanup_after | `datetime(6)` | YES |  |
| purged_at | `datetime(6)` | YES |  |
| private_cleaned_at | `datetime(6)` | YES |  |

## media_renditions

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| media_asset_id | `bigint unsigned` | NO | MUL |
| rendition | `varchar(24)` | NO |  |
| provider | `varchar(30)` | NO | MUL |
| namespace_name | `varchar(150)` | NO |  |
| object_key | `varchar(600)` | NO |  |
| object_key_hash | `char(64)` | NO |  |
| mime_type | `varchar(100)` | NO |  |
| width_px | `int unsigned` | NO |  |
| height_px | `int unsigned` | NO |  |
| byte_size | `bigint unsigned` | NO |  |
| sha256 | `char(64)` | NO |  |
| created_at | `datetime(6)` | YES |  |

## media_upload_sessions

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| media_asset_id | `bigint unsigned` | NO | UNI |
| request_key | `varchar(160)` | NO | UNI |
| declared_mime | `varchar(100)` | NO |  |
| declared_size | `bigint unsigned` | NO |  |
| expires_at | `datetime(6)` | NO |  |
| status | `varchar(20)` | NO |  |
| completed_at | `datetime(6)` | YES |  |
| created_by_user_id | `bigint unsigned` | NO | MUL |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| product_id | `bigint unsigned` | NO | MUL |
| variant_id | `bigint unsigned` | YES |  |
| reserved_bytes | `bigint unsigned` | NO |  |
| source_sha256 | `char(64)` | YES |  |
| attempts | `int unsigned` | NO |  |

## migrations

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `int unsigned` | NO | PRI |
| migration | `varchar(255)` | NO |  |
| batch | `int` | NO |  |

## order_items

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| order_id | `bigint unsigned` | NO | MUL |
| store_id | `bigint unsigned` | NO |  |
| variant_id | `bigint unsigned` | NO | MUL |
| seller_employee_id | `bigint unsigned` | YES | MUL |
| quantity | `decimal(19,4)` | NO |  |
| unit_price | `decimal(19,4)` | NO |  |
| line_discount | `decimal(19,4)` | NO |  |
| line_tax | `decimal(19,4)` | NO |  |
| line_total | `decimal(19,4)` | NO |  |
| product_name_snapshot | `varchar(200)` | NO |  |
| sku_snapshot | `varchar(90)` | NO |  |
| variant_snapshot | `json` | YES |  |
| commission_plan_id | `bigint unsigned` | YES | MUL |
| commission_rate_snapshot | `decimal(9,4)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| removed_at | `datetime(6)` | YES |  |
| image_url_snapshot | `varchar(2048)` | YES |  |

## order_payments

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| payment_id | `bigint unsigned` | NO | PRI |
| order_id | `bigint unsigned` | NO | MUL |
| store_id | `bigint unsigned` | NO |  |
| customer_id | `bigint unsigned` | NO |  |
| currency | `char(3)` | NO |  |
| created_at | `datetime(6)` | NO |  |

## orders

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | NO | MUL |
| weekly_account_id | `bigint unsigned` | YES | MUL |
| share_link_id | `bigint unsigned` | YES | UNI |
| seller_employee_id | `bigint unsigned` | YES | MUL |
| channel | `varchar(20)` | NO |  |
| status | `varchar(20)` | NO |  |
| currency | `char(3)` | NO |  |
| subtotal | `decimal(19,4)` | NO |  |
| discount_total | `decimal(19,4)` | NO |  |
| tax_total | `decimal(19,4)` | NO |  |
| shipping_total | `decimal(19,4)` | NO |  |
| grand_total | `decimal(19,4)` | NO |  |
| notes | `text` | YES |  |
| confirmed_at | `datetime(6)` | YES |  |
| fulfilled_at | `datetime(6)` | YES |  |
| cancelled_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| lock_version | `bigint unsigned` | NO |  |
| customer_snapshot | `json` | YES |  |
| estimated_delivery | `date` | YES |  |
| warehouse_id | `bigint unsigned` | YES |  |
| tax_rate_snapshot | `decimal(9,4)` | YES |  |
| order_kind | `varchar(20)` | NO |  |
| basket_number | `varchar(24)` | YES |  |
| closed_at | `datetime(6)` | YES |  |
| shipped_at | `datetime(6)` | YES |  |

## outbox_events

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| event_key | `varchar(160)` | NO | UNI |
| event_type | `varchar(100)` | NO |  |
| aggregate_type | `varchar(100)` | NO |  |
| aggregate_id | `bigint unsigned` | NO |  |
| payload | `json` | NO |  |
| status | `varchar(20)` | NO | MUL |
| attempts | `smallint unsigned` | NO |  |
| available_at | `datetime(6)` | NO |  |
| processed_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |

## password_reset_tokens

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| email | `varchar(255)` | NO | PRI |
| token | `varchar(255)` | NO |  |
| created_at | `timestamp` | YES |  |

## payment_allocations

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| payment_id | `bigint unsigned` | NO | MUL |
| invoice_id | `bigint unsigned` | NO | MUL |
| store_id | `bigint unsigned` | NO |  |
| customer_id | `bigint unsigned` | NO |  |
| currency | `char(3)` | NO |  |
| event_key | `varchar(160)` | NO | UNI |
| amount | `decimal(19,4)` | NO |  |
| created_at | `datetime(6)` | YES |  |

## payments

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | NO | MUL |
| weekly_account_id | `bigint unsigned` | YES | MUL |
| payment_method | `varchar(30)` | NO | MUL |
| external_reference | `varchar(160)` | YES |  |
| currency | `char(3)` | NO |  |
| amount | `decimal(19,4)` | NO |  |
| status | `varchar(20)` | NO |  |
| paid_at | `datetime(6)` | YES |  |
| notes | `text` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## payroll_commission_entries

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| payroll_line_id | `bigint unsigned` | NO | MUL |
| commission_entry_id | `bigint unsigned` | NO | PRI |
| employee_id | `bigint unsigned` | NO |  |
| currency | `char(3)` | NO |  |
| created_at | `datetime(6)` | YES |  |

## payroll_disbursements

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| payroll_line_id | `bigint unsigned` | NO | MUL |
| employee_id | `bigint unsigned` | NO |  |
| amount | `decimal(19,4)` | NO |  |
| currency | `char(3)` | NO |  |
| payment_method | `varchar(30)` | NO |  |
| reference | `varchar(160)` | YES |  |
| event_key | `varchar(160)` | NO | UNI |
| paid_at | `datetime(6)` | NO |  |
| created_at | `datetime(6)` | YES |  |

## payroll_lines

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| payroll_period_id | `bigint unsigned` | NO | MUL |
| employee_id | `bigint unsigned` | NO | MUL |
| compensation_type_snapshot | `varchar(20)` | NO |  |
| currency | `char(3)` | NO |  |
| salary_amount | `decimal(19,4)` | NO |  |
| commission_amount | `decimal(19,4)` | NO |  |
| adjustments | `decimal(19,4)` | NO |  |
| deductions | `decimal(19,4)` | NO |  |
| total_payable | `decimal(19,4)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## payroll_periods

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | YES | MUL |
| scope_key | `varchar(80)` | NO | MUL |
| currency | `char(3)` | NO |  |
| starts_on | `date` | NO |  |
| ends_on | `date` | NO |  |
| status | `varchar(20)` | NO |  |
| approved_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## product_attributes

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| name | `varchar(100)` | NO | UNI |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| is_active | `tinyint(1)` | NO |  |

## product_images

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| product_id | `bigint unsigned` | NO | MUL |
| variant_id | `bigint unsigned` | YES |  |
| media_asset_id | `bigint unsigned` | NO | MUL |
| alt_text | `varchar(255)` | YES |  |
| is_primary | `tinyint(1)` | NO |  |
| sort_order | `int unsigned` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| primary_product_id | `bigint unsigned` | YES | UNI |
| deleted_at | `datetime(6)` | YES |  |

## product_variants

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| product_id | `bigint unsigned` | NO | MUL |
| sku | `varchar(90)` | NO | UNI |
| barcode | `varchar(100)` | YES | UNI |
| combination_key | `varchar(255)` | NO |  |
| sale_price | `decimal(19,4)` | NO |  |
| cost_price | `decimal(19,4)` | YES |  |
| is_active | `tinyint(1)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| deleted_at | `datetime(6)` | YES |  |
| lock_version | `bigint unsigned` | NO |  |

## products

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| category_id | `bigint unsigned` | YES | MUL |
| brand_id | `bigint unsigned` | YES | MUL |
| name | `varchar(200)` | NO | MUL |
| description | `text` | YES |  |
| product_type | `varchar(20)` | NO |  |
| unit_code | `varchar(20)` | NO |  |
| track_stock | `tinyint(1)` | NO |  |
| status | `varchar(20)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| deleted_at | `datetime(6)` | YES |  |
| lock_version | `bigint unsigned` | NO |  |

## refunds

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| customer_id | `bigint unsigned` | NO |  |
| sales_return_id | `bigint unsigned` | YES | MUL |
| original_payment_id | `bigint unsigned` | NO | MUL |
| refund_kind | `varchar(25)` | NO |  |
| status | `varchar(20)` | NO |  |
| amount | `decimal(19,4)` | NO |  |
| currency | `char(3)` | NO |  |
| method | `varchar(30)` | NO |  |
| event_key | `varchar(160)` | NO | UNI |
| external_reference | `varchar(160)` | YES |  |
| refunded_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| store_id | `bigint unsigned` | NO |  |
| invoice_id | `bigint unsigned` | YES |  |

## role_user

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| user_id | `bigint unsigned` | NO | PRI |
| role_id | `bigint unsigned` | NO | PRI |

## roles

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| code | `varchar(50)` | NO | UNI |
| label | `varchar(100)` | NO |  |
| permissions | `json` | NO |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## sales_return_items

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| sales_return_id | `bigint unsigned` | NO | MUL |
| invoice_id | `bigint unsigned` | NO |  |
| invoice_item_id | `bigint unsigned` | NO | MUL |
| quantity | `decimal(19,4)` | NO |  |
| refund_amount | `decimal(19,4)` | NO |  |
| restock | `tinyint(1)` | NO |  |
| created_at | `datetime(6)` | YES |  |
| store_id | `bigint unsigned` | NO |  |
| variant_id | `bigint unsigned` | YES |  |

## sales_returns

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| invoice_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | NO | MUL |
| status | `varchar(20)` | NO |  |
| reason | `varchar(255)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
| store_id | `bigint unsigned` | NO |  |
| currency | `char(3)` | NO |  |

## sessions

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `varchar(255)` | NO | PRI |
| user_id | `bigint unsigned` | YES | MUL |
| ip_address | `varchar(45)` | YES |  |
| user_agent | `text` | YES |  |
| payload | `longtext` | NO |  |
| last_activity | `int` | NO | MUL |

## share_links

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | YES | MUL |
| token_hash | `char(64)` | NO | UNI |
| status | `varchar(20)` | NO | MUL |
| expires_at | `datetime(6)` | NO |  |
| used_at | `datetime(6)` | YES |  |
| created_by_user_id | `bigint unsigned` | YES | MUL |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## stock_balances

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| warehouse_id | `bigint unsigned` | NO | PRI |
| variant_id | `bigint unsigned` | NO | PRI |
| on_hand | `decimal(19,4)` | NO |  |
| reserved | `decimal(19,4)` | NO |  |
| version | `bigint unsigned` | NO |  |
| updated_at | `datetime(6)` | YES |  |

## stock_reservations

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| order_item_id | `bigint unsigned` | NO | MUL |
| store_id | `bigint unsigned` | NO | MUL |
| warehouse_id | `bigint unsigned` | NO | MUL |
| variant_id | `bigint unsigned` | NO |  |
| quantity | `decimal(19,4)` | NO |  |
| fulfilled_quantity | `decimal(19,4)` | NO |  |
| released_quantity | `decimal(19,4)` | NO |  |
| status | `varchar(20)` | NO | MUL |
| expires_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |

## storage_quota

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `tinyint unsigned` | NO | PRI |
| max_bytes | `bigint unsigned` | NO |  |
| bytes_ready | `bigint unsigned` | NO |  |
| bytes_pending | `bigint unsigned` | NO |  |
| max_single_upload_bytes | `bigint unsigned` | NO |  |
| max_images_per_product | `tinyint unsigned` | NO |  |
| updated_at | `datetime(6)` | YES |  |
| bytes_retained | `bigint unsigned` | NO |  |

## store_user

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| user_id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | PRI |

## stores

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| name | `varchar(160)` | NO |  |
| code | `varchar(32)` | NO | UNI |
| address | `varchar(255)` | YES |  |
| phone | `varchar(40)` | YES |  |
| is_active | `tinyint(1)` | NO |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## team_invitations

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| role_id | `bigint unsigned` | NO | MUL |
| invited_by_user_id | `bigint unsigned` | NO | MUL |
| email | `varchar(190)` | NO | MUL |
| token_hash | `char(64)` | NO | UNI |
| status | `varchar(20)` | NO |  |
| expires_at | `datetime(6)` | NO |  |
| accepted_at | `datetime(6)` | YES |  |
| accepted_by_user_id | `bigint unsigned` | YES | MUL |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## users

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| name | `varchar(160)` | NO |  |
| email | `varchar(190)` | NO | UNI |
| password | `varchar(255)` | NO |  |
| phone | `varchar(40)` | YES |  |
| is_active | `tinyint(1)` | NO |  |
| email_verified_at | `datetime(6)` | YES |  |
| remember_token | `varchar(100)` | YES |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |

## variant_attribute_values

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| variant_id | `bigint unsigned` | NO | PRI |
| attribute_id | `bigint unsigned` | NO | PRI |
| value_id | `bigint unsigned` | NO |  |

## warehouses

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | MUL |
| name | `varchar(160)` | NO |  |
| is_default | `tinyint(1)` | NO |  |
| is_active | `tinyint(1)` | NO |  |
| created_at | `timestamp(6)` | YES |  |
| updated_at | `timestamp(6)` | YES |  |
| default_store_id | `bigint unsigned` | YES | UNI |

## weekly_accounts

| Campo | Tipo | NULL | Clave |
|---|---|---|---|
| id | `bigint unsigned` | NO | PRI |
| store_id | `bigint unsigned` | NO | MUL |
| customer_id | `bigint unsigned` | NO | MUL |
| period_start | `date` | NO |  |
| period_end | `date` | NO |  |
| status | `varchar(20)` | NO |  |
| closed_at | `datetime(6)` | YES |  |
| created_at | `datetime(6)` | YES |  |
| updated_at | `datetime(6)` | YES |  |
