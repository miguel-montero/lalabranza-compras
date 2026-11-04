-- Kitchen price tracker: initial schema (InnoDB, utf8mb4; MySQL 5.7+ / MariaDB 10.3+)

CREATE TABLE suppliers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  notes TEXT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  category_id INT UNSIGNED NULL,
  base_unit ENUM('kg','L','unidad') NOT NULL,
  active TINYINT NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_products_name (name),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_aliases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  alias VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_product_aliases_alias (alias),
  CONSTRAINT fk_aliases_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  purchase_date DATE NULL,
  date_unknown TINYINT NOT NULL DEFAULT 0,
  doc_type ENUM('boleta','factura') NOT NULL DEFAULT 'boleta',
  receipt_number VARCHAR(60) NULL,
  iva_rate DECIMAL(5,4) NOT NULL,
  adjustment_amount INT NOT NULL DEFAULT 0,
  delivery_amount INT NOT NULL DEFAULT 0,
  printed_total INT NULL,
  notes TEXT NULL,
  status ENUM('active','void') NOT NULL DEFAULT 'active',
  void_reason VARCHAR(255) NULL,
  idempotency_key CHAR(36) NULL,
  source ENUM('manual','import') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipts_idempotency (idempotency_key),
  KEY idx_receipts_date_id (purchase_date, id),
  KEY idx_receipts_supplier_number (supplier_id, receipt_number),
  CONSTRAINT fk_receipts_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipt_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT UNSIGNED NOT NULL,
  line_order SMALLINT NOT NULL DEFAULT 0,
  product_id INT UNSIGNED NOT NULL,
  entry_mode ENUM('total','unit_price') NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  entry_unit ENUM('kg','g','L','mL','unidad') NOT NULL,
  package_count DECIMAL(14,4) NULL,
  package_content DECIMAL(14,4) NULL,
  package_unit ENUM('kg','g','L','mL','unidad') NULL,
  unit_price INT NULL,
  entered_amount INT NOT NULL,
  adjustment_share INT NOT NULL DEFAULT 0,
  iva_exempt TINYINT NOT NULL DEFAULT 0,
  real_cost INT NOT NULL,
  amount_paid INT NOT NULL,
  base_quantity DECIMAL(14,4) NULL,
  normalized_price DECIMAL(14,4) NULL,
  notes TEXT NULL,
  KEY idx_items_product (product_id),
  KEY idx_items_receipt (receipt_id),
  CONSTRAINT fk_items_receipt FOREIGN KEY (receipt_id) REFERENCES receipts (id),
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  action ENUM('create','update','void') NOT NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  k VARCHAR(50) NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (k, v) VALUES
  ('iva_rate', '0.19'),
  ('alert_threshold_pct', '10'),
  ('recent_days', '90'),
  ('timezone', 'America/Santiago');

CREATE TABLE app_user (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_attempts (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
