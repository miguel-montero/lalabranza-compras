-- Quantities keep 6 decimals so small entries (e.g. 0.125 g = 0.000125 kg) are stored without loss.
-- The integer part stays at 10 digits, as before. normalized_price is unchanged (DECIMAL(14,4)).
ALTER TABLE receipt_items
  MODIFY quantity DECIMAL(16,6) NOT NULL,
  MODIFY package_count DECIMAL(16,6) NULL,
  MODIFY package_content DECIMAL(16,6) NULL,
  MODIFY base_quantity DECIMAL(16,6) NULL;
