import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ReceiptForm from './ReceiptForm'

const calls: { method: string; url: string }[] = []

beforeEach(() => {
  localStorage.clear()
  calls.length = 0
  // jsdom lacks these; cmdk / radix need them.
  globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as never
  Element.prototype.scrollIntoView ??= () => {}
  Element.prototype.hasPointerCapture ??= () => false
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string, init?: RequestInit) => {
      const method = init?.method ?? 'GET'
      calls.push({ method, url })
      if (url.startsWith('/api/settings')) return new Response(JSON.stringify({ iva_rate: '0.19', alert_threshold_pct: '10' }))
      if (url.startsWith('/api/products') && method === 'POST') {
        return new Response(JSON.stringify({ id: 9, name: 'Cilantro', base_unit: 'kg', category_id: null, category_name: null, active: true, notes: null, aliases: [] }), { status: 201 })
      }
      if (url.startsWith('/api/products')) return new Response('[]')
      if (url.startsWith('/api/categories')) return new Response('[]')
      if (url.startsWith('/api/suppliers')) return new Response('[]')
      return new Response('null')
    }),
  )
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

function setup() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ReceiptForm />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
const receiptPosts = () => calls.filter((c) => c.method === 'POST' && c.url === '/api/receipts')

describe('ReceiptForm', () => {
  it('inline product creation does not POST /receipts', async () => {
    const user = userEvent.setup()
    setup()
    await user.click(screen.getByText('Elegir producto'))
    await user.type(screen.getByPlaceholderText(/Buscar producto/), 'Cilantro')
    await user.click(await screen.findByText(/Crear «Cilantro»/))
    const dialog = await screen.findByRole('dialog')
    expect(dialog).toBeTruthy()
    await user.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url === '/api/products')).toBe(true))
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
    expect(receiptPosts()).toHaveLength(0)
  })

  it('Enter in header inputs does not submit the receipt', async () => {
    const user = userEvent.setup()
    setup()
    await user.type(screen.getByLabelText(/N° de boleta/), '123{Enter}')
    await user.type(screen.getByLabelText('Descuento / ajuste'), '-5{Enter}')
    expect(receiptPosts()).toHaveLength(0)
  })

  it('Enter in the last amount field adds a line without saving', async () => {
    const user = userEvent.setup()
    setup()
    await user.type(screen.getByLabelText('Total línea'), '1000{Enter}')
    expect(screen.getAllByTestId('line-row')).toHaveLength(2)
    expect(receiptPosts()).toHaveLength(0)
  })
})
