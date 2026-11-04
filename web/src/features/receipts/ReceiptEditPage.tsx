import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import type { ReceiptView } from '@/lib/types'
import ReceiptForm from './ReceiptForm'
import { draftFromReceipt } from './useReceiptDraft'

/** Edit mode: ReceiptForm with the receipt as initial draft (`kt:draft:<id>`), saving with PUT. */
export default function ReceiptEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { data: r, isLoading, error } = useQuery({
    queryKey: ['receipt', id],
    queryFn: () => api<ReceiptView>('GET', `/receipts/${id}`),
    staleTime: 0,
    refetchOnMount: 'always',
  })
  if (isLoading) return <p className="text-muted-foreground">Cargando…</p>
  if (error || !r) return <p className="text-destructive">No se encontró la compra.</p>
  if (r.status === 'void') return <p className="text-destructive">La compra está anulada y no se puede editar.</p>
  return (
    <ReceiptForm
      key={r.id}
      storageKey={`kt:draft:${r.id}`}
      initial={draftFromReceipt(r)}
      editingId={r.id}
      ivaRate={r.iva_rate}
      submit={(payload) => api<ReceiptView>('PUT', `/receipts/${r.id}`, payload)}
      onSaved={async (saved) => {
        await qc.invalidateQueries()
        navigate(`/compras/${saved.id}`)
      }}
    />
  )
}
