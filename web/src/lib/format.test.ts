import { describe, expect, it } from 'vitest'
import { formatCLP, formatDate, formatPct, formatUnitPrice } from './format'

describe('format', () => {
  it('formatCLP', () => {
    expect(formatCLP(1300)).toBe('$1.300')
    expect(formatCLP(0)).toBe('$0')
    expect(formatCLP(1234567)).toBe('$1.234.567')
    expect(formatCLP(-400)).toBe('-$400')
    expect(formatCLP(999)).toBe('$999')
  })
  it('formatUnitPrice', () => {
    expect(formatUnitPrice(1300, 'kg')).toBe('$1.300/kg')
    expect(formatUnitPrice(666.6667, 'kg')).toBe('$667/kg')
    expect(formatUnitPrice(2.5, 'unidad')).toBe('$3/unidad')
    expect(formatUnitPrice(1500, 'L')).toBe('$1.500/L')
  })
  it('formatPct', () => {
    expect(formatPct(15.38)).toBe('+15,38%')
    expect(formatPct(-10)).toBe('-10,00%')
    expect(formatPct(0)).toBe('0,00%')
    expect(formatPct(1234.5)).toBe('+1.234,50%')
  })
  it('formatDate', () => {
    expect(formatDate('2026-02-10')).toBe('10-02-2026')
    expect(formatDate(null)).toBe('sin fecha')
    expect(formatDate(undefined)).toBe('sin fecha')
  })
})
