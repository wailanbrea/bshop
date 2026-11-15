-- KeylaShop | Modelo relacional AUDITADO v2.1 — almacenamiento local sin cargos adicionales (referencia)
-- MySQL 8.0.16+ / 8.4 LTS; InnoDB; utf8mb4; fechas UTC.
-- ESQUEMA DE REFERENCIA; NO EJECUTADO EN MYSQL REAL. APLICAR MEDIANTE MIGRACIONES LARAVEL
-- después de pruebas MySQL 8.0.16+/8.4. Cambios críticos documentados en 01_AUDITORIA_TECNICA_V2_1.md.
-- Guardar fechas DATETIME(6) en UTC; convertir a zona del negocio en capa de presentación.
-- Todas las relaciones de negocio entre tablas con tenant_id utilizan FK compuestas
-- para impedir referencias entre comercios diferentes.

CREATE DATABASE IF NOT EXISTS keylashop
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE keylashop;
SET time_zone = '+00:00';

-- 01. Identidad, multiempresa y control de acceso
CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  email_verified_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  legal_name VARCHAR(200) NULL,
  default_currency CHAR(3) NOT NULL DEFAULT 'USD',
  currency_decimals TINYINT UNSIGNED NOT NULL DEFAULT 2,
  timezone VARCHAR(80) NOT NULL DEFAULT 'America/Santo_Domingo',
  locale VARCHAR(12) NOT NULL DEFAULT 'es_DO',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_tenants_slug (slug),
  CONSTRAINT chk_tenant_decimal CHECK (currency_decimals <= 4),
  CONSTRAINT chk_tenant_status CHECK (status IN ('active','suspended','closed'))
) ENGINE=InnoDB;

CREATE TABLE tenant_memberships (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_member_tenant_id (tenant_id, id),
  UNIQUE KEY uq_member_user (tenant_id, user_id),
  CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT chk_membership_status CHECK (status IN ('active','suspended','invited'))
) ENGINE=InnoDB;

CREATE TABLE tenant_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  permissions JSON NOT NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_roles_tenant_id (tenant_id, id),
  UNIQUE KEY uq_roles_code (tenant_id, code),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE tenant_membership_roles (
  tenant_id BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, membership_id, role_id),
  CONSTRAINT fk_mr_membership FOREIGN KEY (tenant_id, membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT fk_mr_role FOREIGN KEY (tenant_id, role_id)
    REFERENCES tenant_roles(tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE stores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  code VARCHAR(32) NOT NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(40) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_stores_tenant_id (tenant_id, id),
  UNIQUE KEY uq_stores_code (tenant_id, code),
  CONSTRAINT fk_stores_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  is_default BOOLEAN NOT NULL DEFAULT FALSE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_wh_tenant_id (tenant_id, id),
  UNIQUE KEY uq_wh_store_id (tenant_id, store_id, id),
  INDEX idx_wh_store (tenant_id, store_id),
  CONSTRAINT fk_wh_store FOREIGN KEY (tenant_id, store_id) REFERENCES stores(tenant_id, id)
) ENGINE=InnoDB;

-- Restricción positiva de sucursales: si se habilita en la aplicación, el usuario
-- solo podrá operar en las sucursales listadas explícitamente aquí.
CREATE TABLE membership_store_access (
  tenant_id BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, membership_id, store_id),
  CONSTRAINT fk_msa_membership FOREIGN KEY (tenant_id, membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT fk_msa_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE tenant_settings (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  weekly_close_weekday TINYINT UNSIGNED NOT NULL DEFAULT 4,
  sales_tax_rate DECIMAL(9,4) NOT NULL DEFAULT 0,
  reserve_expiry_hours INT UNSIGNED NULL,
  invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'FAC',
  configuration JSON NULL,
  updated_at DATETIME(6) NULL,
  CONSTRAINT fk_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT chk_settings_weekday CHECK (weekly_close_weekday BETWEEN 1 AND 7),
  CONSTRAINT chk_settings_tax CHECK (sales_tax_rate BETWEEN 0 AND 100)
) ENGINE=InnoDB;

-- 02. Personal, vendedores y esquemas de pago versionados
CREATE TABLE employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  home_store_id BIGINT UNSIGNED NULL,
  employee_code VARCHAR(40) NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  can_sell BOOLEAN NOT NULL DEFAULT TRUE,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  hired_on DATE NULL,
  ended_on DATE NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_emp_tenant_id (tenant_id, id),
  UNIQUE KEY uq_emp_code (tenant_id, employee_code),
  UNIQUE KEY uq_emp_member (tenant_id, membership_id),
  CONSTRAINT fk_emp_member FOREIGN KEY (tenant_id, membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT fk_emp_store FOREIGN KEY (tenant_id, home_store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT chk_emp_status CHECK (status IN ('active','inactive','terminated'))
) ENGINE=InnoDB;

CREATE TABLE employee_store_assignments (
  tenant_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, employee_id, store_id),
  CONSTRAINT fk_esa_emp FOREIGN KEY (tenant_id, employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT fk_esa_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE employee_compensation_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  pay_type VARCHAR(20) NOT NULL,  -- salary | commission; sin mezcla en MVP
  currency CHAR(3) NOT NULL,
  fixed_salary DECIMAL(19,4) NULL,
  salary_period VARCHAR(20) NULL, -- weekly | biweekly | monthly
  commission_rate DECIMAL(9,4) NULL, -- porcentaje de 0 a 100
  commission_base VARCHAR(30) NULL DEFAULT 'net_items',
  commission_trigger VARCHAR(20) NULL DEFAULT 'payment',
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_plan_tenant_id (tenant_id, id),
  UNIQUE KEY uq_plan_employee_currency (tenant_id, id, employee_id, currency),
  UNIQUE KEY uq_plan_employee (tenant_id, id, employee_id),
  INDEX idx_plan_employee_dates (tenant_id, employee_id, effective_from, effective_to),
  CONSTRAINT fk_plan_emp FOREIGN KEY (tenant_id, employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT chk_plan_type CHECK (pay_type IN ('salary','commission')),
  CONSTRAINT chk_plan_dates CHECK (effective_to IS NULL OR effective_to >= effective_from),
  CONSTRAINT chk_plan_mode CHECK (
    (pay_type='salary' AND fixed_salary IS NOT NULL AND fixed_salary >= 0
      AND salary_period IN ('weekly','biweekly','monthly') AND commission_rate IS NULL)
    OR
    (pay_type='commission' AND fixed_salary IS NULL AND salary_period IS NULL
      AND commission_rate IS NOT NULL AND commission_rate BETWEEN 0 AND 100
      AND commission_base IN ('net_items')
      AND commission_trigger IN ('payment'))
  )
) ENGINE=InnoDB;

-- 03. Clientes y catálogo reutilizable para rubros distintos
CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  full_name VARCHAR(180) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(255) NULL,
  notes TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uq_customer_tenant_id (tenant_id, id),
  INDEX idx_customers_name (tenant_id, full_name),
  INDEX idx_customers_phone (tenant_id, phone),
  CONSTRAINT fk_customer_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(130) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_category_tenant_id (tenant_id, id),
  UNIQUE KEY uq_category_slug (tenant_id, slug),
  CONSTRAINT fk_category_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_category_parent FOREIGN KEY (tenant_id, parent_id)
    REFERENCES categories(tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE brands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(130) NOT NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_brand_tenant_id (tenant_id, id),
  UNIQUE KEY uq_brand_name (tenant_id, name),
  CONSTRAINT fk_brand_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  brand_id BIGINT UNSIGNED NULL,
  name VARCHAR(200) NOT NULL,
  description TEXT NULL,
  product_type VARCHAR(20) NOT NULL DEFAULT 'physical', -- physical | service
  unit_code VARCHAR(20) NOT NULL DEFAULT 'unit',
  track_stock BOOLEAN NOT NULL DEFAULT TRUE,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uq_product_tenant_id (tenant_id, id),
  INDEX idx_product_name (tenant_id, name),
  INDEX idx_product_category (tenant_id, category_id),
  CONSTRAINT fk_prod_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_prod_category FOREIGN KEY (tenant_id, category_id)
    REFERENCES categories(tenant_id, id),
  CONSTRAINT fk_prod_brand FOREIGN KEY (tenant_id, brand_id)
    REFERENCES brands(tenant_id, id),
  CONSTRAINT chk_prod_type CHECK (product_type IN ('physical','service'))
) ENGINE=InnoDB;

CREATE TABLE product_attributes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL, -- Talla, Color, Presentación, Material, etc.
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_attr_tenant_id (tenant_id, id),
  UNIQUE KEY uq_attr_name (tenant_id, name),
  CONSTRAINT fk_attr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE attribute_values (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  value VARCHAR(120) NOT NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_av_tenant_id (tenant_id, id),
  UNIQUE KEY uq_av_attr_id (tenant_id, attribute_id, id),
  UNIQUE KEY uq_av_value (tenant_id, attribute_id, value),
  CONSTRAINT fk_av_attr FOREIGN KEY (tenant_id, attribute_id)
    REFERENCES product_attributes(tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE product_variants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(90) NOT NULL,
  barcode VARCHAR(100) NULL,
  combination_key VARCHAR(255) NOT NULL DEFAULT 'default',
  sale_price DECIMAL(19,4) NOT NULL,
  cost_price DECIMAL(19,4) NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uq_var_tenant_id (tenant_id, id),
  UNIQUE KEY uq_var_product_id (tenant_id, product_id, id),
  UNIQUE KEY uq_var_sku (tenant_id, sku),
  UNIQUE KEY uq_var_barcode (tenant_id, barcode),
  UNIQUE KEY uq_var_combination (tenant_id, product_id, combination_key),
  INDEX idx_var_product (tenant_id, product_id),
  CONSTRAINT fk_var_product FOREIGN KEY (tenant_id, product_id)
    REFERENCES products(tenant_id, id),
  CONSTRAINT chk_var_price CHECK (sale_price >= 0 AND (cost_price IS NULL OR cost_price >= 0))
) ENGINE=InnoDB;

CREATE TABLE variant_attribute_values (
  tenant_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  attribute_id BIGINT UNSIGNED NOT NULL,
  value_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id, variant_id, attribute_id),
  CONSTRAINT fk_vav_var FOREIGN KEY (tenant_id, variant_id)
    REFERENCES product_variants(tenant_id, id),
  CONSTRAINT fk_vav_value FOREIGN KEY (tenant_id, attribute_id, value_id)
    REFERENCES attribute_values(tenant_id, attribute_id, id)
) ENGINE=InnoDB;

-- Los archivos NO se almacenan dentro de MySQL. Imagen pública solo si ready.
CREATE TABLE media_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'r2', -- R2 Standard en producción; local para test
  bucket_name VARCHAR(150) NOT NULL, -- namespace/disco lógico local o bucket R2
  staging_provider VARCHAR(30) NOT NULL DEFAULT 'local', -- temporal privado del servidor Laravel
  staging_bucket_name VARCHAR(150) NOT NULL, -- namespace/disco temporal privado
  staging_key VARCHAR(600) NOT NULL,
  staging_key_hash CHAR(64) NOT NULL, -- SHA-256 de staging_key para indexar sin superar 3072 bytes
  object_key VARCHAR(600) NULL,
  object_key_hash CHAR(64) NULL,  -- objeto inmutable, procesado, nunca URL completa
  original_filename VARCHAR(255) NULL,
  requested_mime VARCHAR(100) NULL,
  mime_type VARCHAR(100) NULL,    -- MIME verificado con decoder
  byte_size BIGINT UNSIGNED NULL,
  width_px INT UNSIGNED NULL,
  height_px INT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  visibility VARCHAR(12) NOT NULL DEFAULT 'public', -- public|private
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|uploaded|processing|ready|rejected|deleted
  error_code VARCHAR(80) NULL,
  uploaded_by_membership_id BIGINT UNSIGNED NULL,
  ready_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uq_media_tenant_id (tenant_id, id),
  UNIQUE KEY uq_media_stage (provider, staging_bucket_name, staging_key_hash),
  UNIQUE KEY uq_media_final (provider, bucket_name, object_key_hash),
  INDEX idx_media_status (tenant_id, status, created_at),
  CONSTRAINT fk_media_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_media_uploader FOREIGN KEY (tenant_id, uploaded_by_membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT chk_media_provider CHECK (provider IN ('local','r2')),
  CONSTRAINT chk_media_staging_provider CHECK (staging_provider IN ('local','r2')),
  CONSTRAINT chk_media_visibility CHECK (visibility IN ('public','private')),
  CONSTRAINT chk_media_status CHECK (status IN ('pending','uploaded','processing','ready','rejected','deleted')),
  CONSTRAINT chk_media_dimensions CHECK (
    (width_px IS NULL OR width_px > 0) AND
    (height_px IS NULL OR height_px > 0) AND
    (byte_size IS NULL OR byte_size > 0)
  )
) ENGINE=InnoDB;

CREATE TABLE media_upload_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  media_asset_id BIGINT UNSIGNED NOT NULL,
  request_key VARCHAR(160) NOT NULL,
  declared_mime VARCHAR(100) NOT NULL,
  declared_size BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'issued', -- issued|uploaded|verified|expired|failed
  completed_at DATETIME(6) NULL,
  created_by_membership_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_mus_tenant_id (tenant_id, id),
  UNIQUE KEY uq_mus_request (tenant_id, request_key),
  UNIQUE KEY uq_mus_media (tenant_id, media_asset_id),
  CONSTRAINT fk_mus_media FOREIGN KEY (tenant_id, media_asset_id)
    REFERENCES media_assets(tenant_id, id),
  CONSTRAINT fk_mus_creator FOREIGN KEY (tenant_id, created_by_membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT chk_mus_size CHECK (declared_size > 0 AND declared_size <= 10485760), -- máximo técnico 10 MiB; config puede limitar más
  CONSTRAINT chk_mus_status CHECK (status IN ('issued','uploaded','verified','expired','failed'))
) ENGINE=InnoDB;

CREATE TABLE product_images (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  media_asset_id BIGINT UNSIGNED NOT NULL,
  alt_text VARCHAR(255) NULL,
  is_primary BOOLEAN NOT NULL DEFAULT FALSE,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_img_tenant_id (tenant_id, id),
  UNIQUE KEY uq_img_product_media (tenant_id, product_id, media_asset_id),
  INDEX idx_img_product_sort (tenant_id, product_id, sort_order),
  CONSTRAINT fk_img_prod FOREIGN KEY (tenant_id, product_id)
    REFERENCES products(tenant_id, id),
  -- Impide elegir una variante de otro producto aunque sea del mismo tenant.
  CONSTRAINT fk_img_var_same_product FOREIGN KEY (tenant_id, product_id, variant_id)
    REFERENCES product_variants(tenant_id, product_id, id),
  CONSTRAINT fk_img_asset FOREIGN KEY (tenant_id, media_asset_id)
    REFERENCES media_assets(tenant_id, id)
) ENGINE=InnoDB;

-- Derivados de la misma imagen, optimizados localmente antes de publicarlos.
-- No se necesita un SaaS de transformaciones. Solo almacenar rutas y metadata.
CREATE TABLE media_renditions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  media_asset_id BIGINT UNSIGNED NOT NULL,
  rendition VARCHAR(24) NOT NULL, -- thumb|card|detail
  provider VARCHAR(30) NOT NULL DEFAULT 'r2',
  namespace_name VARCHAR(150) NOT NULL, -- disk lógico o bucket
  object_key VARCHAR(600) NOT NULL, -- ruta relativa inmutable
  object_key_hash CHAR(64) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  width_px INT UNSIGNED NOT NULL,
  height_px INT UNSIGNED NOT NULL,
  byte_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_rend_tenant_id (tenant_id, id),
  UNIQUE KEY uq_rend_asset_kind (tenant_id, media_asset_id, rendition),
  UNIQUE KEY uq_rend_storage (provider, namespace_name, object_key_hash),
  INDEX idx_rend_asset (tenant_id, media_asset_id),
  CONSTRAINT fk_rend_media FOREIGN KEY (tenant_id, media_asset_id)
    REFERENCES media_assets(tenant_id, id),
  CONSTRAINT chk_rend_kind CHECK (rendition IN ('thumb','card','detail')),
  CONSTRAINT chk_rend_provider CHECK (provider IN ('local','r2')),
  CONSTRAINT chk_rend_dimensions CHECK (width_px > 0 AND height_px > 0 AND byte_size > 0)
) ENGINE=InnoDB;

-- Una fila por empresa para proteger la cuota durante subidas concurrentes.
-- Reconciliar los contadores con archivos físicos y filas ready en una tarea periódica.
CREATE TABLE tenant_storage_quotas (
  tenant_id BIGINT UNSIGNED PRIMARY KEY,
  max_bytes BIGINT UNSIGNED NOT NULL DEFAULT 2147483648, -- 2 GiB por tienda, configurable
  bytes_ready BIGINT UNSIGNED NOT NULL DEFAULT 0,
  bytes_pending BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_single_upload_bytes BIGINT UNSIGNED NOT NULL DEFAULT 10485760,
  max_images_per_product TINYINT UNSIGNED NOT NULL DEFAULT 5,
  updated_at DATETIME(6) NULL,
  CONSTRAINT fk_storage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT chk_quota_size CHECK (max_bytes > 0 AND max_single_upload_bytes > 0 AND max_images_per_product BETWEEN 1 AND 20),
  CONSTRAINT chk_quota_current CHECK (bytes_ready + bytes_pending <= max_bytes)
) ENGINE=InnoDB;

-- 04. Inventario por almacén y variante
CREATE TABLE stock_balances (
  tenant_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  on_hand DECIMAL(19,4) NOT NULL DEFAULT 0,
  reserved DECIMAL(19,4) NOT NULL DEFAULT 0,
  version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, warehouse_id, variant_id),
  INDEX idx_stock_variant (tenant_id, variant_id),
  CONSTRAINT fk_stock_wh FOREIGN KEY (tenant_id, warehouse_id)
    REFERENCES warehouses(tenant_id, id),
  CONSTRAINT fk_stock_var FOREIGN KEY (tenant_id, variant_id)
    REFERENCES product_variants(tenant_id, id),
  CONSTRAINT chk_stock_nonneg CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)
) ENGINE=InnoDB;

-- 05. Pedidos, enlaces de acceso y cuentas semanales
CREATE TABLE share_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  expires_at DATETIME(6) NOT NULL,
  used_at DATETIME(6) NULL,
  created_by_membership_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_link_tenant_id (tenant_id, id),
  UNIQUE KEY uq_link_store_id (tenant_id, id, store_id),
  UNIQUE KEY uq_link_token (token_hash),
  INDEX idx_link_expiry (status, expires_at),
  CONSTRAINT fk_link_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT fk_link_customer FOREIGN KEY (tenant_id, customer_id)
    REFERENCES customers(tenant_id, id),
  CONSTRAINT fk_link_creator FOREIGN KEY (tenant_id, created_by_membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT chk_link_status CHECK (status IN ('active','used','revoked','expired'))
) ENGINE=InnoDB;

CREATE TABLE weekly_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  closed_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_wa_tenant_id (tenant_id, id),
  UNIQUE KEY uq_wa_context (tenant_id, id, store_id, customer_id),
  UNIQUE KEY uq_wa_cycle (tenant_id, store_id, customer_id, period_start, period_end),
  CONSTRAINT fk_wa_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT fk_wa_customer FOREIGN KEY (tenant_id, customer_id)
    REFERENCES customers(tenant_id, id),
  CONSTRAINT chk_wa_period CHECK (period_end >= period_start),
  CONSTRAINT chk_wa_status CHECK (status IN ('open','closed','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  weekly_account_id BIGINT UNSIGNED NULL,
  share_link_id BIGINT UNSIGNED NULL,
  seller_employee_id BIGINT UNSIGNED NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'manual', -- manual | share_link
  status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft | reserved | confirmed | fulfilled | cancelled
  currency CHAR(3) NOT NULL,
  subtotal DECIMAL(19,4) NOT NULL DEFAULT 0,
  discount_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  tax_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  shipping_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  grand_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  confirmed_at DATETIME(6) NULL,
  fulfilled_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_order_tenant_id (tenant_id, id),
  UNIQUE KEY uq_order_context (tenant_id, id, store_id, customer_id),
  UNIQUE KEY uq_order_context_currency (tenant_id, id, store_id, customer_id, currency),
  UNIQUE KEY uq_order_store (tenant_id, id, store_id),
  UNIQUE KEY uq_order_link (tenant_id, share_link_id),
  INDEX idx_order_customer_status (tenant_id, customer_id, status),
  INDEX idx_order_weekly (tenant_id, weekly_account_id),
  INDEX idx_order_seller (tenant_id, seller_employee_id),
  CONSTRAINT fk_order_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT fk_order_customer FOREIGN KEY (tenant_id, customer_id)
    REFERENCES customers(tenant_id, id),
  CONSTRAINT fk_order_weekly FOREIGN KEY (tenant_id, weekly_account_id, store_id, customer_id)
    REFERENCES weekly_accounts(tenant_id, id, store_id, customer_id),
  CONSTRAINT fk_order_link FOREIGN KEY (tenant_id, share_link_id, store_id)
    REFERENCES share_links(tenant_id, id, store_id),
  CONSTRAINT fk_order_seller FOREIGN KEY (tenant_id, seller_employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT chk_order_totals CHECK (
    subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND
    shipping_total >= 0 AND grand_total >= 0
  ),
  CONSTRAINT chk_order_status CHECK (status IN ('draft','reserved','confirmed','fulfilled','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL, -- redundancia validada para mismo almacén/sucursal
  variant_id BIGINT UNSIGNED NOT NULL,
  seller_employee_id BIGINT UNSIGNED NULL,
  quantity DECIMAL(19,4) NOT NULL,
  unit_price DECIMAL(19,4) NOT NULL,
  line_discount DECIMAL(19,4) NOT NULL DEFAULT 0,
  line_tax DECIMAL(19,4) NOT NULL DEFAULT 0,
  line_total DECIMAL(19,4) NOT NULL,
  -- Copia histórica de las características y el precio al confirmar la venta
  product_name_snapshot VARCHAR(200) NOT NULL,
  sku_snapshot VARCHAR(90) NOT NULL,
  variant_snapshot JSON NULL,
  commission_plan_id BIGINT UNSIGNED NULL,
  commission_rate_snapshot DECIMAL(9,4) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_oi_tenant_id (tenant_id, id),
  UNIQUE KEY uq_oi_variant_id (tenant_id, id, variant_id),
  UNIQUE KEY uq_oi_store_variant (tenant_id, id, store_id, variant_id),
  INDEX idx_oi_order (tenant_id, order_id),
  INDEX idx_oi_variant (tenant_id, variant_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (tenant_id, order_id, store_id)
    REFERENCES orders(tenant_id, id, store_id),
  CONSTRAINT fk_oi_var FOREIGN KEY (tenant_id, variant_id)
    REFERENCES product_variants(tenant_id, id),
  CONSTRAINT fk_oi_seller FOREIGN KEY (tenant_id, seller_employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT fk_oi_plan FOREIGN KEY (tenant_id, commission_plan_id, seller_employee_id)
    REFERENCES employee_compensation_plans(tenant_id, id, employee_id),
  CONSTRAINT chk_oi_amount CHECK (
    quantity > 0 AND unit_price >= 0 AND line_discount >= 0 AND
    line_tax >= 0 AND line_total >= 0 AND
    (commission_rate_snapshot IS NULL OR commission_rate_snapshot BETWEEN 0 AND 100) AND
    (commission_plan_id IS NULL OR seller_employee_id IS NOT NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE stock_reservations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(19,4) NOT NULL,
  fulfilled_quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
  released_quantity DECIMAL(19,4) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_res_tenant_id (tenant_id, id),
  UNIQUE KEY uq_res_order_item_wh (tenant_id, order_item_id, warehouse_id),
  UNIQUE KEY uq_res_context (tenant_id, id, warehouse_id, variant_id),
  INDEX idx_res_expiry (status, expires_at),
  CONSTRAINT fk_res_order_item FOREIGN KEY (tenant_id, order_item_id, store_id, variant_id)
    REFERENCES order_items(tenant_id, id, store_id, variant_id),
  CONSTRAINT fk_res_warehouse_store FOREIGN KEY (tenant_id, store_id, warehouse_id)
    REFERENCES warehouses(tenant_id, store_id, id),
  CONSTRAINT fk_res_balance FOREIGN KEY (tenant_id, warehouse_id, variant_id)
    REFERENCES stock_balances(tenant_id, warehouse_id, variant_id),
  CONSTRAINT chk_res_qty CHECK (
    quantity > 0 AND fulfilled_quantity >= 0 AND released_quantity >= 0 AND
    fulfilled_quantity + released_quantity <= quantity
  ),
  CONSTRAINT chk_res_status CHECK (status IN ('active','fulfilled','released','expired'))
) ENGINE=InnoDB;

-- 06. Facturación, cobros, devoluciones y reembolsos
CREATE TABLE invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  weekly_account_id BIGINT UNSIGNED NULL,
  order_id BIGINT UNSIGNED NULL,
  invoice_number VARCHAR(60) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'issued', -- issued | partially_paid | paid | void | refunded
  currency CHAR(3) NOT NULL,
  subtotal DECIMAL(19,4) NOT NULL,
  discount_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  tax_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  shipping_total DECIMAL(19,4) NOT NULL DEFAULT 0,
  grand_total DECIMAL(19,4) NOT NULL,
  issued_at DATETIME(6) NOT NULL,
  due_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_invoice_tenant_id (tenant_id, id),
  UNIQUE KEY uq_invoice_customer (tenant_id, id, customer_id),
  UNIQUE KEY uq_invoice_customer_currency (tenant_id, id, customer_id, currency),
  UNIQUE KEY uq_invoice_store_customer_currency (tenant_id, id, store_id, customer_id, currency),
  UNIQUE KEY uq_invoice_number (tenant_id, invoice_number),
  UNIQUE KEY uq_invoice_weekly (tenant_id, weekly_account_id),
  UNIQUE KEY uq_invoice_order (tenant_id, order_id),
  CONSTRAINT fk_invoice_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT fk_invoice_customer FOREIGN KEY (tenant_id, customer_id)
    REFERENCES customers(tenant_id, id),
  CONSTRAINT fk_invoice_wa FOREIGN KEY (tenant_id, weekly_account_id, store_id, customer_id)
    REFERENCES weekly_accounts(tenant_id, id, store_id, customer_id),
  CONSTRAINT fk_invoice_order FOREIGN KEY (tenant_id, order_id, store_id, customer_id, currency)
    REFERENCES orders(tenant_id, id, store_id, customer_id, currency),
  CONSTRAINT chk_invoice_source CHECK (
    (weekly_account_id IS NOT NULL AND order_id IS NULL) OR
    (weekly_account_id IS NULL AND order_id IS NOT NULL)
  ),
  CONSTRAINT chk_invoice_amount CHECK (
    subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND
    shipping_total >= 0 AND grand_total >= 0
  ),
  CONSTRAINT chk_invoice_status CHECK (status IN ('issued','partially_paid','paid','void','refunded'))
) ENGINE=InnoDB;

-- Secuencia de documentos por empresa/sucursal/tipo, asignada bajo FOR UPDATE.
CREATE TABLE document_sequences (
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  document_type VARCHAR(24) NOT NULL,
  prefix VARCHAR(20) NOT NULL,
  next_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, store_id, document_type),
  CONSTRAINT fk_sequence_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT chk_sequence_next CHECK (next_number >= 1)
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NULL,
  seller_employee_id BIGINT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  sku_snapshot VARCHAR(90) NULL,
  quantity DECIMAL(19,4) NOT NULL,
  unit_price DECIMAL(19,4) NOT NULL,
  discount_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  line_total DECIMAL(19,4) NOT NULL,
  commission_plan_id BIGINT UNSIGNED NULL,
  commission_rate_snapshot DECIMAL(9,4) NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_ii_tenant_id (tenant_id, id),
  UNIQUE KEY uq_ii_invoice_id (tenant_id, id, invoice_id),
  UNIQUE KEY uq_ii_order_item (tenant_id, order_item_id),
  INDEX idx_ii_invoice (tenant_id, invoice_id),
  CONSTRAINT fk_ii_invoice FOREIGN KEY (tenant_id, invoice_id)
    REFERENCES invoices(tenant_id, id),
  CONSTRAINT fk_ii_order_item FOREIGN KEY (tenant_id, order_item_id)
    REFERENCES order_items(tenant_id, id),
  CONSTRAINT fk_ii_seller FOREIGN KEY (tenant_id, seller_employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT fk_ii_plan FOREIGN KEY (tenant_id, commission_plan_id, seller_employee_id)
    REFERENCES employee_compensation_plans(tenant_id, id, employee_id),
  CONSTRAINT chk_ii_amount CHECK (
    quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND
    tax_amount >= 0 AND line_total >= 0 AND
    (commission_rate_snapshot IS NULL OR commission_rate_snapshot BETWEEN 0 AND 100) AND
    (commission_plan_id IS NULL OR seller_employee_id IS NOT NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  weekly_account_id BIGINT UNSIGNED NULL,
  payment_method VARCHAR(30) NOT NULL,
  external_reference VARCHAR(160) NULL,
  currency CHAR(3) NOT NULL,
  amount DECIMAL(19,4) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  paid_at DATETIME(6) NULL,
  notes TEXT NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_payment_tenant_id (tenant_id, id),
  UNIQUE KEY uq_payment_context (tenant_id, id, customer_id, currency),
  UNIQUE KEY uq_payment_store_customer_currency (tenant_id, id, store_id, customer_id, currency),
  UNIQUE KEY uq_payment_method_reference (tenant_id, payment_method, external_reference),
  INDEX idx_payment_customer (tenant_id, customer_id, paid_at),
  CONSTRAINT fk_payment_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT fk_payment_customer FOREIGN KEY (tenant_id, customer_id)
    REFERENCES customers(tenant_id, id),
  CONSTRAINT fk_payment_wa FOREIGN KEY (tenant_id, weekly_account_id, store_id, customer_id)
    REFERENCES weekly_accounts(tenant_id, id, store_id, customer_id),
  CONSTRAINT chk_payment_amt CHECK (amount > 0),
  CONSTRAINT chk_payment_status CHECK (status IN ('pending','confirmed','failed','reversed') AND (status <> 'confirmed' OR paid_at IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE payment_allocations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  event_key VARCHAR(160) NOT NULL, -- operación inmutable e idempotente
  amount DECIMAL(19,4) NOT NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_pa_tenant_id (tenant_id, id),
  UNIQUE KEY uq_pa_context (tenant_id, id, payment_id, customer_id, currency),
  UNIQUE KEY uq_pa_event (tenant_id, event_key),
  INDEX idx_pa_payment_invoice (tenant_id, payment_id, invoice_id),
  CONSTRAINT fk_pa_payment FOREIGN KEY (tenant_id, payment_id, store_id, customer_id, currency)
    REFERENCES payments(tenant_id, id, store_id, customer_id, currency),
  CONSTRAINT fk_pa_invoice FOREIGN KEY (tenant_id, invoice_id, store_id, customer_id, currency)
    REFERENCES invoices(tenant_id, id, store_id, customer_id, currency),
  CONSTRAINT chk_pa_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE sales_returns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'approved',
  reason VARCHAR(255) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_sr_tenant_id (tenant_id, id),
  UNIQUE KEY uq_sr_invoice_context (tenant_id, id, invoice_id),
  UNIQUE KEY uq_sr_customer_context (tenant_id, id, customer_id),
  CONSTRAINT fk_sr_invoice FOREIGN KEY (tenant_id, invoice_id, customer_id)
    REFERENCES invoices(tenant_id, id, customer_id),
  CONSTRAINT fk_sr_customer FOREIGN KEY (tenant_id, customer_id)
    REFERENCES customers(tenant_id, id)
) ENGINE=InnoDB;

CREATE TABLE sales_return_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  sales_return_id BIGINT UNSIGNED NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,
  invoice_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(19,4) NOT NULL,
  refund_amount DECIMAL(19,4) NOT NULL,
  restock BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_sri_tenant_id (tenant_id, id),
  INDEX idx_sri_invoice_item (tenant_id, invoice_item_id),
  CONSTRAINT fk_sri_return FOREIGN KEY (tenant_id, sales_return_id, invoice_id)
    REFERENCES sales_returns(tenant_id, id, invoice_id),
  CONSTRAINT fk_sri_invoice_item FOREIGN KEY (tenant_id, invoice_item_id, invoice_id)
    REFERENCES invoice_items(tenant_id, id, invoice_id),
  CONSTRAINT chk_sri_amount CHECK (quantity > 0 AND refund_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  sales_return_id BIGINT UNSIGNED NULL, -- NULL si es reembolso de crédito no asignado
  original_payment_id BIGINT UNSIGNED NOT NULL,
  refund_kind VARCHAR(25) NOT NULL, -- return | unused_credit
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|confirmed|failed
  amount DECIMAL(19,4) NOT NULL,
  currency CHAR(3) NOT NULL,
  method VARCHAR(30) NOT NULL,
  event_key VARCHAR(160) NOT NULL,
  external_reference VARCHAR(160) NULL,
  refunded_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_ref_tenant_id (tenant_id, id),
  UNIQUE KEY uq_ref_context (tenant_id, id, original_payment_id, customer_id, currency),
  UNIQUE KEY uq_ref_event (tenant_id, event_key),
  CONSTRAINT fk_ref_return FOREIGN KEY (tenant_id, sales_return_id, customer_id)
    REFERENCES sales_returns(tenant_id, id, customer_id),
  CONSTRAINT fk_ref_payment FOREIGN KEY (tenant_id, original_payment_id, customer_id, currency)
    REFERENCES payments(tenant_id, id, customer_id, currency),
  CONSTRAINT chk_ref_amount CHECK (amount > 0),
  CONSTRAINT chk_ref_kind CHECK (
    (refund_kind='return' AND sales_return_id IS NOT NULL) OR
    (refund_kind='unused_credit' AND sales_return_id IS NULL)
  ),
  CONSTRAINT chk_ref_status CHECK (status IN ('pending','confirmed','failed'))
) ENGINE=InnoDB;

-- Libro explícito de saldo a favor: + cobro confirmado, - aplicación, - devolución
-- del crédito sin aplicar. Las devoluciones de ventas NO se descuentan aquí a ciegas.
CREATE TABLE customer_credit_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  payment_allocation_id BIGINT UNSIGNED NULL,
  refund_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(20) NOT NULL,  -- receipt|allocation|unused_refund|reversal
  amount_delta DECIMAL(19,4) NOT NULL,
  event_key VARCHAR(160) NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_ccm_tenant_id (tenant_id, id),
  UNIQUE KEY uq_ccm_event (tenant_id, event_key),
  UNIQUE KEY uq_ccm_allocation (tenant_id, payment_allocation_id),
  UNIQUE KEY uq_ccm_refund (tenant_id, refund_id),
  INDEX idx_ccm_customer (tenant_id, customer_id, currency, occurred_at),
  CONSTRAINT fk_ccm_payment FOREIGN KEY (tenant_id, payment_id, customer_id, currency)
    REFERENCES payments(tenant_id, id, customer_id, currency),
  CONSTRAINT fk_ccm_allocation FOREIGN KEY
    (tenant_id, payment_allocation_id, payment_id, customer_id, currency)
    REFERENCES payment_allocations(tenant_id, id, payment_id, customer_id, currency),
  CONSTRAINT fk_ccm_refund FOREIGN KEY
    (tenant_id, refund_id, payment_id, customer_id, currency)
    REFERENCES refunds(tenant_id, id, original_payment_id, customer_id, currency),
  CONSTRAINT chk_ccm_event CHECK (
    (event_type='receipt' AND amount_delta>0 AND payment_allocation_id IS NULL AND refund_id IS NULL) OR
    (event_type='allocation' AND amount_delta<0 AND payment_allocation_id IS NOT NULL AND refund_id IS NULL) OR
    (event_type='unused_refund' AND amount_delta<0 AND payment_allocation_id IS NULL AND refund_id IS NOT NULL) OR
    (event_type='reversal' AND amount_delta<0 AND payment_allocation_id IS NULL AND refund_id IS NULL)
  )
) ENGINE=InnoDB;

-- 07. Kardex: movimientos de existencias y reservas (solo anexar)
CREATE TABLE inventory_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  reservation_id BIGINT UNSIGNED NULL,
  return_item_id BIGINT UNSIGNED NULL,
  movement_type VARCHAR(32) NOT NULL,
  on_hand_delta DECIMAL(19,4) NOT NULL DEFAULT 0,
  reserved_delta DECIMAL(19,4) NOT NULL DEFAULT 0,
  event_key VARCHAR(160) NOT NULL,
  note VARCHAR(255) NULL,
  performed_by_membership_id BIGINT UNSIGNED NULL,
  occurred_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_im_tenant_id (tenant_id, id),
  UNIQUE KEY uq_im_event (tenant_id, event_key),
  INDEX idx_im_variant_date (tenant_id, variant_id, occurred_at),
  CONSTRAINT fk_im_balance FOREIGN KEY (tenant_id, warehouse_id, variant_id)
    REFERENCES stock_balances(tenant_id, warehouse_id, variant_id),
  CONSTRAINT fk_im_res FOREIGN KEY (tenant_id, reservation_id, warehouse_id, variant_id)
    REFERENCES stock_reservations(tenant_id, id, warehouse_id, variant_id),
  CONSTRAINT fk_im_return FOREIGN KEY (tenant_id, return_item_id)
    REFERENCES sales_return_items(tenant_id, id),
  CONSTRAINT fk_im_actor FOREIGN KEY (tenant_id, performed_by_membership_id)
    REFERENCES tenant_memberships(tenant_id, id),
  CONSTRAINT chk_im_not_zero CHECK (on_hand_delta <> 0 OR reserved_delta <> 0)
) ENGINE=InnoDB;

-- 08. Comisiones, nómina y pagos de vendedores
CREATE TABLE commission_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  compensation_plan_id BIGINT UNSIGNED NOT NULL,
  invoice_item_id BIGINT UNSIGNED NOT NULL,
  payment_allocation_id BIGINT UNSIGNED NULL,
  refund_id BIGINT UNSIGNED NULL,
  entry_type VARCHAR(20) NOT NULL, -- earn | reversal | adjustment
  commission_base DECIMAL(19,4) NOT NULL,
  commission_rate DECIMAL(9,4) NOT NULL,
  amount DECIMAL(19,4) NOT NULL, -- positivo: a favor, negativo: reversión
  currency CHAR(3) NOT NULL,
  event_key VARCHAR(160) NOT NULL,
  earned_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_ce_tenant_id (tenant_id, id),
  UNIQUE KEY uq_ce_payroll_context (tenant_id, id, employee_id, currency),
  UNIQUE KEY uq_ce_event (tenant_id, event_key),
  INDEX idx_ce_employee_date (tenant_id, employee_id, earned_at),
  CONSTRAINT fk_ce_employee FOREIGN KEY (tenant_id, employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT fk_ce_plan FOREIGN KEY (tenant_id, compensation_plan_id, employee_id, currency)
    REFERENCES employee_compensation_plans(tenant_id, id, employee_id, currency),
  CONSTRAINT fk_ce_item FOREIGN KEY (tenant_id, invoice_item_id)
    REFERENCES invoice_items(tenant_id, id),
  CONSTRAINT fk_ce_pa FOREIGN KEY (tenant_id, payment_allocation_id)
    REFERENCES payment_allocations(tenant_id, id),
  CONSTRAINT fk_ce_refund FOREIGN KEY (tenant_id, refund_id)
    REFERENCES refunds(tenant_id, id),
  CONSTRAINT chk_ce_type CHECK (entry_type IN ('earn','reversal','adjustment')),
  CONSTRAINT chk_ce_rate CHECK (commission_rate BETWEEN 0 AND 100),
  CONSTRAINT chk_ce_sign CHECK (
    (entry_type='earn' AND amount >= 0) OR
    (entry_type='reversal' AND amount <= 0) OR
    (entry_type='adjustment')
  )
) ENGINE=InnoDB;

CREATE TABLE payroll_periods (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  store_id BIGINT UNSIGNED NULL,
  scope_key VARCHAR(80) NOT NULL DEFAULT 'company',
  currency CHAR(3) NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft | approved | paid
  approved_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_pp_tenant_id (tenant_id, id),
  UNIQUE KEY uq_pp_scope (tenant_id, scope_key, starts_on, ends_on),
  CONSTRAINT fk_pp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_pp_store FOREIGN KEY (tenant_id, store_id)
    REFERENCES stores(tenant_id, id),
  CONSTRAINT chk_pp_dates CHECK (ends_on >= starts_on),
  CONSTRAINT chk_pp_status CHECK (status IN ('draft','approved','paid'))
) ENGINE=InnoDB;

CREATE TABLE payroll_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  payroll_period_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  compensation_type_snapshot VARCHAR(20) NOT NULL,
  currency CHAR(3) NOT NULL,
  salary_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(19,4) NOT NULL DEFAULT 0,
  adjustments DECIMAL(19,4) NOT NULL DEFAULT 0,
  deductions DECIMAL(19,4) NOT NULL DEFAULT 0,
  total_payable DECIMAL(19,4) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_pl_tenant_id (tenant_id, id),
  UNIQUE KEY uq_pl_payroll_context (tenant_id, id, employee_id, currency),
  UNIQUE KEY uq_pl_employee_period (tenant_id, payroll_period_id, employee_id),
  CONSTRAINT fk_pl_period FOREIGN KEY (tenant_id, payroll_period_id)
    REFERENCES payroll_periods(tenant_id, id),
  CONSTRAINT fk_pl_employee FOREIGN KEY (tenant_id, employee_id)
    REFERENCES employees(tenant_id, id),
  CONSTRAINT chk_pl_deductions CHECK (
    salary_amount >= 0 AND deductions >= 0 AND total_payable >= 0
  )
) ENGINE=InnoDB;

-- Cada comisión se consume una sola vez en una liquidación; no duplicar pagos.
CREATE TABLE payroll_commission_entries (
  tenant_id BIGINT UNSIGNED NOT NULL,
  payroll_line_id BIGINT UNSIGNED NOT NULL,
  commission_entry_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, commission_entry_id),
  INDEX idx_pce_line (tenant_id, payroll_line_id),
  CONSTRAINT fk_pce_line FOREIGN KEY (tenant_id, payroll_line_id, employee_id, currency)
    REFERENCES payroll_lines(tenant_id, id, employee_id, currency),
  CONSTRAINT fk_pce_entry FOREIGN KEY (tenant_id, commission_entry_id, employee_id, currency)
    REFERENCES commission_entries(tenant_id, id, employee_id, currency)
) ENGINE=InnoDB;

CREATE TABLE payroll_disbursements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  payroll_line_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(19,4) NOT NULL,
  currency CHAR(3) NOT NULL,
  payment_method VARCHAR(30) NOT NULL,
  reference VARCHAR(160) NULL,
  event_key VARCHAR(160) NOT NULL,
  paid_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_pd_tenant_id (tenant_id, id),
  INDEX idx_pd_line (tenant_id, payroll_line_id),
  UNIQUE KEY uq_pd_event (tenant_id, event_key),
  CONSTRAINT fk_pd_line FOREIGN KEY (tenant_id, payroll_line_id, employee_id, currency)
    REFERENCES payroll_lines(tenant_id, id, employee_id, currency),
  CONSTRAINT chk_pd_amount CHECK (amount > 0)
) ENGINE=InnoDB;

-- 09. Robustez SaaS: idempotencia y auditoría
CREATE TABLE idempotency_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  operation VARCHAR(80) NOT NULL,
  request_key VARCHAR(160) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'processing',
  resource_type VARCHAR(60) NULL,
  resource_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NULL,
  completed_at DATETIME(6) NULL,
  UNIQUE KEY uq_ir_tenant_id (tenant_id, id),
  UNIQUE KEY uq_ir_request (tenant_id, operation, request_key),
  CONSTRAINT fk_ir_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  event VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  old_data JSON NULL,
  new_data JSON NULL,
  ip_address VARCHAR(45) NULL,
  occurred_at DATETIME(6) NOT NULL,
  INDEX idx_audit_tenant_date (tenant_id, occurred_at),
  INDEX idx_audit_entity (tenant_id, entity_type, entity_id),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_audit_member FOREIGN KEY (tenant_id, membership_id)
    REFERENCES tenant_memberships(tenant_id, id)
) ENGINE=InnoDB;


-- 10. Comercialización de KeylaShop a múltiples negocios
CREATE TABLE saas_plans (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(45) NOT NULL,
  name VARCHAR(120) NOT NULL,
  billing_cycle VARCHAR(20) NOT NULL, -- monthly | yearly | lifetime
  price DECIMAL(19,4) NOT NULL,
  currency CHAR(3) NOT NULL,
  max_stores SMALLINT UNSIGNED NULL,
  max_users SMALLINT UNSIGNED NULL,
  features JSON NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_saas_plan_code (code),
  CONSTRAINT chk_saas_price CHECK (price >= 0),
  CONSTRAINT chk_saas_cycle CHECK (billing_cycle IN ('monthly','yearly','lifetime'))
) ENGINE=InnoDB;

CREATE TABLE tenant_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  saas_plan_id BIGINT UNSIGNED NOT NULL,
  plan_name_snapshot VARCHAR(120) NULL,
  amount_snapshot DECIMAL(19,4) NULL,
  currency_snapshot CHAR(3) NULL,
  billing_cycle_snapshot VARCHAR(20) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'trial',
  started_at DATETIME(6) NOT NULL,
  renews_at DATETIME(6) NULL,
  ends_at DATETIME(6) NULL,
  external_reference VARCHAR(160) NULL,
  created_at DATETIME(6) NULL,
  updated_at DATETIME(6) NULL,
  UNIQUE KEY uq_sub_tenant_id (tenant_id, id),
  INDEX idx_sub_current (tenant_id, status, renews_at),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_sub_plan FOREIGN KEY (saas_plan_id) REFERENCES saas_plans(id)
) ENGINE=InnoDB;

-- Eventos transaccionales para trabajos de cola (no enviar notificaciones antes de commit).
CREATE TABLE outbox_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_key VARCHAR(160) NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  aggregate_type VARCHAR(100) NOT NULL,
  aggregate_id BIGINT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME(6) NOT NULL,
  processed_at DATETIME(6) NULL,
  created_at DATETIME(6) NULL,
  UNIQUE KEY uq_outbox_event (tenant_id, event_key),
  INDEX idx_outbox_dispatch (status, available_at),
  CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT chk_outbox_status CHECK (status IN ('pending','processing','done','failed'))
) ENGINE=InnoDB;

-- Reglas intertabla no expresables mediante FK/CHECK: implementar en servicios de dominio:
-- (1) shop/store/customer deben corresponder a la misma venta/cuenta/factura;
-- (2) la variante debe corresponder al producto y variante de cada imagen o movimiento;
-- (3) plan de pago del vendedor vigente y sin solapamientos;
-- (4) pagos asignados <= pago confirmado; facturas no cobradas por encima del saldo;
-- (5) devoluciones <= unidades y montos facturados; no devolver 2 veces;
-- (6) suma entradas de nómina no liquidada no puede liquidarse dos veces;
-- (7) modificar on_hand y reserved SOLO en servicio con DB::transaction + FOR UPDATE;
-- (8) no crear comisiones al apartar; liquidar después de pago realmente aplicado;
-- (9) no utilizar como borrado físico la eliminación de transacciones contables.
-- (10) contra crédito del cliente: sumar customer_credit_movements por pago, nunca negativo;
--      lock de payments para asignación concurrente y reembolsos.
-- (11) media: validar bytes reales, dimensionar/recodificar, hacer ready y asociar;
--      nunca servir staging_key ni incluir objetos privados en catálogo público.
-- (12) eliminar referencias a imágenes por BD antes de limpieza de archivos en tarea de fondo;
--      retener originales según política y respaldar fuera del directorio de publicación.
-- (13) invoice source exactamente weekly_account XOR order; misma sucursal/cliente en FKs.
-- (14) payroll payables netos y pagos parciales: impedir excedente por lock.
-- (15) atributos de variantes y combination_key calculados canónicamente en servicio.
-- (16) pagos vencidos/refund duplicados: webhook idempotente, evento único.
-- (17) captura de pedidos público: anti-abuso, token hash, validaciones por tenant.
-- (18) prohibido guardar credenciales o archivos en SQL o local público.
-- (19) tiempos de reserva y abonos sujetos a reglas configuradas por tenant.
-- (20) suma de reservas activas debe corresponder con stock_balances.reserved.

-- (21) almacenamiento por defecto local con separación de directorios staging/privado/público;
--      rutas relativas y nombre aleatorio por archivo; NUNCA aceptar rutas suministradas por el usuario.
-- (22) cuota de espacio se reserva con SELECT FOR UPDATE sobre tenant_storage_quotas
--      antes de aceptar subida; confirmar bytes_ready y liberar pending al finalizar/fallar.
-- (23) versiones thumb/card/detail se generan en el VPS con GD/Imagick;
--      nunca dependen de Cloudflare Images ni R2 ni de transformaciones de pago.
-- (24) almacenar originales de forma temporal, verificar bytes reales y recodificar a WebP
--      sin EXIF; publicar rendiciones con clave inmutable y cabeceras cache-control.
-- (25) para Windows Server/Apache, servir únicamente carpeta pública controlada;
--      staging fuera de DocumentRoot; no confiar en enlaces simbólicos sin verificar.
-- (26) cuotas y bytes_ready no eliminan la necesidad de backups fuera del disco principal,
--      monitoreo de capacidad y prueba de restauración antes de ir a producción.

-- v3: máximo cinco imágenes por producto se valida con transacción y bloqueo de products.
-- v3: si se adopta subida vía Laravel, staging_provider='local' y provider='r2'.
-- v3: links temporales se marcan usados sólo tras commit exitoso de orden.
-- v3: facturas semanales no pueden incluir órdenes facturadas individualmente.
-- v3: contador bytes_ready = suma de claves de objetos físicos distintas, no de vínculos a productos.
-- v3: cuota y conexión no garantizan gratuidad permanente del proveedor R2.
