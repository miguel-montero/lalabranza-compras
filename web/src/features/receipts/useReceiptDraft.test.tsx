import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useReceiptDraft } from './useReceiptDraft'

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
})
