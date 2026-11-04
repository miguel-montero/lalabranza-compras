import { useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { logout } from '@/lib/api'

const MAIN = [
  { to: '/compras/nueva', label: 'Nueva compra', icon: '＋' },
  { to: '/consultar', label: 'Consultar precio', icon: '⌕' },
  { to: '/', label: 'Panel', icon: '▦' },
]
const MORE = [
  { to: '/compras', label: 'Compras' },
  { to: '/historial', label: 'Historial' },
  { to: '/productos', label: 'Productos' },
  { to: '/proveedores', label: 'Proveedores' },
  { to: '/categorias', label: 'Categorías' },
  { to: '/importar', label: 'Importar planilla' },
  { to: '/ajustes', label: 'Ajustes' },
]

export function Layout({ children }: { children: ReactNode }) {
  const [more, setMore] = useState(false)
  const navigate = useNavigate()
  const qc = useQueryClient()
  const location = useLocation()

  async function doLogout() {
    await logout().catch(() => undefined)
    // The receipt draft stays in localStorage on purpose.
    qc.clear()
    navigate('/login', { replace: true })
  }

  const side = 'block rounded-md px-3 py-2 text-sm text-sidebar-foreground hover:bg-sidebar-accent'
  const sideActive = 'bg-sidebar-accent text-sidebar-accent-foreground font-medium'
  const moreActive = MORE.some((m) => location.pathname.startsWith(m.to) && !(m.to === '/compras' && location.pathname === '/compras/nueva'))

  return (
    <div className="min-h-dvh md:grid md:grid-cols-[14rem_1fr]">
      <aside className="hidden bg-sidebar p-4 text-sidebar-foreground md:flex md:flex-col md:gap-1">
        <div className="mb-4 px-3 font-heading text-xl text-sidebar-primary">La Labranza</div>
        {[...MAIN, ...MORE].map((l) => (
          <NavLink key={l.to} to={l.to} end={l.to === '/' || l.to === '/compras'} className={({ isActive }) => `${side} ${isActive ? sideActive : ''}`}>
            {l.label}
          </NavLink>
        ))}
        <button type="button" onClick={doLogout} className={`${side} mt-auto text-left`}>
          Cerrar sesión
        </button>
      </aside>

      <main className="mx-auto w-full max-w-3xl px-4 pt-4 pb-28 md:pb-10">{children}</main>

      <nav aria-label="Principal" className="fixed inset-x-0 bottom-0 z-20 grid grid-cols-4 border-t bg-card pb-[env(safe-area-inset-bottom)] md:hidden">
        {MAIN.map((l) => (
          <NavLink key={l.to} to={l.to} end={l.to === '/'} className={({ isActive }) => `flex flex-col items-center gap-0.5 px-1 py-2 text-xs ${isActive ? 'font-semibold text-[var(--cobre-viejo)]' : 'text-muted-foreground'}`}>
            <span aria-hidden className="text-lg leading-none">
              {l.icon}
            </span>
            {l.label}
          </NavLink>
        ))}
        <button type="button" onClick={() => setMore(true)} className={`flex flex-col items-center gap-0.5 px-1 py-2 text-xs ${moreActive ? 'font-semibold text-[var(--cobre-viejo)]' : 'text-muted-foreground'}`}>
          <span aria-hidden className="text-lg leading-none">
            ≡
          </span>
          Más
        </button>
      </nav>

      <Sheet open={more} onOpenChange={setMore}>
        <SheetContent side="bottom">
          <SheetHeader>
            <SheetTitle>Más</SheetTitle>
          </SheetHeader>
          <div className="grid gap-1 px-4 pb-6">
            {MORE.map((l) => (
              <NavLink key={l.to} to={l.to} onClick={() => setMore(false)} className="rounded-md px-3 py-3 text-base hover:bg-accent">
                {l.label}
              </NavLink>
            ))}
            <button type="button" onClick={doLogout} className="rounded-md px-3 py-3 text-left text-base text-destructive hover:bg-accent">
              Cerrar sesión
            </button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  )
}
