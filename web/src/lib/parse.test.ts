import { describe, expect, it } from 'vitest'
import { parseCLP, parseDecimal } from './parse'

describe('parseDecimal', () => {
  it.each([
    ['1,5', 1.5],
    ['1.5', 1.5],
    [' 0,75 ', 0.75],
    ['2', 2],
    ['0', 0],
    ['.5', 0.5],
  ])('%j -> %s', (s, e) => expect(parseDecimal(s)).toBe(e))
  it.each(['abc', '', '  ', '1,2,3', '1.2.3', '1,2.3', '-1', '1e3', '1,', ','])('%j -> null', (s) =>
    expect(parseDecimal(s)).toBeNull(),
  )
})

describe('parseCLP', () => {
  it.each([
    ['1.300', 1300],
    ['$1.300', 1300],
    ['1300', 1300],
    [' $ 1.234.567 ', 1234567],
    ['0', 0],
  ])('%j -> %s', (s, e) => expect(parseCLP(s)).toBe(e))
  it.each(['1,5', '', 'abc', '1.30', '$', '1..300', '-400', '1.5'])('%j -> null', (s) =>
    expect(parseCLP(s)).toBeNull(),
  )
})
