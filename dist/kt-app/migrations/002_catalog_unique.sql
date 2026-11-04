-- Supplier and product names are unique (utf8mb4_unicode_ci: case- and accent-insensitive).
ALTER TABLE suppliers DROP INDEX idx_suppliers_name, ADD UNIQUE KEY uq_suppliers_name (name);
ALTER TABLE products DROP INDEX idx_products_name, ADD UNIQUE KEY uq_products_name (name);
