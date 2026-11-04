import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { todaySantiago } from '@/lib/santiago'
import type { ReceiptView } from '@/lib/types'
import { buildPayload, compatibleUnits, contentUnits, draftFromReceipt, newDraft, useReceiptDraft } from './useReceiptDraft'

const KEY = 'kt:draft:new'

function wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
}

const kg = { id: 1, name: 'Tomate', base_unit: 'kg' as const }

beforeEach(() => {
  localStorage.clear()
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      if (url.startsWith('/api/settings')) {
        return new Response(JSON.stringify({ iva_rate: '0.19', alert_threshold_pct: '10' }))
      }
      return new Response('null')
    }),
  )
})
afterEach(() => vi.unstubAllGlobals())

describe('useReceiptDraft', () => {
  it('persists and restores after unmount', () => {
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    act(() => a.result.current.setHeader({ receipt_number: '00123', notes: 'hola' }))
    act(() => a.result.current.addLine())
    const lineKey = a.result.current.draft.lines[0].key
    act(() => a.result.current.updateLine(lineKey, { product: kg, quantity: '2,5', amount: '3000' }))
    expect(JSON.parse(localStorage.getItem(KEY)!).receipt_number).toBe('00123')
    a.unmount()

    const b = renderHook(() => useReceiptDraft(), { wrapper })
    expect(b.result.current.draft.receipt_number).toBe('00123')
    expect(b.result.current.draft.notes).toBe('hola')
    expect(b.result.current.draft.lines[0].product).toEqual(kg)
    expect(b.result.current.draft.lines[0].quantity).toBe('2,5')
    expect(b.result.current.draft.lines[0].amount).toBe('3000')
  })

  it('keeps idempotency key across failed save', () => {
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    const key = a.result.current.draft.idempotency_key
    expect(key).toBeTruthy()
    // a failed save never calls reset(); the user edits and retries
    act(() => a.result.current.setHeader({ notes: 'reintento' }))
    expect(a.result.current.draft.idempotency_key).toBe(key)
    a.unmount()
    const b = renderHook(() => useReceiptDraft(), { wrapper })
    expect(b.result.current.draft.idempotency_key).toBe(key)
  })

  it('reset clears storage', () => {
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    const key = a.result.current.draft.idempotency_key
    act(() => a.result.current.setHeader({ notes: 'x' }))
    expect(localStorage.getItem(KEY)).not.toBeNull()
    act(() => a.result.current.reset())
    expect(localStorage.getItem(KEY)).toBeNull()
    expect(a.result.current.draft.idempotency_key).not.toBe(key)
    expect(a.result.current.draft.notes).toBe('')
  })

  it('computed matches calc for factura line', async () => {
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    act(() => a.result.current.setHeader({ doc_type: 'factura' }))
    act(() => a.result.current.addLine())
    const k = a.result.current.draft.lines[0].key
    act(() => a.result.current.updateLine(k, { product: kg, quantity: '1', unit: 'kg', entry_mode: 'total', amount: '1000' }))
    await waitFor(() => expect(a.result.current.computed.ivaRate).toBe(0.19))
    const l = a.result.current.computed.lines[0]
    expect(l.realCost).toBe(1000)
    expect(l.amountPaid).toBe(1190)
    expect(l.baseQty).toBe(1)
    expect(l.normalizedPrice).toBe(1000)
    expect(a.result.current.computed.totals).toEqual({ neto: 1000, exento: 0, iva: 190, total: 1190 })
  })

  it('discount spreads across lines and exempt lines stay out of neto (matches API: 900/2700/171/3771)', async () => {
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    act(() => a.result.current.setHeader({ doc_type: 'factura', adjustment: '-400' }))
    act(() => a.result.current.addLine())
    const [k1, k2] = a.result.current.draft.lines.map((l) => l.key)
    act(() => a.result.current.updateLine(k1, { product: kg, quantity: '1', unit: 'kg', amount: '1000' }))
    act(() => a.result.current.updateLine(k2, { product: kg, quantity: '1', unit: 'kg', amount: '3000', iva_exempt: true }))
    await waitFor(() => expect(a.result.current.computed.ivaRate).toBe(0.19))
    expect(a.result.current.computed.totals).toEqual({ neto: 900, exento: 2700, iva: 171, total: 3771 })
  })

  it('a pristine stored draft from another day loads with today and nothing is written until an edit', () => {
    const old = { ...newDraft(), purchase_date: '2026-01-02' }
    localStorage.setItem(KEY, JSON.stringify(old))
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    expect(a.result.current.draft.purchase_date).toBe(todaySantiago())
    expect(a.result.current.staleDraftDate).toBeNull()
    a.unmount()
    localStorage.clear()
    const b = renderHook(() => useReceiptDraft(), { wrapper })
    expect(localStorage.getItem(KEY)).toBeNull() // untouched form: no draft left behind
    act(() => b.result.current.setHeader({ notes: 'x' }))
    expect(localStorage.getItem(KEY)).not.toBeNull()
  })

  it('a stored draft with content from another day keeps its date and flags it until "Usar hoy"', () => {
    const old = newDraft()
    const d = { ...old, purchase_date: '2026-01-02', lines: [{ ...old.lines[0], product: kg, amount: '1000' }] }
    localStorage.setItem(KEY, JSON.stringify(d))
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    expect(a.result.current.draft.purchase_date).toBe('2026-01-02')
    expect(a.result.current.staleDraftDate).toBe('2026-01-02')
    act(() => a.result.current.applyToday())
    expect(a.result.current.draft.purchase_date).toBe(todaySantiago())
    expect(a.result.current.staleDraftDate).toBeNull()
    expect(JSON.parse(localStorage.getItem(KEY)!).purchase_date).toBe(todaySantiago())
  })

  it('edit mode previews with the receipt\'s own IVA rate, not the current setting', async () => {
    const a = renderHook(() => useReceiptDraft({ storageKey: 'kt:draft:9', receiptId: 9, ivaRate: 0.15 }), { wrapper })
    act(() => a.result.current.setHeader({ doc_type: 'factura' }))
    const k = a.result.current.draft.lines[0].key
    act(() => a.result.current.updateLine(k, { product: kg, quantity: '1', unit: 'kg', amount: '1000' }))
    await new Promise((r) => setTimeout(r, 20)) // let the settings query (iva 0.19) resolve
    expect(a.result.current.computed.ivaRate).toBe(0.15)
    expect(a.result.current.computed.lines[0].amountPaid).toBe(1150)
    expect(a.result.current.computed.totals.iva).toBe(150)
  })

  it('kg/L products may be entered in unidad (not comparable); package content units stay convertible', async () => {
    expect(compatibleUnits('kg')).toEqual(['kg', 'g', 'unidad'])
    expect(compatibleUnits('L')).toEqual(['L', 'mL', 'unidad'])
    expect(compatibleUnits('unidad')).toEqual(['unidad'])
    expect(contentUnits('kg')).toEqual(['kg', 'g'])
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    const k = a.result.current.draft.lines[0].key
    act(() => a.result.current.updateLine(k, { product: kg, quantity: '2', unit: 'unidad', amount: '1000' }))
    const l = a.result.current.computed.lines[0]
    expect(l.realCost).toBe(1000)
    expect(l.normalizedPrice).toBeNull()
    expect(l.comparison.status).toBe('none')
  })

  it('0,125 g for $5.000 previews $40.000.000/kg like the server', () => {
    const a = renderHook(() => useReceiptDraft(), { wrapper })
    const k = a.result.current.draft.lines[0].key
    act(() => a.result.current.updateLine(k, { product: kg, quantity: '0,125', unit: 'g', amount: '5000' }))
    expect(a.result.current.computed.lines[0].normalizedPrice).toBe(40000000)
    expect(a.result.current.computed.lines[0].baseQty).toBe(0.000125)
  })
})

describe('undated receipts in edit mode', () => {
  const undated = {
    id: 5, supplier: { id: 1, name: 'Lider' }, purchase_date: null, date_unknown: true, doc_type: 'boleta',
    receipt_number: 'U1', notes: null, status: 'active', adjustment_amount: 0, delivery_amount: 0, printed_total: null,
    iva_rate: 0.19, totals: { neto: 0, exento: 0, iva: 0, total: 1000 }, printed_total_mismatch: false,
    items: [{
      id: 1, line_order: 0, product_id: 1, product: kg, entry_mode: 'total', quantity: 1, entry_unit: 'kg',
      package_count: null, package_content: null, package_unit: null, unit_price: null, entered_amount: 1000,
      adjustment_share: 0, iva_exempt: false, real_cost: 1000, amount_paid: 1000, base_quantity: 1, normalized_price: 1000, notes: null,
    }],
  } as ReceiptView

  it('stays undated: empty date in the draft, null in the PUT payload', () => {
    const d = draftFromReceipt(undated)
    expect(d.purchase_date).toBe('')
    expect(d.date_unknown).toBe(true)
    expect(buildPayload(d).payload.purchase_date).toBeNull()
    expect(buildPayload({ ...d, purchase_date: '2026-01-05' }).payload.purchase_date).toBe('2026-01-05')
  })

  it('does not request baselines without a date', async () => {
    const fetchMock = globalThis.fetch as unknown as ReturnType<typeof vi.fn>
    const a = renderHook(() => useReceiptDraft({ storageKey: 'kt:draft:5', receiptId: 5, initial: draftFromReceipt(undated) }), { wrapper })
    await new Promise((r) => setTimeout(r, 20))
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('/baseline'))).toBe(false)
    expect(a.result.current.computed.lines[0].comparison.status).toBe('none')
  })
})
