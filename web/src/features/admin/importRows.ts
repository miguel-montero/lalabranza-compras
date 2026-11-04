import { roundHalfAway, type BaseUnit, type DocType, type Unit } from '@/lib/calc'

export type RawRow = Record<string, unknown>

export interface ImportRow {
  date: string | null
  supplier: string | null
  product: string | null
  category: string | null
  quantity: number | null
  unit: Unit | null
  unit_price: number | null
  total: number | null
  receipt_number: string | null
}

/** A preview row: the parsed spreadsheet row plus the server's flags and the user's resolutions. */
export interface EditRow extends ImportRow {
  index: number
  serverFlags: string[]
  supplierId: number | null
  supplierName: string | null
  productId: number | null
  productName: string | null
  productBaseUnit: BaseUnit | null
  docType: DocType
  priceMode: 'unit' | 'total' | null
  dateUnknown: boolean
  acceptNoNumber: boolean
  acceptDuplicate: boolean
  swapReviewed: boolean
  selected: boolean
}

function key(s: string): string {
  return s
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, '')
}

const HEADERS: Record<string, keyof ImportRow> = {
  fecha: 'date',
  lugarproveedor: 'supplier',
  pruducto: 'product',
  producto: 'product',
  categoria: 'category',
  cantidad: 'quantity',
  unidad: 'unit',
  preciounitario: 'unit_price',
  totaldecompra: 'total',
  numerodeboleta: 'receipt_number',
}

const UNITS: Record<string, Unit> = {
  gr: 'g', g: 'g', grs: 'g',
  kg: 'kg', kilo: 'kg', kilos: 'kg',
  l: 'L', lt: 'L', litro: 'L',
  ml: 'mL', cc: 'mL',
  unidad: 'unidad', un: 'unidad', u: 'unidad', und: 'unidad',
}

export function normalizeUnit(v: unknown): Unit | null {
  if (typeof v !== 'string') return null
  return UNITS[v.trim().toLowerCase()] ?? null
}

const pad = (n: number) => String(n).padStart(2, '0')

/** Excel serial day number (epoch 1899-12-30) to YYYY-MM-DD. */
export function excelSerialToISO(n: number): string {
  const d = new Date(Date.UTC(1899, 11, 30) + Math.floor(n) * 86400000)
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`
}

function toDate(v: unknown): string | null {
  if (v instanceof Date && !isNaN(v.getTime())) return `${v.getUTCFullYear()}-${pad(v.getUTCMonth() + 1)}-${pad(v.getUTCDate())}`
  if (typeof v === 'number' && isFinite(v) && v > 0) return excelSerialToISO(v)
  if (typeof v !== 'string') return null
  const t = v.trim()
  let m = /^(\d{4})-(\d{1,2})-(\d{1,2})/.exec(t)
  if (m) return `${m[1]}-${pad(+m[2])}-${pad(+m[3])}`
  m = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2}|\d{4})$/.exec(t)
  if (m) return `${m[3].length === 2 ? '20' + m[3] : m[3]}-${pad(+m[2])}-${pad(+m[1])}`
  if (/^\d{5}$/.test(t)) return excelSerialToISO(Number(t))
  return null
}

function toText(v: unknown): string | null {
  if (v === null || v === undefined) return null
  const s = String(v).trim()
  return s === '' ? null : s
}

/** "1.300", "$1.300", 1300 -> 1300; "1,5" -> 1.5 when decimals allowed. */
function toNumber(v: unknown, money: boolean): number | null {
  if (typeof v === 'number') return isFinite(v) ? v : null
  const s = toText(v)
  if (s === null) return null
  const t = s.replace(/[$\s]/g, '')
  if (money) {
    if (/^\d{1,3}(\.\d{3})+$/.test(t)) return Number(t.replace(/\./g, ''))
    if (/^\d+([.,]\d+)?$/.test(t)) return Number(t.replace(',', '.'))
    return null
  }
  return /^\d+([.,]\d+)?$/.test(t) ? Number(t.replace(',', '.')) : null
}

/** Maps spreadsheet header names (case/accent/space-insensitive) to ImportRow fields. Empty rows are dropped. */
export function mapHeaders(rows: RawRow[]): ImportRow[] {
  const out: ImportRow[] = []
  for (const raw of rows) {
    const r: ImportRow = {
      date: null, supplier: null, product: null, category: null, quantity: null,
      unit: null, unit_price: null, total: null, receipt_number: null,
    }
    let any = false
    for (const [h, v] of Object.entries(raw)) {
      const field = HEADERS[key(h)]
      if (!field) continue
      if (toText(v) !== null || v instanceof Date) any = true
      switch (field) {
        case 'date': r.date = toDate(v); break
        case 'unit': r.unit = normalizeUnit(v); break
        case 'quantity': r.quantity = toNumber(v, false); break
        case 'unit_price': r.unit_price = toNumber(v, true); break
        case 'total': r.total = toNumber(v, true); break
        default: r[field] = toText(v)
      }
    }
    if (any) out.push(r)
  }
  return out
}

/** Hand-rolled CSV parser: quotes, "" escapes, `;` or `,` auto-detected from the header line. */
export function parseCsv(text: string): RawRow[] {
  const src = text.replace(/^﻿/, '')
  const firstLine = src.split(/\r?\n/, 1)[0] ?? ''
  const sep = (firstLine.match(/;/g)?.length ?? 0) >= (firstLine.match(/,/g)?.length ?? 0) && firstLine.includes(';') ? ';' : ','
  const table: string[][] = []
  let row: string[] = []
  let cell = ''
  let quoted = false
  for (let i = 0; i < src.length; i++) {
    const c = src[i]
    if (quoted) {
      if (c === '"') {
        if (src[i + 1] === '"') { cell += '"'; i++ } else quoted = false
      } else cell += c
    } else if (c === '"') quoted = true
    else if (c === sep) { row.push(cell); cell = '' }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && src[i + 1] === '\n') i++
      row.push(cell); cell = ''
      table.push(row); row = []
    } else cell += c
  }
  if (cell !== '' || row.length) { row.push(cell); table.push(row) }
  const rows = table.filter((r) => r.some((c) => c.trim() !== ''))
  if (!rows.length) return []
  const headers = rows[0].map((h) => h.trim())
  return rows.slice(1).map((r) => Object.fromEntries(headers.map((h, i) => [h, r[i] ?? ''])))
}

/** Reads the first sheet ("Hoja 1" when present) of an .xlsx, or a .csv, into header-keyed rows. */
export async function readSheet(file: File): Promise<RawRow[]> {
  if (/\.csv$/i.test(file.name) || file.type === 'text/csv') return parseCsv(await file.text())
  const { readSheet: read, readSheetNames } = (await import('read-excel-file/browser')) as unknown as {
    readSheet: (f: File, sheet?: number | string) => Promise<unknown[][]>
    readSheetNames?: (f: File) => Promise<string[]>
  }
  let data: unknown[][]
  try {
    data = await read(file, 'Hoja 1')
  } catch {
    void readSheetNames
    data = await read(file)
  }
  if (!data.length) return []
  const headers = data[0].map((h) => String(h ?? '').trim())
  return data.slice(1).map((r) => Object.fromEntries(headers.map((h, i) => [h, r[i] ?? null])))
}

/** Flags still blocking the import given the user's resolutions. */
export function activeFlags(r: EditRow): string[] {
  const f: string[] = []
  if (!r.date && !r.dateUnknown) f.push('missing_date')
  if (!r.unit) f.push('missing_unit')
  if (r.total === null && r.unit_price === null) f.push('missing_total')
  if (!r.receipt_number && !r.acceptNoNumber) f.push('missing_receipt_number')
  if (!r.supplierId) f.push('unknown_supplier')
  if (!r.productId) f.push('unknown_product')
  if (r.serverFlags.includes('duplicate_receipt_number') && !r.acceptDuplicate) f.push('duplicate_receipt_number')
  if (r.serverFlags.includes('possible_swap') && !r.swapReviewed) f.push('possible_swap')
  if (r.unit_price !== null && r.total === null && r.quantity !== 1 && r.priceMode === null) f.push('ambiguous_price')
  if (!(r.quantity !== null && r.quantity > 0)) f.push('invalid_quantity')
  return f
}

/** Amount paid for the line (IVA-included), or null when it cannot be determined. */
export function effectiveAmount(r: EditRow): number | null {
  if (r.total !== null && r.priceMode !== 'unit') return roundHalfAway(r.total)
  if (r.unit_price === null) return r.total === null ? null : roundHalfAway(r.total)
  if (r.priceMode === 'unit' || (r.priceMode === null && r.quantity === 1)) return roundHalfAway(r.unit_price * (r.quantity ?? 1))
  if (r.priceMode === 'total') return roundHalfAway(r.unit_price)
  return null
}
