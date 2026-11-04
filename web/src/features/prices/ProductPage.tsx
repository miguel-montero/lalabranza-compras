import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts'
import { PageHeader } from '@/components/PageHeader'
import { ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart'
import { api } from '@/lib/api'
import { formatCLP, formatDate, formatUnitPrice } from '@/lib/format'
import type { ProductDetail } from '@/lib/types'
import { StatsBlock } from './StatsBlock'

const COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)']

export default function ProductPage() {
  const { id } = useParams()
  const { data, isLoading, error } = useQuery({ queryKey: ['product', id], queryFn: () => api<ProductDetail>('GET', `/products/${id}`) })

  const chart = useMemo(() => {
    const suppliers = [...new Set((data?.series ?? []).filter((s) => s.purchase_date).map((s) => s.supplier_name))]
    const config: ChartConfig = {}
    suppliers.forEach((s, i) => (config[`s${i}`] = { label: s, color: COLORS[i % COLORS.length] }))
    const byDate = new Map<string, Record<string, number | string>>()
    for (const pt of data?.series ?? []) {
      if (!pt.purchase_date) continue
      const row = byDate.get(pt.purchase_date) ?? { date: pt.purchase_date }
      row[`s${suppliers.indexOf(pt.supplier_name)}`] = pt.normalized_price
      byDate.set(pt.purchase_date, row)
    }
    const rows = [...byDate.values()].sort((a, b) => String(a.date).localeCompare(String(b.date)))
    return { suppliers, config, rows }
  }, [data])

  if (isLoading) return <p className="text-muted-foreground">Cargando…</p>
  if (error || !data) return <p className="text-destructive">No se encontró el producto.</p>
  const unit = data.product.base_unit
  const undated = data.series.filter((s) => !s.purchase_date)
  return (
    <div className="grid grid-cols-[minmax(0,1fr)] gap-5">
      <PageHeader title={data.product.name} />
      <p className="-mt-3 text-sm text-muted-foreground">
        {data.product.category_name ?? 'Sin categoría'} · {data.stats.purchase_count} compra{data.stats.purchase_count === 1 ? '' : 's'}
        {data.aliases.length > 0 && ` · También: ${data.aliases.map((a) => a.alias).join(', ')}`}
      </p>
      <StatsBlock stats={data.stats} bySupplier={data.by_supplier} baseUnit={unit} />

      {chart.rows.length > 0 && (
        <section aria-label="Costo real por unidad en el tiempo">
          <h2 className="mb-2 font-heading text-lg">Costo real por {unit}</h2>
          <ChartContainer config={chart.config} className="h-64 w-full">
            <LineChart data={chart.rows} margin={{ left: 4, right: 12, top: 8 }}>
              <CartesianGrid vertical={false} />
              <XAxis dataKey="date" tickFormatter={(d: string) => formatDate(d).slice(0, 5)} tickMargin={8} />
              <YAxis width={56} tickFormatter={(v: number) => formatCLP(v)} domain={['auto', 'auto']} />
              <ChartTooltip
                content={
                  <ChartTooltipContent
                    labelFormatter={(_, p) => formatDate(String(p?.[0]?.payload?.date ?? ''))}
                    formatter={(v, name) => (
                      <span>
                        {chart.config[String(name)]?.label}: <strong>{formatUnitPrice(Number(v), unit)}</strong>
                      </span>
                    )}
                  />
                }
              />
              <ChartLegend content={<ChartLegendContent />} />
              {chart.suppliers.map((_, i) => (
                <Line key={i} dataKey={`s${i}`} type="monotone" stroke={`var(--color-s${i})`} strokeWidth={2} dot connectNulls isAnimationActive={false} />
              ))}
            </LineChart>
          </ChartContainer>
        </section>
      )}

      {undated.length > 0 && (
        <section>
          <h2 className="mb-2 font-heading text-lg">sin fecha</h2>
          <ul className="divide-y rounded-md border bg-card text-sm">
            {undated.map((u, i) => (
              <li key={i} className="flex justify-between px-3 py-2">
                <span>{u.supplier_name}</span>
                <span>{formatUnitPrice(u.normalized_price, unit)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}
