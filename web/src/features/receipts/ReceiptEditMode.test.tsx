import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { ReceiptView } from '@/lib/types'
import ReceiptForm from './ReceiptForm'
import { draftFromReceipt } from './useReceiptDraft'

const receipt: ReceiptView = {
  id: 7,
  supplier: { id: 1, name: 'Lider' },
  purchase_date: '2026-02-10',
  date_unknown: false,
  doc_type: 'boleta',
  receipt_number: null,
  notes: null,
  status: 'active',
  adjustment_amount: 0,
  delivery_amount: 0,
  printed_total: null,
  iva_rate: 0.19,
  totals: { neto: 0, exento: 0, iva: 0, total: 1500 },
  printed_total_mismatch: false,
  items: [
    {
      id: 1, line_order: 0, product_id: 3, product: { id: 3, name: 'Arroz', base_unit: 'kg' }, entry_mode: 'total', quantity: 1,
      entry_unit: 'kg', package_count: null, package_content: null, package_unit: null, unit_price: null, entered_amount: 1500,
      adjustment_share: 0, iva_exempt: false, real_cost: 1500, amount_paid: 1500, base_quantity: 1, normalized_price: 1500, notes: null,
    },
  ],
}

const calls: { method: string; url: string; body?: string }[] = []

beforeEach(() => {
  localStorage.clear()
  calls.length = 0
  globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as never
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ method: init?.method ?? 'GET', url, body: init?.body as string | undefined })
      if (url.startsWith('/api/settings')) return new Response(JSON.stringify({ iva_rate: '0.19', alert_threshold_pct: '10' }))
      if (url.startsWith('/api/receipts/7')) return new Response(JSON.stringify(receipt))
      return new Response('null')
    }),
  )
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('ReceiptForm edit mode', () => {
  it('shows edit labels, PUTs with reason, clears the kt:draft:<id> draft', async () => {
    const user = userEvent.setup()
    const saved = vi.fn()
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <ReceiptForm
            storageKey="kt:draft:7"
            initial={draftFromReceipt(receipt)}
            editingId={7}
            submit={async (p) => {
              calls.push({ method: 'PUT', url: '/api/receipts/7', body: JSON.stringify(p) })
              return receipt
            }}
            onSaved={saved}
          />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    expect(screen.getByRole('heading', { name: 'Editar compra' })).toBeTruthy()
    expect(localStorage.getItem('kt:draft:7')).toBeTruthy()
    // baselines in edit mode are scoped to the receipt being edited
    await waitFor(() => expect(calls.some((c) => c.url.includes('/products/3/baseline') && c.url.includes('receipt_id=7'))).toBe(true))
    await user.type(screen.getByLabelText(/Motivo del cambio/), 'precio mal')
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }))
    await waitFor(() => expect(saved).toHaveBeenCalled())
    const put = calls.find((c) => c.method === 'PUT')!
    expect(JSON.parse(put.body!).reason).toBe('precio mal')
    expect(localStorage.getItem('kt:draft:7')).toBeNull()
  })
})
