export class ApiError extends Error {
  status: number
  code: string
  data: Record<string, unknown>
  constructor(status: number, code: string, data: Record<string, unknown> = {}) {
    super(code)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.data = data
  }
  /** Field-level messages of a 422 validation error, e.g. {'items.0.quantity': '...'}. */
  get fields(): Record<string, string> {
    const f = this.data.fields
    return f && typeof f === 'object' ? (f as Record<string, string>) : {}
  }
}

export const UNAUTHENTICATED_EVENT = 'kt:unauthenticated'

let csrfToken: string | null = null

export function setCsrfToken(t: string | null): void {
  csrfToken = t
}
export function getCsrfToken(): string | null {
  return csrfToken
}

export async function api<T = unknown>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (csrfToken && method.toUpperCase() !== 'GET') headers['X-CSRF-Token'] = csrfToken
  let res: Response
  try {
    res = await fetch('/api' + path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'network')
  }
  let data: unknown = null
  const text = await res.text()
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      data = null
    }
  }
  if (res.ok) return data as T
  const obj = (data && typeof data === 'object' ? data : {}) as Record<string, unknown>
  const code = typeof obj.error === 'string' ? obj.error : 'http_' + res.status
  if (res.status === 401 && path !== '/auth/login') {
    window.dispatchEvent(new CustomEvent(UNAUTHENTICATED_EVENT))
  }
  throw new ApiError(res.status, code, obj)
}

/** Boot: asks the server whether the session is alive and stores the CSRF token. */
export async function fetchSession(): Promise<boolean> {
  const me = await api<{ authenticated: boolean; csrf_token?: string }>('GET', '/auth/me')
  setCsrfToken(me.authenticated ? (me.csrf_token ?? null) : null)
  return me.authenticated
}

export async function login(password: string): Promise<void> {
  const r = await api<{ csrf_token: string }>('POST', '/auth/login', { password })
  setCsrfToken(r.csrf_token)
}

export async function logout(): Promise<void> {
  try {
    await api('POST', '/auth/logout')
  } finally {
    setCsrfToken(null)
  }
}
