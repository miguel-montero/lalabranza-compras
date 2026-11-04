import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import HistoryPage from './HistoryPage'

const urls: string[] = []

beforeEach(() => {
  urls.length = 0
  globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as never
  Element.prototype.scrollIntoView ??= () => {}
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      urls.push(url)
      if (url.startsWith('/api/products')) {
        return new Response(JSON.stringify([
          { id: 3, name: 'Arroz', base_unit: 'kg', category_id: null, category_name: null, active: false, notes: null, aliases: [] },
        ]))
      }
      if (url.startsWith('/api/history')) return new Response(JSON.stringify({ items: [], total: 0, page: 1, page_size: 50 }))
      return new Response('[]')
    }),
  )
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('HistoryPage product filter', () => {
  it('filters by product (including inactive ones) and the CSV export uses the same filter', async () => {
    const user = userEvent.setup()
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <HistoryPage />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    await user.click(screen.getByRole('combobox', { name: 'Producto' }))
    await waitFor(() => expect(urls.some((u) => u.startsWith('/api/products?active=all'))).toBe(true))
    await user.click(await screen.findByText('Arroz'))
    expect(screen.queryByText(/Crear «/)).toBeNull()
    await user.click(screen.getByRole('button', { name: 'Filtrar' }))
    await waitFor(() => expect(urls).toContain('/api/history?product_id=3&page=1'))
    expect(screen.getByRole('link', { name: 'Exportar CSV' }).getAttribute('href')).toBe('/api/history?product_id=3&format=csv')
    await user.click(screen.getByRole('button', { name: 'Quitar filtro de producto' }))
    await user.click(screen.getByRole('button', { name: 'Filtrar' }))
    await waitFor(() => expect(urls).toContain('/api/history?page=1'))
  })
})
