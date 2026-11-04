import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { buttonVariants } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { formatCLP, formatDate } from '@/lib/format'

interface Row {
  id: number
  purchase_date: string | null
  supplier_name: string
  doc_type: string
  receipt_number: string | null
  total: number
  status: string
  flagged_count: number
}

export default function ReceiptsListPage() {
  const { data, isLoading } = useQuery({ queryKey: ['receipts', 1], queryFn: () => api<{ items: Row[]; total: number }>('GET', '/receipts') })
  return (
    <div>
      <PageHeader title="Compras">
        <Link to="/compras/nueva" className={buttonVariants()}>
          Nueva compra
        </Link>
      </PageHeader>
      {isLoading ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Fecha</TableHead>
              <TableHead>Proveedor</TableHead>
              <TableHead className="hidden sm:table-cell">N°</TableHead>
              <TableHead className="text-right">Total</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.items.map((r) => (
              <TableRow key={r.id}>
                <TableCell>
                  <Link className="underline-offset-2 hover:underline" to={`/compras/${r.id}`}>
                    {formatDate(r.purchase_date)}
                  </Link>
                </TableCell>
                <TableCell>
                  {r.supplier_name} {r.flagged_count > 0 && <Badge variant="destructive">{r.flagged_count} alerta{r.flagged_count > 1 ? 's' : ''}</Badge>}
                </TableCell>
                <TableCell className="hidden sm:table-cell">{r.receipt_number}</TableCell>
                <TableCell className="text-right">{formatCLP(r.total)}</TableCell>
              </TableRow>
            ))}
            {data?.items.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="text-center text-muted-foreground">
                  Aún no hay compras.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      )}
    </div>
  )
}
