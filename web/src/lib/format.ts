import type { BaseUnit } from './calc'
import { roundHalfAway } from './calc'

const clp = new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0, useGrouping: true })
const pct = new Intl.NumberFormat('es-CL', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
  useGrouping: true,
})

/** 1300 -> "$1.300", -400 -> "-$400". Rounded half away to a whole peso. */
export function formatCLP(n: number): string {
  const r = roundHalfAway(n)
  const body = '$' + clp.format(Math.abs(r)).replace(/ /g, '')
  return r < 0 ? '-' + body : body
}

/** "$1.300/kg"; rounded to a whole peso for display. */
export function formatUnitPrice(n: number, base: BaseUnit): string {
  return `${formatCLP(n)}/${base}`
}

/** 15.38 -> "+15,38%", -10 -> "-10,00%", 0 -> "0,00%". */
export function formatPct(p: number): string {
  const r = roundHalfAway(p, 2)
  const body = pct.format(Math.abs(r))
  return (r > 0 ? '+' : r < 0 ? '-' : '') + body + '%'
}

/** "2026-02-10" -> "10-02-2026"; null/empty -> "sin fecha". Pure string handling, no time zones. */
export function formatDate(d: string | null | undefined): string {
  if (!d) return 'sin fecha'
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d)
  return m ? `${m[3]}-${m[2]}-${m[1]}` : d
}
