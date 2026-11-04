import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { UNAUTHENTICATED_EVENT } from '@/lib/api'
import { Layout } from './Layout'
import { useSession } from './session'

/** Turns the global `kt:unauthenticated` event into a redirect to /login?next=<path>. */
export function useUnauthenticatedRedirect() {
  const navigate = useNavigate()
  const qc = useQueryClient()
  const location = useLocation()
  useEffect(() => {
    const handler = () => {
      qc.setQueryData(['session'], false)
      if (location.pathname !== '/login') {
        navigate(`/login?next=${encodeURIComponent(location.pathname + location.search)}`, { replace: true })
      }
    }
    window.addEventListener(UNAUTHENTICATED_EVENT, handler)
    return () => window.removeEventListener(UNAUTHENTICATED_EVENT, handler)
  }, [navigate, qc, location.pathname, location.search])
}

export default function RequireAuth() {
  const session = useSession()
  const location = useLocation()
  useUnauthenticatedRedirect()
  if (session.isLoading) return <p className="p-6 text-muted-foreground">Cargando…</p>
  if (session.isError) return <p className="p-6 text-destructive">No se pudo conectar con el servidor.</p>
  if (!session.data) {
    return <Navigate to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} replace />
  }
  return (
    <Layout>
      <Outlet />
    </Layout>
  )
}
