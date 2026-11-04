import { useRef, useState } from 'react'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
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

/** ↑ red / ↓ green / = / "Sin comparación". Flagged changes are bold. Tap or hover shows the reference purchase. */
export function ComparisonChip({ comparison, reference, baseUnit }: { comparison: ChipComparison; reference?: ChipRef | null; baseUnit: BaseUnit }) {
  const { status, pct, flagged } = comparison
  const [open, setOpen] = useState(false)
  const hoverOpened = useRef(false)
  const canHover = () => typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover)').matches
  const label =
    status === 'none' ? 'Sin comparación' : status === 'same' ? '= igual' : `${status === 'up' ? '↑' : '↓'} ${formatPct(pct ?? 0)}`
  const cls = `inline-flex items-center rounded-full px-2.5 py-0.5 text-sm ${STYLE[status]} ${flagged ? 'font-bold ring-1 ring-current' : 'font-medium'}`
  if (!reference) {
    return (
      <span data-status={status} data-flagged={flagged || undefined} className={cls}>
        {label}
      </span>
    )
  }
  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        // A click right after a hover-open must not immediately close it.
        if (!next && hoverOpened.current) {
          hoverOpened.current = false
          return
        }
        setOpen(next)
      }}
    >
      <PopoverTrigger asChild>
        <button
          type="button"
          data-status={status}
          data-flagged={flagged || undefined}
          className={cls}
          onMouseEnter={() => {
            if (canHover()) {
              hoverOpened.current = true
              setOpen(true)
            }
          }}
          onMouseLeave={() => {
            hoverOpened.current = false
            if (canHover()) setOpen(false)
          }}
        >
          {label}
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-auto max-w-64 text-sm" align="start">
        <div className="font-medium">Referencia</div>
        <div>{formatUnitPrice(reference.normalized_price, baseUnit)}</div>
        <div className="text-muted-foreground">
          {reference.supplier_name} · {formatDate(reference.purchase_date)}
        </div>
      </PopoverContent>
    </Popover>
  )
}
