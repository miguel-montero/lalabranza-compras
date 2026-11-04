import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatCLP, formatDate, formatUnitPrice } from '@/lib/format'
import type { ReceiptView } from '@/lib/types'
import { ComparisonChip } from './ComparisonChip'

/** Minimal read-only view; editing and voiding arrive in Task 9. */
export default function ReceiptDetailPage() {
  const { id } = useParams()
  const { data: r, isLoading, error } = useQuery({
    queryKey: ['receipt', id],
    queryFn: () => api<ReceiptView>('GET', `/receipts/${id}`),
  })
  if (isLoading) return <p className="text-muted-foreground">Cargando…</p>
  if (error || !r) return <p className="text-destructive">No se encontró la compra.</p>
  return (
    <div>
      <PageHeader title={`Compra ${r.receipt_number ? 'N° ' + r.receipt_number : '#' + r.id}`}>
        {r.status === 'void' && <Badge variant="destructive">Anulada</Badge>}
      </PageHeader>
      <p className="mb-4 text-muted-foreground">
        {r.supplier.name} · {formatDate(r.purchase_date)} · {r.doc_type === 'factura' ? 'Factura' : 'Boleta'}
      </p>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Producto</TableHead>
            <TableHead className="text-right">Cantidad</TableHead>
            <TableHead className="text-right">Pagado</TableHead>
            <TableHead className="text-right">Costo real/u.</TableHead>
            <TableHead>Cambio</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {r.items.map((i) => (
            <TableRow key={i.id}>
              <TableCell>
                <Link className="underline-offset-2 hover:underline" to={`/productos/${i.product_id}`}>
                  {i.product.name}
                </Link>
              </TableCell>
              <TableCell className="text-right">
                {String(i.quantity).replace('.', ',')} {i.entry_unit}
              </TableCell>
              <TableCell className="text-right">{formatCLP(i.amount_paid)}</TableCell>
              <TableCell className="text-right">{i.normalized_price !== null ? formatUnitPrice(i.normalized_price, i.product.base_unit) : '—'}</TableCell>
              <TableCell>{i.comparison && <ComparisonChip comparison={i.comparison} reference={i.comparison.ref} baseUnit={i.product.base_unit} />}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
      <dl className="mt-4 ml-auto grid max-w-xs grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-sm">
        {r.adjustment_amount !== 0 && (
          <>
            <dt className="text-muted-foreground">Descuento / ajuste</dt>
            <dd className="text-right">{formatCLP(r.adjustment_amount)}</dd>
          </>
        )}
        {r.delivery_amount > 0 && (
          <>
            <dt className="text-muted-foreground">Despacho</dt>
            <dd className="text-right">{formatCLP(r.delivery_amount)}</dd>
          </>
        )}
        {r.doc_type === 'factura' && (
          <>
            <dt className="text-muted-foreground">Neto</dt>
            <dd className="text-right">{formatCLP(r.totals.neto)}</dd>
            <dt className="text-muted-foreground">IVA</dt>
            <dd className="text-right">{formatCLP(r.totals.iva)}</dd>
          </>
        )}
        <dt className="font-semibold">Total</dt>
        <dd className="text-right text-lg font-semibold">{formatCLP(r.totals.total)}</dd>
      </dl>
      {r.printed_total_mismatch && <p className="mt-3 text-sm">El total no coincide con el total impreso ({formatCLP(r.printed_total ?? 0)}).</p>}
    </div>
  )
}
