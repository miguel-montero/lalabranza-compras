import { useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { errorMessage } from '@/lib/errors'
import { formatCLP, formatDate, formatUnitPrice } from '@/lib/format'
import type { ReceiptView } from '@/lib/types'
import { ComparisonChip } from './ComparisonChip'

/** "2026-02-10 14:03:00" -> "10-02-2026 14:03" (stored server time, no zone conversion). */
function formatDateTime(s: string): string {
  return `${formatDate(s.slice(0, 10))} ${s.slice(11, 16)}`.trim()
}

const ACTION: Record<string, string> = { create: 'Creada', update: 'Editada', void: 'Anulada' }

export default function ReceiptDetailPage() {
  const { id } = useParams()
  const qc = useQueryClient()
  const [voiding, setVoiding] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const { data: r, isLoading, error } = useQuery({
    queryKey: ['receipt', id],
    staleTime: 0,
    queryFn: () => api<ReceiptView>('GET', `/receipts/${id}`),
  })
  if (isLoading) return <p className="text-muted-foreground">Cargando…</p>
  if (error || !r) return <p className="text-destructive">No se encontró la compra.</p>
  async function doVoid() {
    setBusy(true)
    try {
      await api('POST', `/receipts/${id}/void`, reason.trim() ? { reason: reason.trim() } : {})
      await qc.invalidateQueries()
      toast.success('Compra anulada')
      setVoiding(false)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }
  return (
    <div>
      <PageHeader title={`Compra ${r.receipt_number ? 'N° ' + r.receipt_number : '#' + r.id}`}>
        {r.status === 'void' ? (
          <Badge variant="destructive">Anulada</Badge>
        ) : (
          <div className="flex gap-2">
            <Link to={`/compras/${r.id}/editar`} className={buttonVariants({ variant: 'outline' })}>
              Editar
            </Link>
            <Button type="button" variant="destructive" onClick={() => setVoiding(true)}>
              Anular
            </Button>
          </div>
        )}
      </PageHeader>
      <p className="mb-4 text-muted-foreground">
        {r.supplier.name} · {formatDate(r.purchase_date)} · {r.doc_type === 'factura' ? 'Factura' : 'Boleta'}
        {r.items.some((i) => i.iva_exempt) && ' · incluye exento / sin IVA'}
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
      {r.notes && <p className="mt-3 text-sm text-muted-foreground">{r.notes}</p>}

      {r.audit && r.audit.length > 0 && (
        <details className="mt-6 rounded-md border bg-card p-3 text-sm">
          <summary className="cursor-pointer font-medium">Historial de cambios</summary>
          <ul className="mt-2 grid gap-2">
            {r.audit.map((a, i) => (
              <li key={i}>
                <strong>{ACTION[a.action] ?? a.action}</strong> · {formatDateTime(a.created_at)}
                {a.action === 'update' && a.before && a.after && (
                  <span className="text-muted-foreground">
                    {' '}
                    · total {formatCLP(a.before.totals.total)} → {formatCLP(a.after.totals.total)}
                  </span>
                )}
                {a.reason && <span className="block text-muted-foreground">Motivo: {a.reason}</span>}
              </li>
            ))}
          </ul>
        </details>
      )}

      <Dialog open={voiding} onOpenChange={setVoiding}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Anular compra</DialogTitle>
            <DialogDescription>La compra dejará de contar en precios y comparaciones. Queda registrada en el historial.</DialogDescription>
          </DialogHeader>
          <label className="grid gap-1 text-sm text-muted-foreground">
            Motivo (opcional)
            <Input value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
          </label>
          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => setVoiding(false)}>
              Cancelar
            </Button>
            <Button type="button" variant="destructive" disabled={busy} onClick={() => void doVoid()}>
              {busy ? 'Anulando…' : 'Anular compra'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
