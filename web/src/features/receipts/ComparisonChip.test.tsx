import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { ComparisonChip } from './ComparisonChip'

afterEach(cleanup)

describe('ComparisonChip', () => {
  it('clicking the chip reveals the reference supplier and date', async () => {
    render(
      <ComparisonChip
        comparison={{ status: 'up', pct: 15.38, flagged: true }}
        reference={{ normalized_price: 1300, supplier_name: 'Vega Central', purchase_date: '2026-02-10' }}
        baseUnit="kg"
      />,
    )
    expect(screen.queryByText(/Vega Central/)).toBeNull()
    await userEvent.click(screen.getByRole('button', { name: /\+15,38%/ }))
    expect(await screen.findByText(/Vega Central · 10-02-2026/)).toBeTruthy()
    expect(screen.getByText('$1.300/kg')).toBeTruthy()
  })

  it('shows plain "Sin comparación" without a reference', () => {
    render(<ComparisonChip comparison={{ status: 'none', pct: null, flagged: false }} baseUnit="kg" />)
    expect(screen.getByText('Sin comparación')).toBeTruthy()
  })
})
