// TypeScript mirror of api/src/Calc/*.php, used for the live preview only.
// The server always recomputes on save. Both must pass shared/calc-fixtures.json.

export type Unit = 'kg' | 'g' | 'L' | 'mL' | 'unidad'
export type BaseUnit = 'kg' | 'L' | 'unidad'
export type DocType = 'boleta' | 'factura'
export type EntryMode = 'total' | 'unit_price'

/** Same field names as the API / fixtures. */
export interface LineInput {
  entry_mode?: EntryMode | null
  entered_amount?: number | null
  unit_price?: number | null
  quantity?: number | null
  entry_unit?: Unit | null
  package_count?: number | null
  package_content?: number | null
  package_unit?: Unit | null
}

export interface LineCosts {
  realCost: number
  amountPaid: number
}

export interface ReceiptLine {
  realCost: number
  ivaExempt?: boolean | null
}

export interface ReceiptTotals {
  neto: number
  exento: number
  iva: number
  total: number
}

export type ComparisonStatus = 'none' | 'same' | 'up' | 'down'

export interface Comparison {
  status: ComparisonStatus
  diff: number | null
  pct: number | null
  flagged: boolean
}

/**
 * Round half away from zero, matching PHP round(). PHP pre-rounds its
 * operand (round(1.005, 2) === 1.01), so the scaled value is snapped to 15
 * significant digits before rounding. Never returns -0.
 */
export function roundHalfAway(x: number, decimals = 0): number {
  if (!Number.isFinite(x)) return x
  const f = 10 ** decimals
  const scaled = Number((Math.abs(x) * f).toPrecision(15))
  const r = Math.floor(scaled + 0.5) / f
  return (x < 0 ? -r : r) + 0
}

export function toBase(qty: number, unit: Unit, baseUnit: BaseUnit): number | null {
  if (unit === baseUnit && (unit === 'kg' || unit === 'L' || unit === 'unidad')) return qty
  if (unit === 'g' && baseUnit === 'kg') return qty / 1000
  if (unit === 'mL' && baseUnit === 'L') return qty / 1000
  return null
}

export function baseQuantity(line: LineInput, baseUnit: BaseUnit): number | null {
  const count = line.package_count ?? null
  const content = line.package_content ?? null
  const pUnit = line.package_unit ?? null
  if (count !== null && content !== null && pUnit !== null) {
    const per = toBase(Number(content), pUnit, baseUnit)
    return per === null ? null : Number(count) * per
  }
  return toBase(Number(line.quantity ?? 0), (line.entry_unit ?? '') as Unit, baseUnit)
}

export function enteredAmount(line: LineInput): number {
  if (line.entry_mode === 'total') return Math.trunc(Number(line.entered_amount ?? 0)) + 0
  const multiplier = line.package_count ?? line.quantity ?? 0
  return roundHalfAway(Number(line.unit_price ?? 0) * Number(multiplier))
}

/**
 * Spread an integer adjustment proportionally over amounts. The rounding
 * remainder goes to the largest amount (lowest index on ties).
 */
export function spread(amounts: number[], adjustment: number): number[] {
  if (amounts.length === 0) return []
  const sum = amounts.reduce((a, b) => a + b, 0)
  if (sum === 0) {
    if (adjustment !== 0) throw new Error('Cannot spread a non-zero adjustment over zero amounts')
    return amounts.map(() => 0)
  }
  const shares: number[] = []
  let largest = 0
  amounts.forEach((a, i) => {
    shares[i] = roundHalfAway((adjustment * a) / sum)
    if (a > amounts[largest]) largest = i
  })
  shares[largest] += adjustment - shares.reduce((a, b) => a + b, 0)
  return shares.map((s) => s + 0)
}

export function lineCosts(netOrPrinted: number, docType: DocType, exempt: boolean, ivaRate: number): LineCosts {
  if (docType === 'factura' && !exempt) {
    return { realCost: netOrPrinted, amountPaid: roundHalfAway(netOrPrinted * (1 + ivaRate)) }
  }
  return { realCost: netOrPrinted, amountPaid: netOrPrinted }
}

export function normalizedPrice(realCost: number, baseQty: number | null): number | null {
  if (baseQty === null || baseQty <= 0) return null
  return roundHalfAway(realCost / baseQty, 4)
}

export function receiptTotals(
  lines: ReceiptLine[],
  delivery: number,
  docType: DocType,
  ivaRate: number,
): ReceiptTotals {
  if (docType !== 'factura') {
    const sum = lines.reduce((s, l) => s + l.realCost, 0)
    return { neto: 0, exento: 0, iva: 0, total: sum + delivery }
  }
  let neto = delivery
  let exento = 0
  for (const l of lines) {
    if (l.ivaExempt) exento += l.realCost
    else neto += l.realCost
  }
  const iva = roundHalfAway(neto * ivaRate)
  return { neto, exento, iva, total: neto + exento + iva }
}

export function change(next: number | null, ref: number | null, thresholdPct: number): Comparison {
  if (next === null || ref === null || ref <= 0) {
    return { status: 'none', diff: null, pct: null, flagged: false }
  }
  const pct = roundHalfAway(((next - ref) / ref) * 100, 2)
  if (pct === 0) return { status: 'same', diff: 0, pct: 0, flagged: false }
  return {
    status: pct > 0 ? 'up' : 'down',
    diff: roundHalfAway(next - ref, 2),
    pct,
    flagged: Math.abs(pct) >= thresholdPct,
  }
}
