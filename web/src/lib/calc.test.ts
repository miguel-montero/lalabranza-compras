import { describe, expect, it } from 'vitest'
import fixtures from '../../../shared/calc-fixtures.json'
import {
  baseQuantity,
  change,
  enteredAmount,
  lineCosts,
  normalizedPrice,
  receiptTotals,
  roundHalfAway,
  spread,
  toBase,
} from './calc'

/* eslint-disable @typescript-eslint/no-explicit-any */
type Case = { in: any[]; out: any }
const F = fixtures as unknown as Record<string, Case[]>

function expectClose(actual: unknown, expected: unknown) {
  if (expected !== null && typeof expected === 'object' && !Array.isArray(expected)) {
    expect(Object.keys(actual as object)).toEqual(Object.keys(expected))
    for (const [k, v] of Object.entries(expected)) expectClose((actual as any)[k], v)
  } else if (typeof expected === 'number') {
    expect(actual).not.toBeNull()
    expect(actual as number).toBeCloseTo(expected, 4)
  } else {
    expect(actual).toEqual(expected)
  }
}

describe('roundHalfAway', () => {
  it.each([
    [1.005, 2, 1.01],
    [-0.5, 0, -1],
    [0.5, 0, 1],
    [2.5, 0, 3],
    [-2.5, 0, -3],
    [59.5, 0, 60],
    [1.955, 2, 1.96],
    [-1.005, 2, -1.01],
    [666.66666, 4, 666.6667],
    [0.4, 0, 0],
  ])('round(%s, %s) = %s', (x, d, e) => {
    expect(roundHalfAway(x, d)).toBe(e)
  })
  it('never returns -0', () => {
    expect(Object.is(roundHalfAway(-0.2), 0)).toBe(true)
  })
  it('matches 50 * 1.19 case', () => {
    expect(roundHalfAway(50 * (1 + 0.19))).toBe(60)
  })
})

describe('fixtures', () => {
  it.each(F.toBase.map((c) => [JSON.stringify(c.in), c] as const))('toBase %s', (_n, c) => {
    expectClose((toBase as any)(...c.in), c.out)
  })
  it.each(F.baseQuantity.map((c) => [JSON.stringify(c.in), c] as const))('baseQuantity %s', (_n, c) => {
    expectClose((baseQuantity as any)(...c.in), c.out)
  })
  it.each(F.spread.map((c) => [JSON.stringify(c.in), c] as const))('spread %s', (_n, c) => {
    const r = (spread as any)(...c.in)
    expect(r).toEqual(c.out)
    expect(r.some((v: number) => Object.is(v, -0))).toBe(false)
  })
  it.each(F.costs.map((c) => [JSON.stringify(c.in), c] as const))('lineCosts %s', (_n, c) => {
    const r = (lineCosts as any)(...c.in)
    expect(r).toEqual({ realCost: c.out.real_cost, amountPaid: c.out.amount_paid })
  })
  it.each(F.normalizedPrice.map((c) => [JSON.stringify(c.in), c] as const))('normalizedPrice %s', (_n, c) => {
    expectClose((normalizedPrice as any)(...c.in), c.out)
  })
  it.each(F.receiptTotals.map((c) => [JSON.stringify(c.in), c] as const))('receiptTotals %s', (_n, c) => {
    const [lines, ...rest] = c.in
    const mapped = lines.map((l: any) => ({ realCost: l.real_cost, ivaExempt: l.iva_exempt }))
    expect((receiptTotals as any)(mapped, ...rest)).toEqual(c.out)
  })
  it.each(F.change.map((c) => [JSON.stringify(c.in), c] as const))('change %s', (_n, c) => {
    expectClose((change as any)(...c.in), c.out)
  })
  it.each(F.enteredAmount.map((c) => [JSON.stringify(c.in), c] as const))('enteredAmount %s', (_n, c) => {
    expect((enteredAmount as any)(...c.in)).toBe(c.out)
  })
})

describe('spread edge cases', () => {
  it('throws on zero sum with non-zero adjustment', () => {
    expect(() => spread([0, 0], 10)).toThrow()
  })
  it('empty input', () => {
    expect(spread([], 5)).toEqual([])
  })
  it('zero sum zero adjustment', () => {
    expect(spread([0, 0], 0)).toEqual([0, 0])
  })
})
