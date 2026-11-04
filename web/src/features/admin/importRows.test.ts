import { describe, expect, it } from 'vitest'
import { activeFlags, commitErrorsByRow, effectiveAmount, excelSerialToISO, mapHeaders, normalizeUnit, parseCsv, unitOptions, type EditRow } from './importRows'

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
  acceptNoNumber: false, acceptDuplicate: false, swapReviewed: false, selected: false,
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
  it('duplicate receipt number is cleared by accepting it', () => {
    const r = { ...base, priceMode: 'total' as const, serverFlags: ['duplicate_receipt_number'] }
    expect(activeFlags(r)).toEqual(['duplicate_receipt_number'])
    expect(activeFlags({ ...r, acceptDuplicate: true })).toEqual([])
  })
})

describe('units and commit errors', () => {
  const ok = { ...base, priceMode: 'total' as const }
  it('flags a unit incompatible with the mapped product; unidad is always allowed', () => {
    expect(activeFlags({ ...ok, unit: 'g' })).toEqual([])
    expect(activeFlags({ ...ok, unit: 'unidad' })).toEqual([])
    expect(activeFlags({ ...ok, unit: 'mL' })).toEqual(['incompatible_unit'])
    expect(activeFlags({ ...ok, unit: 'kg', productBaseUnit: 'L' })).toEqual(['incompatible_unit'])
    expect(activeFlags({ ...ok, unit: 'kg', productBaseUnit: 'unidad' })).toEqual(['incompatible_unit'])
  })
  it('offers only units compatible with the mapped product (keeping the current value visible)', () => {
    expect(unitOptions({ ...ok, unit: 'kg' })).toEqual(['kg', 'g', 'unidad'])
    expect(unitOptions({ ...ok, unit: 'mL' })).toEqual(['kg', 'g', 'unidad', 'mL'])
    expect(unitOptions({ ...ok, productBaseUnit: 'L', unit: null })).toEqual(['L', 'mL', 'unidad'])
    expect(unitOptions({ ...ok, productId: null, productBaseUnit: null, unit: null })).toEqual(['kg', 'g', 'L', 'mL', 'unidad'])
  })
  it('maps a 422 commit error to the affected rows', () => {
    expect(commitErrorsByRow({ fields: { 'items.1.entry_unit': 'unidad incompatible con L' }, rows: [0, 2],
      row_fields: { '2': { entry_unit: 'unidad incompatible con L' } } })).toEqual({ 2: { entry_unit: 'unidad incompatible con L' } })
    // row_fields may arrive as a JSON list when keys are 0..n
    expect(commitErrorsByRow({ fields: {}, rows: [0], row_fields: [{ purchase_date: 'no puede ser futura' }] }))
      .toEqual({ 0: { purchase_date: 'no puede ser futura' } })
    // older servers: no row_fields -> every listed row gets the messages
    expect(commitErrorsByRow({ fields: { purchase_date: 'no puede ser futura' }, rows: [1, 3] }))
      .toEqual({ 1: { purchase_date: 'no puede ser futura' }, 3: { purchase_date: 'no puede ser futura' } })
    expect(commitErrorsByRow({})).toEqual({})
  })
})
