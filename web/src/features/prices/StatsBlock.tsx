import { Link } from 'react-router-dom'
import type { BaseUnit } from '@/lib/calc'
import { formatDate, formatUnitPrice } from '@/lib/format'
import type { ProductStats, SupplierPrice } from '@/lib/types'

/** Latest real price, lowest in the recent window, and per-supplier rows. Shared by Consultar and Producto. */
export function StatsBlock({ stats, bySupplier, baseUnit }: { stats: ProductStats; bySupplier: SupplierPrice[]; baseUnit: BaseUnit }) {
  const { latest, lowest_recent: lowest } = stats
  if (!latest) return <p className="text-sm text-muted-foreground">Sin compras registradas.</p>
  return (
    <div className="grid gap-3">
      <div>
        <div className="text-3xl font-semibold" data-testid="latest-price">
          {formatUnitPrice(latest.normalized_price, baseUnit)}
        </div>
        <div className="text-sm text-muted-foreground">
          Último costo real · {formatDate(latest.purchase_date)} · {latest.supplier_name}
        </div>
      </div>
      <div className="text-sm" data-testid="lowest">
        {lowest ? (
          <>
            Más bajo en {stats.recent_days} días: <strong>{formatUnitPrice(lowest.normalized_price, baseUnit)}</strong> · {lowest.supplier_name} ({formatDate(lowest.purchase_date)})
          </>
        ) : (
          <span className="text-muted-foreground">Sin compras en los últimos {stats.recent_days} días.</span>
        )}
      </div>
      {bySupplier.length > 0 && (
        <ul className="divide-y rounded-md border bg-card text-sm">
          {bySupplier.map((s) => (
            <li key={s.supplier_name} className="flex items-baseline justify-between gap-2 px-3 py-2">
              <span>
                {s.supplier_name}
                <span className="block text-xs text-muted-foreground">
                  {formatDate(s.purchase_date)} · {s.count_in_window} compra{s.count_in_window === 1 ? '' : 's'} en {stats.recent_days} días
                </span>
              </span>
              <span className="font-medium">{formatUnitPrice(s.latest_normalized_price, baseUnit)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function ProductLink({ id, children }: { id: number; children: React.ReactNode }) {
  return (
    <Link className="underline-offset-2 hover:underline" to={`/productos/${id}`}>
      {children}
    </Link>
  )
}
