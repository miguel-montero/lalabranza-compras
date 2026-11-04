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
}
export interface Settings {
  iva_rate: number
  alert_threshold_pct: number
}
