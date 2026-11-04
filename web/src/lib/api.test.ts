import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api, ApiError, setCsrfToken, UNAUTHENTICATED_EVENT } from './api'

function mockFetch(status: number, body: unknown) {
  const fn = vi.fn(async () => new Response(JSON.stringify(body), { status }))
  vi.stubGlobal('fetch', fn)
  return fn
}

describe('api', () => {
  beforeEach(() => {
    localStorage.clear()
    setCsrfToken(null)
  })
  afterEach(() => vi.unstubAllGlobals())

  it('401 dispatches kt:unauthenticated and does not touch draft storage', async () => {
    localStorage.setItem('kt:draft:new', '{"keep":"me"}')
    mockFetch(401, { error: 'unauthenticated' })
    const handler = vi.fn()
    window.addEventListener(UNAUTHENTICATED_EVENT, handler)
    await expect(api('GET', '/receipts')).rejects.toMatchObject({ status: 401, code: 'unauthenticated' })
    window.removeEventListener(UNAUTHENTICATED_EVENT, handler)
    expect(handler).toHaveBeenCalledTimes(1)
    expect(localStorage.getItem('kt:draft:new')).toBe('{"keep":"me"}')
  })

  it('sends X-CSRF-Token on POST', async () => {
    setCsrfToken('tok123')
    const fn = mockFetch(200, { ok: true })
    await api('POST', '/suppliers', { name: 'Vega' })
    const [url, init] = fn.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/suppliers')
    expect((init.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok123')
    expect(init.credentials).toBe('same-origin')
    expect(init.body).toBe('{"name":"Vega"}')
  })

  it('rejects 409/422 with ApiError carrying status, code and data', async () => {
    mockFetch(409, { error: 'duplicate_receipt_number', existing_id: 7 })
    const e = (await api('POST', '/receipts', {}).catch((x) => x)) as ApiError
    expect(e).toBeInstanceOf(ApiError)
    expect(e.status).toBe(409)
    expect(e.code).toBe('duplicate_receipt_number')
    expect(e.data.existing_id).toBe(7)
  })
})
