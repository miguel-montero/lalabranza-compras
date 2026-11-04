import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQueries, useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import {
  change,
  computeReceipt,
  type BaseUnit,
  type Comparison,
  type DocType,
  type EntryMode,
  type LineInput,
  type ReceiptInputLine,
  type ReceiptTotals,
  type Unit,
} from '@/lib/calc'
import { parseCLP, parseDecimal } from '@/lib/parse'
import { todaySantiago } from '@/lib/santiago'
import type { Baseline, ReceiptView } from '@/lib/types'

export const NEW_DRAFT_KEY = 'kt:draft:new'

export interface DraftProduct {
  id: number
  name: string
  base_unit: BaseUnit
}

/** Form state. Numeric fields are kept as the text the user typed. */
export interface DraftLine {
  key: string
  product: DraftProduct | null
  quantity: string
  unit: Unit
  packaged: boolean
  package_count: string
  package_content: string
  package_unit: Unit
  entry_mode: EntryMode
  /** Total (entry_mode=total) or unit price (entry_mode=unit_price), whole pesos. */
  amount: string
  iva_exempt: boolean
  notes: string
}

export interface Draft {
  idempotency_key: string
  /** YYYY-MM-DD; '' only for an undated imported receipt being edited (sent as null, stays "sin fecha"). */
  purchase_date: string
  /** Edit mode: the stored receipt is undated (imported "sin fecha"). */
  date_unknown?: boolean
  supplier: { id: number; name: string } | null
  doc_type: DocType
  receipt_number: string
  notes: string
  /** Signed whole pesos; negative = discount. */
  adjustment: string
  delivery: string
  printed_total: string
  lines: DraftLine[]
}

export interface LineComputed {
  baseQty: number | null
  enteredAmount: number
  realCost: number
  amountPaid: number
  normalizedPrice: number | null
  comparison: Comparison
  baseline: Baseline | null
  /** Unit price (per package when packaged) derived from the total, for display. */
  derivedUnitPrice: number | null
}

export interface Computed {
  lines: LineComputed[]
  totals: ReceiptTotals
  ivaRate: number
  thresholdPct: number
  printedTotal: number | null
  mismatch: boolean
  adjustmentTooLarge: boolean
}

export { compatibleUnits, contentUnits } from '@/lib/calc'

/** True when a kg/L product is entered in `unidad` without net content (saved, not comparable). */
export function isUnitWithoutContent(base: BaseUnit | null | undefined, l: Pick<DraftLine, 'unit' | 'packaged'>): boolean {
  return !!base && base !== 'unidad' && !l.packaged && l.unit === 'unidad'
}

export function newLine(): DraftLine {
  return {
    key: crypto.randomUUID(),
    product: null,
    quantity: '',
    unit: 'kg',
    packaged: false,
    package_count: '',
    package_content: '',
    package_unit: 'kg',
    entry_mode: 'total',
    amount: '',
    iva_exempt: false,
    notes: '',
  }
}

export function newDraft(): Draft {
  return {
    idempotency_key: crypto.randomUUID(),
    purchase_date: todaySantiago(),
    supplier: null,
    doc_type: 'boleta',
    receipt_number: '',
    notes: '',
    adjustment: '',
    delivery: '',
    printed_total: '',
    lines: [newLine()],
  }
}

/** Parses a signed whole-peso amount ("-500", "$-1.200"); null when blank/invalid. */
export function parseSignedCLP(s: string): number | null {
  const t = s.trim()
  if (t.startsWith('-')) {
    const v = parseCLP(t.slice(1))
    return v === null ? null : -v
  }
  return parseCLP(t)
}

/** True when the user has typed nothing in the draft (its date alone is not content). */
export function isPristine(d: Draft): boolean {
  const header = [d.receipt_number, d.notes, d.adjustment, d.delivery, d.printed_total]
  return (
    !d.supplier &&
    header.every((v) => !(v ?? '').trim()) &&
    d.lines.every((l) => !l.product && !l.amount.trim() && !l.quantity.trim() && !l.package_count.trim() && !l.package_content.trim())
  )
}

function load(storageKey: string): Draft | null {
  try {
    const raw = localStorage.getItem(storageKey)
    if (!raw) return null
    const d = JSON.parse(raw) as Draft
    if (!d || typeof d !== 'object' || !Array.isArray(d.lines) || !d.idempotency_key) return null
    return d
  } catch {
    return null
  }
}

/** Builds a draft from an existing receipt (used by the edit screen, Task 9). */
export function draftFromReceipt(r: ReceiptView): Draft {
  return {
    idempotency_key: crypto.randomUUID(),
    // An undated imported receipt stays undated unless the user picks a date.
    purchase_date: r.purchase_date ?? '',
    date_unknown: r.date_unknown && !r.purchase_date,
    supplier: r.supplier,
    doc_type: r.doc_type,
    receipt_number: r.receipt_number ?? '',
    notes: r.notes ?? '',
    adjustment: r.adjustment_amount ? String(r.adjustment_amount) : '',
    delivery: r.delivery_amount ? String(r.delivery_amount) : '',
    printed_total: r.printed_total != null ? String(r.printed_total) : '',
    lines: r.items.map((i) => ({
      key: crypto.randomUUID(),
      product: i.product,
      quantity: String(i.quantity).replace('.', ','),
      unit: i.entry_unit,
      packaged: i.package_count != null,
      package_count: i.package_count != null ? String(i.package_count) : '',
      package_content: i.package_content != null ? String(i.package_content).replace('.', ',') : '',
      package_unit: i.package_unit ?? i.entry_unit,
      entry_mode: i.entry_mode,
      amount: String(i.entry_mode === 'unit_price' ? (i.unit_price ?? '') : i.entered_amount),
      iva_exempt: i.iva_exempt,
      notes: i.notes ?? '',
    })),
  }
}

function lineInput(l: DraftLine): LineInput {
  const amount = parseCLP(l.amount) ?? 0
  const count = l.packaged ? parseDecimal(l.package_count) : null
  return {
    entry_mode: l.entry_mode,
    entered_amount: l.entry_mode === 'total' ? amount : null,
    unit_price: l.entry_mode === 'unit_price' ? amount : null,
    quantity: l.packaged ? count : parseDecimal(l.quantity),
    entry_unit: l.packaged ? l.package_unit : l.unit,
    package_count: l.packaged ? count : null,
    package_content: l.packaged ? parseDecimal(l.package_content) : null,
    package_unit: l.packaged ? l.package_unit : null,
  }
}

/** The JSON body for POST/PUT /receipts. Blank lines are skipped; `indexMap[i]` = draft line index of payload item i. */
export function buildPayload(d: Draft, opts: { confirmDuplicate?: boolean } = {}) {
  const indexMap: number[] = []
  const items: Record<string, unknown>[] = []
  d.lines.forEach((l, idx) => {
    if (!l.product && !l.amount.trim() && !l.quantity.trim() && !l.package_count.trim()) return
    indexMap.push(idx)
    const li = lineInput(l)
    items.push({
      product_id: l.product?.id ?? null,
      entry_mode: l.entry_mode,
      quantity: li.quantity,
      entry_unit: li.entry_unit,
      package_count: li.package_count,
      package_content: li.package_content,
      package_unit: li.package_unit,
      entered_amount: li.entered_amount,
      unit_price: li.unit_price,
      iva_exempt: d.doc_type === 'factura' ? l.iva_exempt : false,
      notes: l.notes.trim() || null,
    })
  })
  const payload: Record<string, unknown> = {
    idempotency_key: d.idempotency_key,
    supplier_id: d.supplier?.id ?? null,
    purchase_date: d.purchase_date || null,
    doc_type: d.doc_type,
    receipt_number: d.receipt_number.trim() || null,
    notes: d.notes.trim() || null,
    adjustment_amount: parseSignedCLP(d.adjustment) ?? 0,
    delivery_amount: parseCLP(d.delivery) ?? 0,
    printed_total: parseCLP(d.printed_total),
    items,
  }
  if (opts.confirmDuplicate) payload.confirm_duplicate = true
  return { payload, indexMap }
}

export interface UseReceiptDraftOptions {
  storageKey?: string
  initial?: Draft
  /** Edit mode: baselines use the saved-line key (draft date, this receipt id) and exclude this receipt entirely. */
  receiptId?: number
  /** Edit mode: the receipt's own IVA snapshot (PUT keeps it), instead of the current setting. */
  ivaRate?: number
}

export function useReceiptDraft(opts: UseReceiptDraftOptions = {}) {
  const storageKey = opts.storageKey ?? NEW_DRAFT_KEY
  const [init] = useState(() => {
    const stored = load(storageKey)
    if (!stored) return { draft: opts.initial ?? newDraft(), stale: null as string | null }
    const today = todaySantiago()
    // A new-purchase draft left from another day: an untouched one simply takes today's date; one with
    // content keeps its date but the form shows "Borrador del dd-mm" with "Usar hoy".
    if (!opts.receiptId && stored.purchase_date !== today) {
      if (isPristine(stored)) return { draft: { ...stored, purchase_date: today }, stale: null }
      return { draft: stored, stale: stored.purchase_date }
    }
    return { draft: stored, stale: null }
  })
  const [draft, setDraft] = useState<Draft>(init.draft)
  const [staleDate, setStaleDate] = useState<string | null>(init.stale)

  // Persist after the first edit (an untouched form never writes, so no stale draft is left behind).
  const dirty = useRef(false)
  useEffect(() => {
    if (!dirty.current) return
    try {
      localStorage.setItem(storageKey, JSON.stringify(draft))
    } catch {
      /* storage full or unavailable: keep working in memory */
    }
  }, [draft, storageKey])
  const edit = useCallback((fn: (d: Draft) => Draft) => {
    dirty.current = true
    setDraft(fn)
  }, [])

  const setHeader = useCallback(
    (patch: Partial<Omit<Draft, 'lines' | 'idempotency_key'>>) => edit((d) => ({ ...d, ...patch })),
    [edit],
  )
  const addLine = useCallback(() => edit((d) => ({ ...d, lines: [...d.lines, newLine()] })), [edit])
  const updateLine = useCallback(
    (key: string, patch: Partial<Omit<DraftLine, 'key'>>) =>
      edit((d) => ({ ...d, lines: d.lines.map((l) => (l.key === key ? { ...l, ...patch } : l)) })),
    [edit],
  )
  const removeLine = useCallback(
    (key: string) =>
      edit((d) => {
        const lines = d.lines.filter((l) => l.key !== key)
        return { ...d, lines: lines.length ? lines : [newLine()] }
      }),
    [edit],
  )
  const reset = useCallback(() => {
    localStorage.removeItem(storageKey)
    dirty.current = false
    setStaleDate(null)
    setDraft(newDraft())
  }, [storageKey])
  /** Date of a restored draft from another day, while the draft still has it; null otherwise. */
  const staleDraftDate = staleDate !== null && draft.purchase_date === staleDate ? staleDate : null
  const applyToday = useCallback(() => {
    setStaleDate(null)
    setHeader({ purchase_date: todaySantiago() })
  }, [setHeader])

  const settings = useQuery({
    queryKey: ['settings'],
    queryFn: () => api<Record<string, string>>('GET', '/settings'),
    staleTime: 5 * 60_000,
  })
  const ivaRate = opts.ivaRate ?? (settings.data?.iva_rate != null ? Number(settings.data.iva_rate) : 0.19)
  const thresholdPct = settings.data?.alert_threshold_pct != null ? Number(settings.data.alert_threshold_pct) : 10

  const baselines = useQueries({
    queries: draft.lines.map((l) => ({
      queryKey: ['baseline', l.product?.id ?? null, draft.purchase_date, opts.receiptId ?? null],
      queryFn: () =>
        api<Baseline | null>(
          'GET',
          `/products/${l.product!.id}/baseline?date=${draft.purchase_date}${opts.receiptId ? `&receipt_id=${opts.receiptId}` : ''}`,
        ),
      enabled: l.product != null && draft.purchase_date !== '',
      staleTime: 60_000,
    })),
  })
  const baselineData = baselines.map((b) => b.data ?? null)
  const baselineKey = JSON.stringify(baselineData)

  const computed = useMemo<Computed>(() => {
    const factura = draft.doc_type === 'factura'
    // Same pipeline as the server (calc.computeReceipt mirrors ReceiptService::compute), over lines with a product.
    const active = draft.lines.map((l) => l.product != null)
    const baseUnits: Record<number, BaseUnit> = {}
    const items: ReceiptInputLine[] = []
    draft.lines.forEach((l) => {
      if (!l.product) return
      baseUnits[l.product.id] = l.product.base_unit
      items.push({ ...lineInput(l), product_id: l.product.id, iva_exempt: factura && l.iva_exempt })
    })
    const input = {
      doc_type: draft.doc_type,
      adjustment_amount: parseSignedCLP(draft.adjustment) ?? 0,
      delivery_amount: parseCLP(draft.delivery) ?? 0,
      items,
    }
    let calc: ReturnType<typeof computeReceipt>
    try {
      calc = computeReceipt(input, baseUnits, ivaRate)
    } catch {
      calc = computeReceipt({ ...input, adjustment_amount: 0 }, baseUnits, ivaRate)
    }
    let n = 0
    const lines: LineComputed[] = draft.lines.map((l, i) => {
      const it = active[i] ? calc.items[n++] : null
      const entered = it?.entered_amount ?? 0
      const np = it?.normalized_price ?? null
      const baseline = baselineData[i]
      const mult = l.packaged ? parseDecimal(l.package_count) : parseDecimal(l.quantity)
      return {
        baseQty: it?.base_quantity ?? null,
        enteredAmount: entered,
        realCost: it?.real_cost ?? 0,
        amountPaid: it?.amount_paid ?? 0,
        normalizedPrice: np,
        comparison: change(np, baseline?.normalized_price ?? null, thresholdPct),
        baseline,
        derivedUnitPrice: mult && mult > 0 && entered > 0 ? entered / mult : null,
      }
    })
    const totals = calc.totals
    const printedTotal = parseCLP(draft.printed_total)
    return {
      lines,
      totals,
      ivaRate,
      thresholdPct,
      printedTotal,
      mismatch: printedTotal !== null && printedTotal !== totals.total,
      adjustmentTooLarge: lines.some((l, i) => active[i] && l.realCost < 0),
    }
    // baselineData is derived from `baselines`, which changes identity each render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [draft, ivaRate, thresholdPct, baselineKey])

  return { draft, setHeader, addLine, updateLine, removeLine, computed, reset, storageKey, staleDraftDate, applyToday }
}
