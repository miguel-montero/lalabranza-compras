import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Button, buttonVariants } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useCategories, useSuppliers } from '@/features/catalog/hooks'
import { ComparisonChip } from '@/features/receipts/ComparisonChip'
import { api } from '@/lib/api'
import { formatCLP, formatDate, formatUnitPrice } from '@/lib/format'
import type { HistoryRow } from '@/lib/types'

interface Filters {
  from: string
  to: string
  supplier_id: string
  category_id: string
  doc_type: string
  direction: string
  receipt_number: string
  include_undated: boolean
}
const EMPTY: Filters = { from: '', to: '', supplier_id: '', category_id: '', doc_type: '', direction: '', receipt_number: '', include_undated: false }

export function filtersToQuery(f: Filters, extra: Record<string, string> = {}): string {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(f)) {
    if (k === 'include_undated') {
      if (v) p.set(k, '1')
    } else if (v) p.set(k, String(v))
  }
  for (const [k, v] of Object.entries(extra)) p.set(k, v)
  return p.toString()
}

const sel = 'h-9 w-full rounded-md border border-input bg-card px-2 text-sm'

export default function HistoryPage() {
  const [f, setF] = useState<Filters>(EMPTY)
  const [applied, setApplied] = useState<Filters>(EMPTY)
  const [page, setPage] = useState(1)
  const suppliers = useSuppliers('', 'all')
  const categories = useCategories()
  const query = filtersToQuery(applied, { page: String(page) })
  const { data, isLoading } = useQuery({
    queryKey: ['history', query],
    queryFn: () => api<{ items: HistoryRow[]; total: number; page: number; page_size: number }>('GET', `/history?${query}`),
    placeholderData: (prev) => prev,
  })
  const set = <K extends keyof Filters>(k: K, v: Filters[K]) => setF((x) => ({ ...x, [k]: v }))
  const pages = data ? Math.max(1, Math.ceil(data.total / data.page_size)) : 1
  const label = (t: string, el: React.ReactNode) => (
    <label className="grid gap-1 text-sm text-muted-foreground">
      {t}
      {el}
    </label>
  )

  return (
    <div>
      <PageHeader title="Historial">
        <a className={buttonVariants({ variant: 'outline' })} href={`/api/history?${filtersToQuery(applied, { format: 'csv' })}`}>
          Exportar CSV
        </a>
      </PageHeader>
      <form
        className="mb-4 grid grid-cols-2 gap-3 rounded-lg border bg-card p-3 md:grid-cols-4"
        onSubmit={(e) => {
          e.preventDefault()
          setApplied(f)
          setPage(1)
        }}
      >
        {label('Desde', <Input type="date" value={f.from} onChange={(e) => set('from', e.target.value)} />)}
        {label('Hasta', <Input type="date" value={f.to} onChange={(e) => set('to', e.target.value)} />)}
        {label(
          'Proveedor',
          <select className={sel} value={f.supplier_id} onChange={(e) => set('supplier_id', e.target.value)}>
            <option value="">Todos</option>
            {suppliers.data?.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>,
        )}
        {label(
          'Categoría',
          <select className={sel} value={f.category_id} onChange={(e) => set('category_id', e.target.value)}>
            <option value="">Todas</option>
            {categories.data?.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </select>,
        )}
        {label(
          'Documento',
          <select className={sel} value={f.doc_type} onChange={(e) => set('doc_type', e.target.value)}>
            <option value="">Todos</option>
            <option value="boleta">Boleta</option>
            <option value="factura">Factura</option>
          </select>,
        )}
        {label(
          'Cambio',
          <select className={sel} value={f.direction} onChange={(e) => set('direction', e.target.value)}>
            <option value="">Todos</option>
            <option value="up">Subió</option>
            <option value="down">Bajó</option>
            <option value="flagged">Con alerta</option>
          </select>,
        )}
        {label('N° documento', <Input value={f.receipt_number} onChange={(e) => set('receipt_number', e.target.value)} />)}
        <label className="flex items-end gap-2 pb-2 text-sm">
          <input type="checkbox" checked={f.include_undated} onChange={(e) => set('include_undated', e.target.checked)} />
          Incluir sin fecha
        </label>
        <div className="col-span-2 flex gap-2 md:col-span-4">
          <Button type="submit">Filtrar</Button>
          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setF(EMPTY)
              setApplied(EMPTY)
              setPage(1)
            }}
          >
            Limpiar
          </Button>
        </div>
      </form>

      {isLoading ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Producto</TableHead>
                  <TableHead>Proveedor</TableHead>
                  <TableHead className="text-right">Pagado</TableHead>
                  <TableHead className="text-right">Costo real/u.</TableHead>
                  <TableHead>Cambio</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data?.items.map((r) => (
                  <TableRow key={`${r.receipt_id}-${r.line_order}`}>
                    <TableCell>
                      <Link className="underline-offset-2 hover:underline" to={`/compras/${r.receipt_id}`}>
                        {formatDate(r.purchase_date)}
                      </Link>
                    </TableCell>
                    <TableCell>
                      <Link className="underline-offset-2 hover:underline" to={`/productos/${r.product_id}`}>
                        {r.product_name}
                      </Link>
                    </TableCell>
                    <TableCell>{r.supplier_name}</TableCell>
                    <TableCell className="text-right">{formatCLP(r.amount_paid)}</TableCell>
                    <TableCell className="text-right">{r.normalized_price !== null ? formatUnitPrice(r.normalized_price, r.base_unit) : '—'}</TableCell>
                    <TableCell>
                      <ComparisonChip comparison={r.comparison} reference={r.comparison.ref} baseUnit={r.base_unit} />
                    </TableCell>
                  </TableRow>
                ))}
                {data?.items.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={6} className="text-center text-muted-foreground">
                      Sin resultados.
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
          <div className="mt-3 flex items-center justify-between text-sm text-muted-foreground">
            <span>{data?.total ?? 0} líneas</span>
            <span className="flex items-center gap-2">
              <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Anterior
              </Button>
              {page} / {pages}
              <Button type="button" variant="outline" size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                Siguiente
              </Button>
            </span>
          </div>
        </>
      )}
    </div>
  )
}
