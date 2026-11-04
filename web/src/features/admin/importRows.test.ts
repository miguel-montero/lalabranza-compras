import { describe, expect, it } from 'vitest'
import { activeFlags, effectiveAmount, excelSerialToISO, mapHeaders, normalizeUnit, parseCsv, type EditRow } from './importRows'

describe('importRows', () => {
  it('maps misspelled Pruducto header', () => {
    const rows = mapHeaders([
      {
        Fecha: '2026-01-10',
        'Lugar / Proveedor': 'Vega Central',
        Pruducto: 'Tomate',
        Categoría: 'Verduras',
        Cantidad: 3,
        Unidad: 'kg',
        'Precio  Unitario': 920,
        'Total de compra': 2760,
        'Número de boleta': 77,
      },
      { producto: 'Ajo' },
    ])
    expect(rows[0]).toEqual({
      date: '2026-01-10',
      supplier: 'Vega Central',
      product: 'Tomate',
      category: 'Verduras',
      quantity: 3,
      unit: 'kg',
      unit_price: 920,
      total: 2760,
      receipt_number: '77',
    })
    expect(rows[1].product).toBe('Ajo')
    expect(rows[1].total).toBeNull()
  })

  it('normalizes Gr/gr/Unidad', () => {
    expect(normalizeUnit('Gr')).toBe('g')
    expect(normalizeUnit('gr')).toBe('g')
    expect(normalizeUnit(' GRS ')).toBe('g')
    expect(normalizeUnit('Kilos')).toBe('kg')
    expect(normalizeUnit('Lt')).toBe('L')
    expect(normalizeUnit('CC')).toBe('mL')
    expect(normalizeUnit('Unidad')).toBe('unidad')
    expect(normalizeUnit('und')).toBe('unidad')
    expect(normalizeUnit('caja')).toBeNull()
    expect(normalizeUnit(null)).toBeNull()
  })

  it('converts excel serial 46000 to 2025-12-09', () => {
    expect(excelSerialToISO(46000)).toBe('2025-12-09')
    expect(mapHeaders([{ Fecha: 46000 }])[0].date).toBe('2025-12-09')
  })

  it('accepts dd-mm-yyyy and Date cells', () => {
    expect(mapHeaders([{ Fecha: '09/12/2025' }])[0].date).toBe('2025-12-09')
    expect(mapHeaders([{ Fecha: new Date(Date.UTC(2025, 11, 9)) }])[0].date).toBe('2025-12-09')
  })

  it('parses csv with ; or , and quotes', () => {
    const semi = parseCsv('Fecha;Pruducto;Total de compra\n10-01-2026;"Aceite; oliva";"1.500"\n')
    expect(semi).toEqual([{ Fecha: '10-01-2026', Pruducto: 'Aceite; oliva', 'Total de compra': '1.500' }])
    const comma = parseCsv('a,b\r\n"x ""q"" y",2\r\n\r\n')
    expect(comma).toEqual([{ a: 'x "q" y', b: '2' }])
    const r = mapHeaders(semi)
    expect(r[0].total).toBe(1500)
    expect(r[0].date).toBe('2026-01-10')
  })
})

const base: EditRow = {
  index: 0, date: '2026-01-10', supplier: 'A', product: 'B', category: null, quantity: 3, unit: 'kg',
  unit_price: 920, total: null, receipt_number: '1', serverFlags: ['ambiguous_price'],
  supplierId: 1, supplierName: 'A', productId: 2, productName: 'B', productBaseUnit: 'kg', docType: 'boleta', priceMode: null, dateUnknown: false,
  acceptNoNumber: false, swapReviewed: false, selected: false,
}

describe('flag resolution', () => {
  it('ambiguous price resolved by choosing a mode, and amount follows', () => {
    expect(activeFlags(base)).toEqual(['ambiguous_price'])
    const unit = { ...base, priceMode: 'unit' as const }
    expect(activeFlags(unit)).toEqual([])
    expect(effectiveAmount(unit)).toBe(2760)
    expect(effectiveAmount({ ...base, priceMode: 'total' })).toBe(920)
  })
  it('missing date resolved by dateUnknown, number by acceptance, swap by review', () => {
    const r = { ...base, priceMode: 'total' as const, date: null, receipt_number: null, serverFlags: ['possible_swap'] }
    expect(activeFlags(r).sort()).toEqual(['missing_date', 'missing_receipt_number', 'possible_swap'])
    expect(activeFlags({ ...r, dateUnknown: true, acceptNoNumber: true, swapReviewed: true })).toEqual([])
  })
})
