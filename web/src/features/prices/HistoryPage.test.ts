import { describe, expect, it } from 'vitest'
import { filtersToQuery } from './HistoryPage'

describe('filtersToQuery', () => {
  const base = { from: '', to: '', supplier_id: '', category_id: '', doc_type: '', direction: '', receipt_number: '', include_undated: false }
  it('omits empty filters and encodes the rest; CSV uses the same params', () => {
    expect(filtersToQuery({ ...base, supplier_id: '2', direction: 'flagged', include_undated: true })).toBe('supplier_id=2&direction=flagged&include_undated=1')
    expect(filtersToQuery({ ...base, from: '2026-01-01' }, { format: 'csv' })).toBe('from=2026-01-01&format=csv')
  })
})
