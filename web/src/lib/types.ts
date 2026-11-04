import type { BaseUnit, ComparisonStatus, DocType, EntryMode, Unit } from './calc'

export interface Supplier { id: number; name: string; notes: string | null; active: boolean }
export interface Category { id: number; name: string }
export interface Alias { id: number; alias: string }
export interface Product {
  id: number
  name: string
  base_unit: BaseUnit
  category_id: number | null
  category_name: string | null
  active: boolean
  notes: string | null
  aliases: Alias[]
  matched_alias?: string
}
export interface Baseline {
  normalized_price: number
  supplier_name: string
  purchase_date: string
  receipt_id: number
}
export interface ItemComparison {
  status: ComparisonStatus
  diff: number | null
  pct: number | null
  flagged: boolean
  ref: { normalized_price: number; supplier_name: string; purchase_date: string } | null
}
export interface ReceiptItemView {
  id: number
  line_order: number
  product_id: number
  product: { id: number; name: string; base_unit: BaseUnit }
  entry_mode: EntryMode
  quantity: number
  entry_unit: Unit
  package_count: number | null
  package_content: number | null
  package_unit: Unit | null
  unit_price: number | null
  entered_amount: number
  adjustment_share: number
  iva_exempt: boolean
  real_cost: number
  amount_paid: number
  base_quantity: number | null
  normalized_price: number | null
  notes: string | null
  comparison?: ItemComparison
}
export interface ReceiptView {
  id: number
  supplier: { id: number; name: string }
  purchase_date: string | null
  date_unknown: boolean
  doc_type: DocType
  receipt_number: string | null
  notes: string | null
  status: 'active' | 'void'
  adjustment_amount: number
  delivery_amount: number
  printed_total: number | null
  iva_rate: number
  totals: { neto: number; exento: number; iva: number; total: number }
  printed_total_mismatch: boolean
  items: ReceiptItemView[]
  audit?: AuditEntry[]
}
export interface Settings {
  iva_rate: number
  alert_threshold_pct: number
}

export interface PriceRef {
  normalized_price: number
  supplier_name: string
  purchase_date: string
}
export interface ProductStats {
  latest: PriceRef | null
  lowest_recent: PriceRef | null
  purchase_count: number
  recent_days: number
}
export interface SupplierPrice {
  supplier_name: string
  latest_normalized_price: number
  purchase_date: string
  count_in_window: number
}
export interface ProductDetail {
  product: Product
  aliases: Alias[]
  stats: ProductStats
  by_supplier: SupplierPrice[]
  series: { purchase_date: string | null; normalized_price: number; supplier_name: string; doc_type: DocType }[]
}
export interface LookupItem extends Product {
  stats: ProductStats
  by_supplier: SupplierPrice[]
}
export interface HistoryRow {
  receipt_id: number
  purchase_date: string | null
  date_unknown: boolean
  supplier_name: string
  product_id: number
  product_name: string
  line_order: number
  category_name: string | null
  doc_type: DocType
  receipt_number: string | null
  quantity: number
  entry_unit: Unit
  package_count: number | null
  package_content: number | null
  package_unit: Unit | null
  base_quantity: number | null
  base_unit: BaseUnit
  entered_amount: number
  amount_paid: number
  real_cost: number
  normalized_price: number | null
  comparison: ItemComparison
}
export interface SpendEntry { name: string; total_paid: number; total_real: number }
export interface DashboardData {
  range: { from: string; to: string }
  recent_receipts: { id: number; purchase_date: string | null; supplier_name: string; doc_type: DocType; receipt_number: string | null; total: number; status: string; flagged_count: number }[]
  flagged: HistoryRow[]
  spend: { total_paid: number; total_real: number; by_category: SpendEntry[]; by_supplier: SpendEntry[] }
}
export interface AuditEntry {
  action: 'create' | 'update' | 'void'
  created_at: string
  reason: string | null
  before: ReceiptView | null
  after: ReceiptView | null
}
