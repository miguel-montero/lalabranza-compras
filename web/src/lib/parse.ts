/** Quantities: "1,5", "1.5", " 0,75 " -> number. No thousands separators, no sign. */
export function parseDecimal(s: string): number | null {
  const t = s.trim()
  if (!/^(\d+([.,]\d+)?|[.,]\d+)$/.test(t)) return null
  return Number(t.replace(',', '.'))
}

/** Pesos: "1.300", "$1.300", "1300" -> 1300. Dots are thousands separators; no decimals. */
export function parseCLP(s: string): number | null {
  const t = s.replace(/\$/g, '').trim()
  if (!/^(\d+|\d{1,3}(\.\d{3})+)$/.test(t)) return null
  return Number(t.replace(/\./g, ''))
}
