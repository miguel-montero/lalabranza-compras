import type { BaseUnit, ComparisonStatus } from '@/lib/calc'
import { formatDate, formatPct, formatUnitPrice } from '@/lib/format'

export interface ChipComparison {
  status: ComparisonStatus
  pct: number | null
  flagged: boolean
}
export interface ChipRef {
  normalized_price: number
  supplier_name: string
  purchase_date: string
}

const STYLE: Record<ComparisonStatus, string> = {
  none: 'bg-muted text-muted-foreground',
  same: 'bg-secondary text-secondary-foreground',
  up: 'bg-[color-mix(in_oklab,var(--up)_14%,transparent)] text-[var(--up)]',
  down: 'bg-[color-mix(in_oklab,var(--down)_14%,transparent)] text-[var(--down)]',
}

/** ↑ red / ↓ green / = / "Sin comparación". Flagged changes are bold. Tooltip names the reference purchase. */
export function ComparisonChip({ comparison, reference, baseUnit }: { comparison: ChipComparison; reference?: ChipRef | null; baseUnit: BaseUnit }) {
  const { status, pct, flagged } = comparison
  const label =
    status === 'none' ? 'Sin comparación' : status === 'same' ? '= igual' : `${status === 'up' ? '↑' : '↓'} ${formatPct(pct ?? 0)}`
  const title = reference
    ? `Referencia: ${formatUnitPrice(reference.normalized_price, baseUnit)} · ${reference.supplier_name} · ${formatDate(reference.purchase_date)}`
    : undefined
  return (
    <span
      data-status={status}
      data-flagged={flagged || undefined}
      title={title}
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-sm ${STYLE[status]} ${flagged ? 'font-bold ring-1 ring-current' : 'font-medium'}`}
    >
      {label}
    </span>
  )
}
