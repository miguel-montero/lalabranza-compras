import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import { PageHeader } from '@/components/PageHeader'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { errorMessage } from '@/lib/errors'
import { useProducts, useSaveProduct } from './hooks'
import { ProductFormDialog } from './ProductFormDialog'

export default function ProductsPage() {
  const [q, setQ] = useState('')
  const { data, isLoading } = useProducts(q)
  const save = useSaveProduct()
  const [editingId, setEditingId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  // Live copy from the list so aliases added in the dialog show up immediately.
  const editing = data?.find((p) => p.id === editingId) ?? null

  return (
    <div>
      <PageHeader title="Productos">
        <Button onClick={() => setCreating(true)}>Nuevo producto</Button>
      </PageHeader>
      <Input className="mb-3" placeholder="Buscar por nombre o alias" aria-label="Buscar producto" value={q} onChange={(e) => setQ(e.target.value)} />
      {isLoading ? (
        <p className="text-muted-foreground">Cargando…</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Producto</TableHead>
              <TableHead>Unidad</TableHead>
              <TableHead className="hidden sm:table-cell">Categoría</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead />
            </TableRow>
          </TableHeader>
          <TableBody>
            {data?.map((p) => (
              <TableRow key={p.id} className={p.active ? '' : 'opacity-60'}>
                <TableCell>
                  <Link to={`/productos/${p.id}`} className="font-medium underline-offset-2 hover:underline">
                    {p.name}
                  </Link>
                  {p.matched_alias && <div className="text-xs text-muted-foreground">alias: {p.matched_alias}</div>}
                </TableCell>
                <TableCell>{p.base_unit}</TableCell>
                <TableCell className="hidden text-muted-foreground sm:table-cell">{p.category_name}</TableCell>
                <TableCell>
                  <button
                    type="button"
                    aria-label={p.active ? `Desactivar ${p.name}` : `Activar ${p.name}`}
                    onClick={() => save.mutate({ id: p.id, active: !p.active }, { onError: (e) => toast.error(errorMessage(e)) })}
                  >
                    <Badge variant={p.active ? 'default' : 'secondary'}>{p.active ? 'Activo' : 'Inactivo'}</Badge>
                  </button>
                </TableCell>
                <TableCell className="text-right">
                  <Button variant="ghost" size="sm" onClick={() => setEditingId(p.id)}>
                    Editar
                  </Button>
                </TableCell>
              </TableRow>
            ))}
            {data?.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="text-center text-muted-foreground">
                  Sin resultados.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      )}
      <ProductFormDialog open={creating} onOpenChange={setCreating} />
      <ProductFormDialog open={editing !== null} onOpenChange={(o) => !o && setEditingId(null)} product={editing} />
    </div>
  )
}
