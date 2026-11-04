import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart'
import { Input } from '@/components/ui/input'
import { ComparisonChip } from '@/features/receipts/ComparisonChip'
import { api } from '@/lib/api'
import { formatCLP, formatDate, formatUnitPrice } from '@/lib/format'
import type { DashboardData } from '@/lib/types'

const config = { total_paid: { label: 'Pagado', color: 'var(--chart-1)' } } satisfies ChartConfig

export default function DashboardPage() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const qs = new URLSearchParams()
  if (from) qs.set('from', from)
  if (to) qs.set('to', to)
  const { data, isLoading } = useQuery({
    queryKey: ['dashboard', from, to],
    queryFn: () => api<DashboardData>('GET', `/dashboard?${qs}`),
    placeholderData: (prev) => prev,
  })
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-4">
      <PageHeader title="Panel" />
      <div className="grid grid-cols-2 gap-3 rounded-lg border bg-card p-3">
        <label className="grid gap-1 text-sm text-muted-foreground">
          Desde
          <Input type="date" value={from || data?.range.from || ''} onChange={(e) => setFrom(e.target.value)} />
        </label>
        <label className="grid gap-1 text-sm text-muted-foreground">
          Hasta
          <Input type="date" value={to || data?.range.to || ''} onChange={(e) => setTo(e.target.value)} />
        </label>
      </div>
      {isLoading || !data ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-normal text-muted-foreground">Gasto pagado</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-semibold" data-testid="total-paid">
                {formatCLP(data.spend.total_paid)}
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-normal text-muted-foreground">Costo real</CardTitle>
              </CardHeader>
              <CardContent className="text-2xl font-semibold" data-testid="total-real">
                {formatCLP(data.spend.total_real)}
              </CardContent>
            </Card>
          </div>

          <section>
            <h2 className="mb-2 font-heading text-lg">Cambios de precio con alerta</h2>
            {data.flagged.length === 0 ? (
              <p className="text-sm text-muted-foreground">Sin alertas en este período.</p>
            ) : (
              <ul className="divide-y rounded-md border bg-card text-sm">
                {data.flagged.map((r) => (
                  <li key={`${r.receipt_id}-${r.product_id}-${r.line_order}`} className="flex items-center justify-between gap-2 px-3 py-2">
                    <span>
                      <Link className="font-medium underline-offset-2 hover:underline" to={`/productos/${r.product_id}`}>
                        {r.product_name}
                      </Link>
                      <span className="block text-xs text-muted-foreground">
                        {r.supplier_name} · {formatDate(r.purchase_date)} · {r.normalized_price !== null && formatUnitPrice(r.normalized_price, r.base_unit)}
                      </span>
                    </span>
                    <ComparisonChip comparison={r.comparison} reference={r.comparison.ref} baseUnit={r.base_unit} />
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section>
            <h2 className="mb-2 font-heading text-lg">Gasto por categoría</h2>
            {data.spend.by_category.length === 0 ? (
              <p className="text-sm text-muted-foreground">Sin compras en este período.</p>
            ) : (
              <ChartContainer config={config} className="w-full" style={{ height: 48 + data.spend.by_category.length * 36 }}>
                <BarChart data={data.spend.by_category} layout="vertical" margin={{ left: 8, right: 16 }}>
                  <CartesianGrid horizontal={false} />
                  <YAxis dataKey="name" type="category" width={96} tickLine={false} axisLine={false} />
                  <XAxis type="number" tickFormatter={(v: number) => formatCLP(v)} />
                  <ChartTooltip content={<ChartTooltipContent formatter={(v) => <strong>{formatCLP(Number(v))}</strong>} />} />
                  <Bar dataKey="total_paid" fill="var(--color-total_paid)" radius={3} isAnimationActive={false} />
                </BarChart>
              </ChartContainer>
            )}
          </section>

          <section>
            <h2 className="mb-2 font-heading text-lg">Compras recientes</h2>
            <ul className="divide-y rounded-md border bg-card text-sm">
              {data.recent_receipts.map((r) => (
                <li key={r.id}>
                  <Link to={`/compras/${r.id}`} className="flex items-center justify-between gap-2 px-3 py-2 hover:bg-accent">
                    <span>
                      {r.supplier_name}
                      <span className="block text-xs text-muted-foreground">
                        {formatDate(r.purchase_date)} · {r.doc_type === 'factura' ? 'Factura' : 'Boleta'}
                        {r.receipt_number ? ` ${r.receipt_number}` : ''}
                      </span>
                    </span>
                    <span className="flex items-center gap-2">
                      {r.flagged_count > 0 && <Badge variant="destructive">{r.flagged_count}</Badge>}
                      {formatCLP(r.total)}
                    </span>
                  </Link>
                </li>
              ))}
              {data.recent_receipts.length === 0 && <li className="px-3 py-2 text-muted-foreground">Aún no hay compras.</li>}
            </ul>
          </section>
        </>
      )}
    </div>
  )
}
